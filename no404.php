<?php
/**
 * no404 – Auto 404 Redirect for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    no404 <https://www.no404.tr>
 * @copyright 2026 no404
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use PrestaShop\Module\No404\Front\ClientFactory;
use PrestaShop\Module\No404\Front\ErrorReporter;
use PrestaShop\Module\No404\Front\NativeResponseEmitter;
use PrestaShop\Module\No404\Front\Redirector;
use PrestaShop\Module\No404\Front\RequestContext;
use PrestaShop\Module\No404\Platform;
use PrestaShop\Module\No404\Settings\Keys;
use PrestaShop\Module\No404\Settings\SettingsReader;

class No404 extends Module
{
    /** @var bool the 404 handler runs at most once per request, whichever hook reaches it first */
    private static $handled = false;

    public function __construct()
    {
        $this->name = 'no404';
        $this->tab = 'seo';
        // Literals on purpose: the Addons validator reads them without running the
        // file. This tree is the PrestaShop 9 line; bin/build.php checks both
        // against platform/ps9/target.json and stamps the PrestaShop 8 values
        // (platform/ps8/target.json) into the PrestaShop 8 zip.
        $this->version = '2.0.0';
        $this->author = 'no404';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        // Hidden tab: no menu entry, but it carries the access rights the
        // settings routes check (_legacy_controller: AdminNo404). Installed and
        // removed by PrestaShop together with the module.
        $this->tabs = [
            [
                'class_name' => 'AdminNo404',
                'route_name' => 'no404_settings',
                'parent_class_name' => 'AdminParentModulesSf',
                'name' => 'no404',
                'visible' => false,
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('no404 – Auto 404 Redirect', [], 'Modules.No404.Admin');
        $this->description = $this->trans(
            'Turns the 404s of your store into real server-side 301/302 redirects to the closest live URL, using the no404 service.',
            [],
            'Modules.No404.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstall no404? Its settings, including the API key, and its local cache will be deleted.',
            [],
            'Modules.No404.Admin'
        );
    }

    /**
     * @return bool
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function install()
    {
        // actionNotFound is registered even where the core does not fire it yet
        // (before PrestaShop 9.1.5): after a core upgrade it simply starts
        // working, without reinstalling the module.
        return parent::install()
            && $this->registerHook(['actionNotFound', 'actionOutputHTMLBefore'])
            && $this->installConfiguration();
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        // Where each shop keeps its cache must be read before the settings go.
        $cacheDirectories = $this->cacheDirectories();

        if (!parent::uninstall()) {
            return false;
        }

        $ok = true;
        foreach (Keys::all() as $key) {
            // deleteByName removes the key in every shop and group context.
            $ok = Configuration::deleteByName($key) && $ok;
        }

        // Only the module's own sub-directory — never a merchant's base directory.
        foreach ($cacheDirectories as $directory) {
            if (is_dir($directory)) {
                Tools::deleteDirectory($directory);
            }
        }

        return $ok;
    }

    /**
     * "Configure" in the Module Manager opens the Symfony settings page.
     *
     * @return void
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->get('router')->generate('no404_settings'));
    }

    /**
     * PrestaShop decided this request is a 404 — ask no404 for the closest live
     * URL and redirect there. Fires for unmatched routes, deleted products and
     * deleted categories, before any output — on PrestaShop 9.1.5 and newer
     * only (see Platform); earlier releases go through the catch-all hook.
     *
     * @param array<string, mixed> $params unused (the hook has no parameters)
     *
     * @return void
     */
    public function hookActionNotFound(array $params)
    {
        $this->handle404(false);
    }

    /**
     * Catch-all path. Before PrestaShop 9.1.5 (the whole 8.x line included) the
     * core never fires actionNotFound, so every 404 comes through here. On newer
     * releases only the 404s rendered without that hook do (a disabled product
     * or category set to "404 Not Found"), and only in catch-all mode. This hook
     * runs right before the HTML is echoed, while headers can still be sent.
     *
     * Normal pages pay one function call: nothing else runs unless the response
     * is already a 404, and the settings are read only then.
     *
     * @param array<string, mixed> $params ['html' => &$html] (unused)
     *
     * @return void
     */
    public function hookActionOutputHTMLBefore(array $params)
    {
        if (self::$handled || 404 !== http_response_code()) {
            return;
        }

        $this->handle404(true);
    }

    /**
     * FAIL-OPEN: any failure — including one of our own — leaves the 404 page to
     * render normally.
     *
     * @param bool $catchAll called from the catch-all hook
     *
     * @return void
     */
    private function handle404($catchAll)
    {
        if (self::$handled) {
            return;
        }
        self::$handled = true;

        try {
            $settings = SettingsReader::fromConfiguration();
            if (!$settings->isOperable() || !function_exists('curl_init')) {
                return;
            }
            // Where the core fires actionNotFound this path is opt-in (catch-all
            // mode). Before PrestaShop 9.1.5 it is the only way a 404 gets here.
            if ($catchAll && !$settings->catchAll() && Platform::firesNotFoundHook(_PS_VERSION_)) {
                return;
            }

            $factory = new ClientFactory($this->context, $settings, $this->version);
            $cookie = $this->context->cookie;
            $emitter = new NativeResponseEmitter(static function () use ($cookie) {
                // Same as PageNotFoundController: a redirect must not set cookies.
                if ($cookie instanceof Cookie) {
                    $cookie->disallowWriting();
                }
            });

            $request = RequestContext::fromServer($_SERVER, Tools::getIsset('ajax'));

            // Lets another module change the visitor data sent to no404 (e.g. read
            // the IP from a proxy header this module does not know) or remove it
            // (set it to []). Same role as the WordPress plugin's no404_visitor filter.
            $visitor = $request->visitor();
            Hook::exec('actionNo404Visitor', ['visitor' => &$visitor]);
            $request = $request->withVisitor($visitor);

            $redirector = new Redirector($factory->create(), $emitter, $settings->debug(), $factory->basePath());
            $outcome = $redirector->handle($request);

            // Only reached when there was no redirect.
            (new ErrorReporter())->observe($outcome);
        } catch (Throwable $e) {
            (new ErrorReporter())->report(
                ErrorReporter::KIND_EXCEPTION,
                sprintf('Unexpected %s in the 404 handler; the 404 page was rendered normally.', get_class($e))
            );
        }
    }

    /**
     * The cache directories of every shop (they can differ per shop).
     *
     * @return string[]
     */
    private function cacheDirectories()
    {
        $directories = [ClientFactory::cacheDirectory()];

        try {
            foreach (Shop::getShops(false, null, true) as $shopId) {
                $directories[] = ClientFactory::cacheDirectory(SettingsReader::forShop((int) $shopId));
            }
        } catch (Throwable $e) {
            // The default directory is still removed.
        }

        return array_values(array_unique($directories));
    }

    /**
     * Writes the defaults at the global level; shops and groups inherit them
     * until a merchant overrides a value for a given context.
     *
     * @return bool
     */
    private function installConfiguration()
    {
        $ok = true;
        foreach (Keys::defaults() as $key => $value) {
            $ok = Configuration::updateGlobalValue($key, $value) && $ok;
        }

        return $ok;
    }
}

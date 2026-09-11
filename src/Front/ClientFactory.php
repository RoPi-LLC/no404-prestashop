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

namespace PrestaShop\Module\No404\Front;

use PrestaShop\Module\No404\Adapter\CurlHttp;
use PrestaShop\Module\No404\Adapter\NullCache;
use PrestaShop\Module\No404\Adapter\SymfonyCache;
use PrestaShop\Module\No404\Core\CacheInterface;
use PrestaShop\Module\No404\Core\Client;
use PrestaShop\Module\No404\Settings\Keys;
use PrestaShop\Module\No404\Settings\SettingsReader;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds the core client for the CURRENT shop: its settings, its domains, its
 * internal paths and its own cache namespace. This is the PrestaShop-specific
 * wiring; nothing here decides anything.
 */
final class ClientFactory
{
    /** Sub-directory the module owns inside the cache base directory (the only thing uninstall deletes). */
    public const CACHE_SUBDIRECTORY = 'no404';

    /**
     * PrestaShop paths that never have a catalogue counterpart. Relative to the
     * shop's physical base path. The random back-office folder is handled by
     * shape in Redirector (and its .php files by extension).
     *
     * @var string[]
     */
    public const CORE_IGNORED_PREFIXES = [
        '/modules',
        '/img',
        '/js',
        '/css',
        '/themes',
        '/upload',
        '/download',
        '/var',
        '/vendor',
        '/app',
        '/config',
        '/install',
        '/override',
        '/api',
        '/webservice',
        '/.well-known',
    ];

    /** @var \Context */
    private $context;

    /** @var SettingsReader */
    private $settings;

    /** @var string */
    private $version;

    /**
     * @param \Context $context PrestaShop context (current shop)
     * @param SettingsReader $settings settings of the current shop
     * @param string $version module version (User-Agent)
     */
    public function __construct(\Context $context, SettingsReader $settings, $version)
    {
        $this->context = $context;
        $this->settings = $settings;
        $this->version = (string) $version;
    }

    /** @return Client */
    public function create()
    {
        return new Client(
            [
                'api_base' => $this->settings->apiBase(),
                'api_key' => $this->settings->apiKey(),
                'timeout_ms' => $this->settings->timeoutMs(),
                'cache_ttl' => $this->settings->cacheTtl(),
                'force_301' => $this->settings->force301(),
                'allowed_hosts' => $this->allowedHosts(),
                'ignored_prefixes' => $this->ignoredPrefixes(),
                'user_agent' => 'no404-prestashop/' . $this->version . '; ' . $this->shopUrl(),
                'visitor_secret' => self::visitorSecret($this->shopUrl()),
            ],
            new CurlHttp(),
            self::cacheFor($this->shopId(), $this->settings)
        );
    }

    /**
     * Key for the pseudonymous visitor ID. Derived from this installation's
     * cookie key (never the key itself); the shop URL keeps multistore shops
     * apart. no404 never sees it, so it cannot turn an ID back into an IP
     * address. No cookie key → '' → no ID is sent (the truncated IP still is).
     *
     * @param string $shopUrl the shop's base URL
     *
     * @return string
     */
    public static function visitorSecret($shopUrl)
    {
        // Read through constant(): static analysis stubs give _COOKIE_KEY_ a fixed
        // dummy value and would treat the empty-key check as dead code.
        $cookieKey = defined('_COOKIE_KEY_') ? (string) constant('_COOKIE_KEY_') : '';
        if ('' === $cookieKey) {
            return '';
        }

        return hash_hmac('sha256', 'no404_visitor|' . $shopUrl, $cookieKey);
    }

    /**
     * A client for the back-office connection test. It needs neither the shop
     * context nor a cache: ping() bypasses both, and never redirects anyone.
     *
     * @param SettingsReader $settings settings of the current context
     * @param string $siteUrl address identifying the installation (User-Agent)
     * @param string $version module version (User-Agent)
     *
     * @return Client
     */
    public static function forConnectionTest(SettingsReader $settings, $siteUrl, $version)
    {
        return new Client(
            [
                'api_base' => $settings->apiBase(),
                'api_key' => $settings->apiKey(),
                'timeout_ms' => $settings->timeoutMs(),
                'user_agent' => 'no404-prestashop/' . $version . '; ' . $siteUrl,
            ],
            new CurlHttp(),
            new NullCache()
        );
    }

    /**
     * The module's cache directory: its own sub-directory inside the configured
     * base directory, or inside PrestaShop's cache directory by default.
     *
     * @param SettingsReader|null $settings settings holding a custom base directory, if any
     *
     * @return string
     */
    public static function cacheDirectory(?SettingsReader $settings = null)
    {
        $base = null !== $settings ? $settings->cacheDirectory() : '';

        return self::cacheDirectoryIn('' !== $base ? $base : _PS_CACHE_DIR_);
    }

    /**
     * @param string $base a base directory
     *
     * @return string the module's sub-directory inside it
     */
    public static function cacheDirectoryIn($base)
    {
        return rtrim((string) $base, '/\\') . DIRECTORY_SEPARATOR . self::CACHE_SUBDIRECTORY;
    }

    /**
     * The cache of one shop. Files by default; APCu in front of files when chosen
     * and available. A NullCache when nothing is usable (the module keeps working;
     * the quota is then unprotected).
     *
     * @param int $shopId shop id
     * @param SettingsReader|null $settings that shop's settings (backend, directory)
     *
     * @return CacheInterface
     */
    public static function cacheFor($shopId, ?SettingsReader $settings = null)
    {
        $namespace = 'shop' . (int) $shopId;
        $directory = self::cacheDirectory($settings);
        $filesUsable = SymfonyCache::isUsable($directory);

        if (null !== $settings && Keys::BACKEND_APCU === $settings->cacheBackend() && SymfonyCache::isApcuAvailable()) {
            // APCu memory is shared by every site on the server: the namespace
            // carries this installation's identity as well as the shop.
            $apcuNamespace = 'no404.' . substr(md5(defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ : __DIR__), 0, 12) . '.' . $namespace;

            return $filesUsable
                ? SymfonyCache::apcuThenFilesystem($apcuNamespace, $namespace, $directory)
                : SymfonyCache::apcu($apcuNamespace);
        }

        return $filesUsable ? SymfonyCache::filesystem($namespace, $directory) : new NullCache();
    }

    /** @return string the shop's physical base path without trailing slash ('' at the web root) */
    public function basePath()
    {
        $shop = $this->context->shop;
        $uri = ($shop instanceof \Shop && is_string($shop->physical_uri)) ? $shop->physical_uri : '/';

        return rtrim($uri, '/');
    }

    /**
     * Hosts allowed as a redirect target: every active domain of the shop (main
     * and alternates, HTTP and HTTPS), each with and without "www.". Never a
     * fixed single host — shops map languages to domains.
     *
     * @return string[]
     */
    public function allowedHosts()
    {
        $domains = [];
        $shop = $this->context->shop;
        if ($shop instanceof \Shop) {
            $domains[] = (string) $shop->domain;
            $domains[] = (string) $shop->domain_ssl;
        }

        try {
            foreach (\ShopUrl::getShopUrls($this->shopId()) as $shopUrl) {
                if (!$shopUrl instanceof \ShopUrl || !$shopUrl->active) {
                    continue;
                }
                $domains[] = (string) $shopUrl->domain;
                $domains[] = (string) $shopUrl->domain_ssl;
            }
        } catch (\Throwable $e) {
            // The context shop's own domains are still there.
        }

        $hosts = [];
        foreach ($domains as $domain) {
            // A domain may carry a port ("localhost:8080"); the target's host never does.
            $host = strtolower(trim(explode(':', $domain, 2)[0]));
            if ('' === $host) {
                continue;
            }
            $hosts[] = $host;
            $hosts[] = (0 === strpos($host, 'www.')) ? substr($host, 4) : 'www.' . $host;
        }

        return array_values(array_unique($hosts));
    }

    /**
     * PrestaShop internals, the merchant's excluded paths, and the 404 page's own
     * URL in every language (a CMS page PrestaShop cannot show is REDIRECTED to
     * it, so the original path is already lost — asking about /page-not-found
     * would only burn quota).
     *
     * @return string[]
     */
    public function ignoredPrefixes()
    {
        $base = $this->basePath();
        $prefixes = [];
        foreach (self::CORE_IGNORED_PREFIXES as $prefix) {
            $prefixes[] = $base . $prefix;
        }

        return array_values(array_unique(array_merge(
            $prefixes,
            $this->settings->excludedPrefixes(),
            $this->pageNotFoundPaths()
        )));
    }

    /** @return string[] */
    private function pageNotFoundPaths()
    {
        $paths = [];

        try {
            $link = ($this->context->link instanceof \Link) ? $this->context->link : new \Link();
            foreach (\Language::getLanguages(true, $this->shopId()) as $language) {
                $idLang = is_array($language) && isset($language['id_lang']) ? (int) $language['id_lang'] : (int) $language;
                if ($idLang <= 0) {
                    continue;
                }
                $path = parse_url((string) $link->getPageLink('pagenotfound', null, $idLang), PHP_URL_PATH);
                if (is_string($path) && '' !== $path && '/' !== $path) {
                    $paths[] = $path;
                }
            }
        } catch (\Throwable $e) {
            // Without it the 404 page's path is asked about once, then negatively cached.
        }

        return $paths;
    }

    /** @return string */
    private function shopUrl()
    {
        $shop = $this->context->shop;
        if (!$shop instanceof \Shop) {
            return '';
        }

        try {
            $url = $shop->getBaseURL(true);
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($url) ? $url : '';
    }

    /** @return int */
    private function shopId()
    {
        $shop = $this->context->shop;

        return ($shop instanceof \Shop) ? (int) $shop->id : 0;
    }
}

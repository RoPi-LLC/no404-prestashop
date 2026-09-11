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

namespace PrestaShop\Module\No404\Controller\Admin;

use PrestaShop\Module\No404\Adapter\SymfonyCache;
use PrestaShop\Module\No404\Admin\ConnectionMessage;
use PrestaShop\Module\No404\Admin\StatusReport;
use PrestaShop\Module\No404\Core\Client;
use PrestaShop\Module\No404\Front\ClientFactory;
use PrestaShop\Module\No404\Front\ErrorReporter;
use PrestaShop\Module\No404\Settings\Keys;
use PrestaShop\Module\No404\Settings\SettingsReader;
use PrestaShop\Module\No404\Version;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Settings page: show (read permission), save (update permission) and the
 * connection test (update permission, JSON). Access rights come from the
 * hidden AdminNo404 tab the module installs.
 */
final class SettingsController extends PrestaShopAdminController
{
    public const CSRF_TEST_CONNECTION = 'no404_test_connection';

    private const HANDLER = 'prestashop.module.no404.form.settings_handler';

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_module_manage')]
    public function indexAction(
        #[Autowire(service: self::HANDLER)]
        FormHandlerInterface $formHandler,
    ): Response {
        return $this->renderPage($formHandler->getForm());
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'no404_settings')]
    public function saveAction(
        Request $request,
        #[Autowire(service: self::HANDLER)]
        FormHandlerInterface $formHandler,
    ): Response {
        $form = $formHandler->getForm();
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->redirectToRoute('no404_settings');
        }

        if ($form->isValid()) {
            $errors = $formHandler->save($form->getData());
            $this->resetStorefrontState();

            if ([] === $errors) {
                $this->addFlash('success', $this->trans('Successful update.', [], 'Admin.Notifications.Success'));

                return $this->redirectToRoute('no404_settings');
            }

            $this->addFlashErrors($errors);
        } else {
            $this->addFlashFormErrors($form);
        }

        return $this->renderPage($form);
    }

    /**
     * Sends one real request with the SAVED settings of the current context.
     * The path is fixed; nothing from the request reaches the API.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function testConnectionAction(Request $request, TranslatorInterface $translator): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TEST_CONNECTION, (string) $request->request->get('_csrf_token'))) {
            return $this->json([
                'ok' => false,
                'message' => $this->trans('Invalid token. Reload the page and try again.', [], 'Modules.No404.Admin'),
            ], Response::HTTP_FORBIDDEN);
        }

        $settings = SettingsReader::fromConfiguration();
        $client = ClientFactory::forConnectionTest($settings, $request->getSchemeAndHttpHost() . '/', Version::CURRENT);
        $result = $client->ping(ConnectionMessage::TEST_PATH);

        return $this->json([
            'ok' => 'ok' === $result['code'],
            'message' => ConnectionMessage::describe($result, $translator),
        ]);
    }

    private function renderPage(FormInterface $form): Response
    {
        $settings = SettingsReader::fromConfiguration();
        $cacheDirectory = ClientFactory::cacheDirectory($settings);

        return $this->render('@Modules/no404/views/templates/admin/settings.html.twig', [
            'layoutTitle' => $this->trans('no404 – Auto 404 Redirect', [], 'Modules.No404.Admin'),
            'enableSidebar' => true,
            'settingsForm' => $form->createView(),
            'maskedKey' => $settings->maskedKey(),
            'cacheDirectory' => $cacheDirectory,
            'status' => StatusReport::build($settings, [
                'curl' => function_exists('curl_init'),
                'rewriting' => (bool) \Configuration::get('PS_REWRITING_SETTINGS'),
                'cache_usable' => SymfonyCache::isUsable($cacheDirectory),
                'apcu' => SymfonyCache::isApcuAvailable(),
                'last_error' => ErrorReporter::lastError(),
                'breaker_open' => $this->isBreakerOpen($settings),
            ]),
            'csrfTestConnection' => self::CSRF_TEST_CONNECTION,
        ]);
    }

    /**
     * Only meaningful for a single shop: the breaker lives in each shop's cache.
     */
    private function isBreakerOpen(SettingsReader $settings): bool
    {
        $shopId = \Shop::getContextShopID(true);
        if (null === $shopId) {
            return false;
        }

        return null !== ClientFactory::cacheFor((int) $shopId, $settings)->get(Client::OUTAGE_KEY);
    }

    /**
     * New settings — a fixed key especially — must take effect now, not after
     * the circuit breaker's 5-minute pause: clear every shop's cache and the
     * recorded error. The only cost is a cold cache.
     */
    private function resetStorefrontState(): void
    {
        try {
            foreach (\Shop::getShops(false, null, true) as $shopId) {
                ClientFactory::cacheFor((int) $shopId, SettingsReader::forShop((int) $shopId))->flush();
            }
            \Configuration::deleteByName(Keys::LAST_ERROR);
        } catch (\Throwable $e) {
            // Entries expire on their own; saving must not fail because of this.
        }
    }
}

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

use PrestaShop\Module\No404\Core\Client;
use PrestaShop\Module\No404\Settings\Keys;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Records configuration-class problems seen on the storefront — the key is
 * rejected (404), the subscription or site is inactive (403) — plus unexpected
 * exceptions in the module.
 *
 * Nothing is logged per 404 (noise and personal data). A problem is written to
 * the PrestaShop log and to NO404_LAST_ERROR at most once per hour per kind; the
 * settings page reads NO404_LAST_ERROR for its status box. Recording must never
 * break the page, so every call swallows its own failures.
 */
final class ErrorReporter
{
    public const THROTTLE_SECONDS = 3600;

    public const KIND_INVALID_KEY = 'invalid_key';
    public const KIND_FORBIDDEN = 'forbidden';
    public const KIND_EXCEPTION = 'exception';

    /**
     * Looks at a storefront outcome and records or clears the error state.
     *
     * @param Outcome $outcome result of the redirector
     *
     * @return void
     */
    public function observe(Outcome $outcome)
    {
        if (Client::LOOKUP_API !== $outcome->lookup()) {
            return;
        }

        switch ($outcome->apiStatus()) {
            case 404:
                $this->report(self::KIND_INVALID_KEY, 'The no404 API did not recognise the API key (HTTP 404). Lookups are paused for 5 minutes at a time until the key is fixed.');
                break;
            case 403:
                $this->report(self::KIND_FORBIDDEN, 'The no404 API refused the request (HTTP 403): the subscription is inactive or the site is paused. Lookups are paused for 5 minutes at a time.');
                break;
            case 200:
                $this->clear();
                break;
        }
    }

    /**
     * @param string $kind one of the KIND_* constants
     * @param string $message English log message; never contains the key or visitor data
     *
     * @return void
     */
    public function report($kind, $message)
    {
        try {
            $now = time();
            $last = self::decode(\Configuration::get(Keys::LAST_ERROR));
            if (null !== $last && $last['kind'] === $kind && $now - $last['at'] < self::THROTTLE_SECONDS) {
                return;
            }

            \Configuration::updateValue(Keys::LAST_ERROR, (string) json_encode(['kind' => $kind, 'at' => $now]));
            \PrestaShopLogger::addLog('no404: ' . $message, self::KIND_EXCEPTION === $kind ? 3 : 2, null, 'Module');
        } catch (\Throwable $e) {
            // Reporting is best effort.
        }
    }

    /**
     * Forgets the last error once the API answers normally again. Writes only
     * when there is something to clear.
     *
     * @return void
     */
    public function clear()
    {
        try {
            $last = self::decode(\Configuration::get(Keys::LAST_ERROR));
            if (null !== $last && self::KIND_EXCEPTION !== $last['kind']) {
                \Configuration::updateValue(Keys::LAST_ERROR, '');
            }
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    /**
     * The last recorded problem in the current shop context, for the settings page.
     *
     * @return array{kind: string, at: int}|null
     */
    public static function lastError()
    {
        try {
            return self::decode(\Configuration::get(Keys::LAST_ERROR));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param mixed $raw stored JSON
     *
     * @return array{kind: string, at: int}|null
     */
    public static function decode($raw)
    {
        if (!is_string($raw) || '' === $raw) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['kind'], $data['at'])) {
            return null;
        }

        return ['kind' => (string) $data['kind'], 'at' => (int) $data['at']];
    }
}

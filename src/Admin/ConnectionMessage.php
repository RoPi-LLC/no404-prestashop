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

namespace PrestaShop\Module\No404\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Turns a Client::ping() result into a message the merchant can act on:
 * an invalid key, an inactive subscription and an exhausted quota each get
 * their own explanation (same wording as the WordPress plugin).
 *
 * The API key never reaches the screen: a redirect's Location header is
 * reduced to scheme + host before it is shown.
 */
final class ConnectionMessage
{
    /** Fixed test path — the path is never taken from the request. */
    public const TEST_PATH = '/no404-connection-test';

    /**
     * @param array<string, mixed> $result output of Client::ping()
     * @param object $translator Symfony TranslatorInterface (anything with trans($id, $parameters, $domain))
     *
     * @return string
     */
    public static function describe(array $result, $translator)
    {
        $detail = isset($result['detail']) ? trim((string) $result['detail']) : '';
        $code = isset($result['code']) ? (string) $result['code'] : '';

        switch ($code) {
            case 'ok':
                if (!empty($result['redirect'])) {
                    return $translator->trans(
                        'Connection succeeded. Suggested target for the test path: %target% (source: %source%, score: %score%).',
                        [
                            '%target%' => (string) $result['redirect'],
                            '%source%' => isset($result['source']) ? (string) $result['source'] : 'NONE',
                            '%score%' => sprintf('%.2f', isset($result['score']) ? (float) $result['score'] : 0.0),
                        ],
                        'Modules.No404.Admin'
                    );
                }

                return $translator->trans('Connection succeeded. Your API key is valid; no match was found for the test path, which is what we expect.', [], 'Modules.No404.Admin');

            case 'no_api_key':
                return $translator->trans('No API key has been entered. Save your key and try again.', [], 'Modules.No404.Admin');

            case 'no_api_base':
                return $translator->trans('The no404 address is empty.', [], 'Modules.No404.Admin');

            case 'invalid_key':
                return $translator->trans('Invalid API key (404). Copy the key from your site\'s Integration page in the no404 dashboard; if you rotated the key recently, enter the new one here as well.', [], 'Modules.No404.Admin');

            case 'forbidden':
                return '' !== $detail
                    ? $translator->trans('Access denied (403): %detail%. Your subscription may be inactive, monitoring for this site may be paused, or your account may be suspended.', ['%detail%' => $detail], 'Modules.No404.Admin')
                    : $translator->trans('Access denied (403). Your subscription may be inactive, monitoring for this site may be paused, or your account may be suspended.', [], 'Modules.No404.Admin');

            case 'rate_limited':
                return $translator->trans('Rate limit exceeded, or your monthly event quota is used up (429). Check your quota in the no404 dashboard; if you sent many requests in a short time, try again in a minute.', [], 'Modules.No404.Admin');

            case 'invalid_path':
                return $translator->trans('The service rejected the test path (422). Please contact no404 support.', [], 'Modules.No404.Admin');

            case 'unreachable':
                return '' !== $detail
                    ? $translator->trans('Could not reach the no404 server: %detail%. Make sure your server is allowed to make outbound HTTPS requests.', ['%detail%' => $detail], 'Modules.No404.Admin')
                    : $translator->trans('Could not reach the no404 server. Make sure your server is allowed to make outbound HTTPS requests.', [], 'Modules.No404.Admin');

            case 'redirected':
                $base = '' !== $detail ? self::apiBaseFromLocation($detail) : '';

                return '' !== $base
                    ? $translator->trans('The no404 address redirects somewhere else. Enter %address% in the "no404 address" field, save, and test again.', ['%address%' => $base], 'Modules.No404.Admin')
                    : $translator->trans('The no404 address redirects somewhere else, so no answer could be read. Check the address in the settings — it is usually the www form of the domain.', [], 'Modules.No404.Admin');

            case 'server_error':
                return $translator->trans('no404 hit a temporary error (5xx). Your shop is unaffected; try again shortly.', [], 'Modules.No404.Admin');

            default:
                return $translator->trans('Unexpected response (HTTP %status%).', ['%status%' => isset($result['status']) ? (int) $result['status'] : 0], 'Modules.No404.Admin');
        }
    }

    /**
     * Reduces a Location header to the address that belongs in the settings
     * field: scheme + host (+ port). Path and query — which could carry a key —
     * are dropped.
     *
     * @param string $location the Location header
     *
     * @return string empty when the header is not a usable absolute URL
     */
    public static function apiBaseFromLocation($location)
    {
        $parts = parse_url(trim((string) $location));
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            return '';
        }

        $base = $scheme . '://' . strtolower($parts['host']);

        return isset($parts['port']) ? $base . ':' . (int) $parts['port'] : $base;
    }
}

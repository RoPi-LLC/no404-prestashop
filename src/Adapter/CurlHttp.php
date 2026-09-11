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

namespace PrestaShop\Module\No404\Adapter;

use PrestaShop\Module\No404\Core\HttpInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * cURL transport. PrestaShop 9 removed Guzzle and requires ext-curl, so plain
 * cURL is the dependency-free choice.
 *
 * Redirects are NOT followed: the no404 API does not redirect, and a 3xx means
 * the configured address is wrong. The connection test reports the Location
 * host so the merchant can fix it (the default is already the canonical
 * `https://www.no404.tr`).
 */
final class CurlHttp implements HttpInterface
{
    /** Connect timeout for storefront lookups (ms). */
    public const CONNECT_TIMEOUT_MS = 700;

    /** Upper bound for the connect phase of long (connection test) requests (ms). */
    public const MAX_CONNECT_TIMEOUT_MS = 3000;

    /**
     * {@inheritdoc}
     */
    public function get($url, $timeoutMs, $userAgent, array $headers = [])
    {
        if (!function_exists('curl_init')) {
            return self::failure('The PHP curl extension is not available.');
        }

        try {
            $handle = curl_init();
            if (false === $handle) {
                return self::failure('curl_init() failed.');
            }

            $timeoutMs = max(1, (int) $timeoutMs);
            $location = '';

            $lines = ['Accept: application/json'];
            foreach ($headers as $name => $value) {
                $lines[] = self::clean((string) $name) . ': ' . self::clean((string) $value);
            }

            curl_setopt_array($handle, [
                CURLOPT_URL => (string) $url,
                CURLOPT_HTTPGET => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                // Required for sub-second timeouts with the default resolver.
                CURLOPT_NOSIGNAL => true,
                CURLOPT_CONNECTTIMEOUT_MS => self::connectTimeout($timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => self::clean((string) $userAgent),
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$location) {
                    if (0 === stripos($line, 'location:')) {
                        $location = trim(substr($line, 9));
                    }

                    return strlen($line);
                },
            ]);

            $body = curl_exec($handle);
            if (false === $body) {
                $error = curl_error($handle);

                return self::failure('' !== $error ? $error : 'Transport error.');
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return [
                'ok' => true,
                'status' => $status,
                'body' => (string) $body,
                'error' => '',
                'location' => ($status >= 300 && $status < 400) ? $location : '',
            ];
        } catch (\Throwable $e) {
            return self::failure('Transport error.');
        }
    }

    /**
     * Storefront lookups (≤ 1500 ms) get a 700 ms connect budget; the connection
     * test, which is allowed to wait, gets half of its budget up to 3 s.
     *
     * @param int $timeoutMs total timeout
     *
     * @return int
     */
    public static function connectTimeout($timeoutMs)
    {
        if ($timeoutMs <= 1500) {
            return min(self::CONNECT_TIMEOUT_MS, $timeoutMs);
        }

        return min(self::MAX_CONNECT_TIMEOUT_MS, intdiv($timeoutMs, 2));
    }

    /**
     * Strips CR/LF so a header value can never start a new header.
     *
     * @param string $value raw value
     *
     * @return string
     */
    private static function clean($value)
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * @param string $error human-readable transport error
     *
     * @return array{ok: bool, status: int, body: string, error: string, location: string}
     */
    private static function failure($error)
    {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $error, 'location' => ''];
    }
}

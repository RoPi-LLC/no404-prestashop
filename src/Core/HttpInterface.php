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

namespace PrestaShop\Module\No404\Core;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * HTTP transport interface.
 *
 * The core (Client) knows nothing about the transport layer: the PrestaShop
 * adapter uses cURL, and the tests pass in a fake implementation. This is the
 * boundary that keeps the core testable without PrestaShop.
 */
interface HttpInterface
{
    /**
     * Performs a GET request. NEVER throws — failures come back in the array.
     *
     * @param string $url full URL
     * @param int $timeoutMs timeout in milliseconds
     * @param string $userAgent User-Agent to send
     * @param array<string, string> $headers extra request headers (name => value), e.g. Authorization
     *
     * ok=false → transport failure (DNS, timeout, TLS); status is 0.
     * ok=true  → an HTTP response arrived; status may be 3xx/4xx/5xx.
     * location → the Location header of a 3xx the transport did not follow.
     *
     * Only `ok` is guaranteed: the core reads every other key defensively, so an
     * adapter that leaves one out degrades to "no redirect" instead of an error.
     *
     * @return array{ok: bool, status?: int, body?: string, error?: string, location?: string}
     */
    public function get($url, $timeoutMs, $userAgent, array $headers = []);
}

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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The only way the redirector touches the HTTP response. The production
 * implementation sends real headers and ends the request; the tests record.
 */
interface ResponseEmitter
{
    /**
     * @return bool true when output has started and headers can no longer be sent
     */
    public function headersSent();

    /**
     * Adds response headers and lets the request continue (debug headers).
     *
     * @param array<string, string> $headers name => value
     *
     * @return void
     */
    public function headers(array $headers);

    /**
     * Sends the redirect and ENDS the request. The production implementation
     * never returns.
     *
     * @param string $target validated target URL
     * @param int $status 301 or 302
     * @param array<string, string> $headers extra headers (X-Redirect-By, debug)
     *
     * @return void
     */
    public function redirect($target, $status, array $headers);
}

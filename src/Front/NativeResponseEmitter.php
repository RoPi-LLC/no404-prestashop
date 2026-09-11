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
 * Sends real headers with header() and ends the request after a redirect —
 * one redirect, no chain, nothing rendered after it.
 */
final class NativeResponseEmitter implements ResponseEmitter
{
    /** @var callable|null runs right before the redirect headers (e.g. stop the cookie being written) */
    private $beforeRedirect;

    /**
     * @param callable|null $beforeRedirect runs right before the redirect is sent
     */
    public function __construct(?callable $beforeRedirect = null)
    {
        $this->beforeRedirect = $beforeRedirect;
    }

    /**
     * {@inheritdoc}
     */
    public function headersSent()
    {
        return headers_sent();
    }

    /**
     * {@inheritdoc}
     */
    public function headers(array $headers)
    {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function redirect($target, $status, array $headers)
    {
        if (null !== $this->beforeRedirect) {
            call_user_func($this->beforeRedirect);
        }

        $this->headers($headers);
        header('Location: ' . $target, true, (int) $status);

        exit;
    }
}

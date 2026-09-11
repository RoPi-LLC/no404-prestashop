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

namespace PrestaShop\Module\No404;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the running PrestaShop can do, where it differs between the releases
 * this module supports. The version is passed in, so every case can be tested
 * without PrestaShop loaded.
 */
final class Platform
{
    /**
     * First release whose core fires `actionNotFound` (404 page, deleted product,
     * deleted category). Checked in the PrestaShop sources: the 8.x line and
     * 9.0.0 – 9.1.4 never fire it.
     */
    public const NOT_FOUND_HOOK_SINCE = '9.1.5';

    /**
     * @param string $psVersion PrestaShop version (_PS_VERSION_)
     *
     * @return bool
     */
    public static function firesNotFoundHook($psVersion)
    {
        return version_compare((string) $psVersion, self::NOT_FOUND_HOOK_SINCE, '>=');
    }
}

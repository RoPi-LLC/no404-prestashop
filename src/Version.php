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
 * The module version in one place, readable from Symfony controllers without
 * loading the legacy module class. Must match config.xml and no404.php.
 */
final class Version
{
    public const CURRENT = '2.0.0';
}

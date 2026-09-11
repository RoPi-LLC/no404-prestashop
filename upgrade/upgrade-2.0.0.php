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
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 2.0.0 opens the PrestaShop 9 line; 1.x is the PrestaShop 8 line. A store that
 * ran 1.x and then moved to PrestaShop 9 upgrades through here. Settings, cache
 * and the hidden tab carry over unchanged; the hook registration is repeated in
 * case the 1.x install lacks it (registering an already registered hook is a
 * no-op that returns true).
 *
 * @param No404 $module
 *
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    return (bool) $module->registerHook(['actionNotFound', 'actionOutputHTMLBefore']);
}

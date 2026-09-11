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

use PrestaShop\Module\No404\Core\CacheInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Used when the cache directory is not writable. Redirects keep working
 * (fail-open), but nothing is remembered: every 404 costs a lookup and the
 * circuit breaker cannot trip. The settings page warns about this state.
 */
final class NullCache implements CacheInterface
{
    /**
     * {@inheritdoc}
     */
    public function get($key)
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $ttl)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
    }
}

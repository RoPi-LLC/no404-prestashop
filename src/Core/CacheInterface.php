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
 * Cache interface.
 *
 * Quota protection depends on this layer: a bot pulling the same dead URL 200
 * times a day must cost one event, not 200. That is why NEGATIVE results are
 * cached as well.
 */
interface CacheInterface
{
    /**
     * @param string $key key (raw; the implementation adds its own prefix/hash)
     *
     * @return mixed|null null when the entry is missing or expired
     */
    public function get($key);

    /**
     * @param string $key key
     * @param mixed $value JSON-serialisable value
     * @param int $ttl seconds
     *
     * @return void
     */
    public function set($key, $value, $ttl);

    /**
     * Deletes every entry belonging to this module (called when settings change).
     *
     * @return void
     */
    public function flush();
}

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
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Cache on top of symfony/cache, which ships with PrestaShop 9 itself (the
 * module does not bundle it).
 *
 * Why not `Cache::getInstance()`: PrestaShop's legacy cache has no file driver
 * and `_PS_CACHE_ENABLED_` is off by default, so on most stores it caches nothing.
 *
 * Backends: files (default, works everywhere), or APCu in front of files for
 * busy stores. Either way:
 * - one namespace per shop, so each shop's circuit breaker and entries are
 *   independent (a quota hit on one shop does not silence another);
 * - keys are hashed: the core's keys contain ':' which PSR-6 reserves;
 * - values are stored as JSON strings (json_encode / json_decode only);
 * - every call is wrapped: a cache failure is a cache miss, never an error page.
 */
final class SymfonyCache implements CacheInterface
{
    /** @var CacheItemPoolInterface */
    private $pool;

    public function __construct(CacheItemPoolInterface $pool)
    {
        $this->pool = $pool;
    }

    /**
     * @param string $namespace per-shop namespace, [-+_.A-Za-z0-9]
     * @param string $directory cache directory
     *
     * @return self
     */
    public static function filesystem($namespace, $directory)
    {
        return new self(new FilesystemAdapter($namespace, 0, $directory));
    }

    /**
     * APCu alone — used when APCu is chosen but the cache directory is unusable.
     *
     * @param string $apcuNamespace namespace unique to this installation and shop
     *
     * @return self
     */
    public static function apcu($apcuNamespace)
    {
        return new self(new ApcuAdapter($apcuNamespace));
    }

    /**
     * APCu answers first; files keep entries across PHP-FPM restarts.
     *
     * @param string $apcuNamespace namespace unique to this installation and shop
     * @param string $namespace per-shop namespace for the files
     * @param string $directory cache directory
     *
     * @return self
     */
    public static function apcuThenFilesystem($apcuNamespace, $namespace, $directory)
    {
        return new self(new ChainAdapter([
            new ApcuAdapter($apcuNamespace),
            new FilesystemAdapter($namespace, 0, $directory),
        ]));
    }

    /**
     * Can a file cache live in this directory? Creates it when missing.
     *
     * @param string $directory cache directory
     *
     * @return bool
     */
    public static function isUsable($directory)
    {
        if (!class_exists(FilesystemAdapter::class)) {
            return false;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        return is_writable($directory);
    }

    /** @return bool APCu is loaded and enabled for this SAPI */
    public static function isApcuAvailable()
    {
        try {
            return class_exists(ApcuAdapter::class) && ApcuAdapter::isSupported();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get($key)
    {
        try {
            $item = $this->pool->getItem(self::hash($key));
            if (!$item->isHit()) {
                return null;
            }

            $raw = $item->get();
            if (!is_string($raw)) {
                return null;
            }

            return json_decode($raw, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $ttl)
    {
        try {
            $json = json_encode($value);
            if (false === $json) {
                return;
            }

            $item = $this->pool->getItem(self::hash($key));
            $item->set($json);
            $item->expiresAfter(max(1, (int) $ttl));
            $this->pool->save($item);
        } catch (\Throwable $e) {
            // A cache that cannot be written is a cache miss next time. Nothing else.
        }
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        try {
            $this->pool->clear();
        } catch (\Throwable $e) {
            // Entries expire on their own.
        }
    }

    /**
     * @param string $key raw key
     *
     * @return string
     */
    private static function hash($key)
    {
        return md5((string) $key);
    }
}

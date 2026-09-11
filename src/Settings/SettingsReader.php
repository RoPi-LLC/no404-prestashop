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

namespace PrestaShop\Module\No404\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reads the settings of the CURRENT shop context, validates them and clamps
 * them to their allowed ranges. A missing or broken value falls back to its
 * default — reading settings never fails.
 *
 * Multistore inheritance (all shops → group → shop) is PrestaShop's own:
 * `Configuration::get()` without explicit ids resolves it for the context shop.
 */
final class SettingsReader
{
    /** Placeholder used when showing the key masked. */
    public const MASK = '••••••••';

    /** @var callable(string): mixed */
    private $get;

    /**
     * @param callable(string): mixed $get returns the raw stored value of a key, false when missing
     */
    public function __construct(callable $get)
    {
        $this->get = $get;
    }

    /**
     * Reader bound to PrestaShop's `Configuration` in the current shop context.
     *
     * @return self
     */
    public static function fromConfiguration()
    {
        return new self(static function ($key) {
            return \Configuration::get($key);
        });
    }

    /**
     * Reader bound to one shop, whatever the current context (uninstall,
     * cache reset after saving).
     *
     * @param int $shopId shop id
     *
     * @return self
     */
    public static function forShop($shopId)
    {
        $shopId = (int) $shopId;

        return new self(static function ($key) use ($shopId) {
            return \Configuration::get($key, null, \Shop::getGroupFromShop($shopId), $shopId);
        });
    }

    /** @return bool also handle 404s rendered without the actionNotFound event */
    public function catchAll()
    {
        return $this->flag(Keys::CATCH_ALL, false);
    }

    /** @return string Keys::BACKEND_FILE or Keys::BACKEND_APCU */
    public function cacheBackend()
    {
        return SettingsSanitizer::cacheBackend($this->string(Keys::CACHE_BACKEND));
    }

    /** @return string base directory of the file cache; '' = PrestaShop's cache directory */
    public function cacheDirectory()
    {
        $directory = SettingsSanitizer::cacheDirectory($this->string(Keys::CACHE_DIR));

        return null === $directory ? '' : $directory;
    }

    /** @return bool the master switch */
    public function isEnabled()
    {
        return $this->flag(Keys::ENABLED, true);
    }

    /** @return bool switched on AND has a key */
    public function isOperable()
    {
        return $this->isEnabled() && '' !== $this->apiKey();
    }

    /** @return string a valid http(s) base URL without trailing slash; the default otherwise */
    public function apiBase()
    {
        $base = self::normalizeApiBase($this->string(Keys::API_BASE));
        if ('' === $base || !self::isHttpUrl($base)) {
            return Keys::DEFAULT_API_BASE;
        }

        return $base;
    }

    /** @return string the API key, reduced to the characters a key can contain */
    public function apiKey()
    {
        return self::sanitizeApiKey($this->string(Keys::API_KEY));
    }

    /** @return string masked key for display ("••••••••abcd"); '' when there is none */
    public function maskedKey()
    {
        $key = $this->apiKey();

        return '' === $key ? '' : self::MASK . substr($key, -4);
    }

    /** @return bool */
    public function force301()
    {
        return $this->flag(Keys::FORCE_301, false);
    }

    /** @return int seconds, 60 – 604800 */
    public function cacheTtl()
    {
        return $this->int(Keys::CACHE_TTL, Keys::DEFAULT_CACHE_TTL, Keys::MIN_CACHE_TTL, Keys::MAX_CACHE_TTL);
    }

    /** @return int milliseconds, 300 – 1500 */
    public function timeoutMs()
    {
        return $this->int(Keys::TIMEOUT_MS, Keys::DEFAULT_TIMEOUT_MS, Keys::MIN_TIMEOUT_MS, Keys::MAX_TIMEOUT_MS);
    }

    /** @return bool send X-No404-* diagnostic headers */
    public function debug()
    {
        return $this->flag(Keys::DEBUG, false);
    }

    /**
     * The "Excluded paths" text as a list of prefixes: one per line (commas are
     * accepted too), a missing leading slash is added, a trailing one dropped.
     *
     * @return string[]
     */
    public function excludedPrefixes()
    {
        $raw = $this->string(Keys::EXCLUDED_PATHS);
        if ('' === trim($raw)) {
            return [];
        }

        $result = [];
        foreach ((array) preg_split('/[\r\n,]+/', $raw) as $line) {
            $line = trim((string) $line);
            if ('' === $line) {
                continue;
            }
            if (0 !== strpos($line, '/')) {
                $line = '/' . $line;
            }
            $result[] = rtrim($line, '/');
        }

        return array_values(array_unique(array_filter($result)));
    }

    /** @return string the excluded prefixes as the settings form shows them, one per line */
    public function excludedPathsText()
    {
        return implode("\n", $this->excludedPrefixes());
    }

    /**
     * Rewrites the redirecting bare domain to the canonical host; any other
     * address (a self-hosted installation) is returned untouched.
     *
     * @param mixed $apiBase the address to check
     *
     * @return string
     */
    public static function normalizeApiBase($apiBase)
    {
        $apiBase = rtrim(trim((string) $apiBase), '/');

        return (Keys::LEGACY_API_BASE === $apiBase) ? Keys::DEFAULT_API_BASE : $apiBase;
    }

    /**
     * @param mixed $key raw key
     *
     * @return string
     */
    public static function sanitizeApiKey($key)
    {
        return (string) preg_replace('/[^A-Za-z0-9\-_]/', '', trim((string) $key));
    }

    /**
     * @param string $url candidate
     *
     * @return bool
     */
    public static function isHttpUrl($url)
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return 'http' === $scheme || 'https' === $scheme;
    }

    /**
     * @param string $key configuration key
     *
     * @return mixed false when missing
     */
    private function raw($key)
    {
        try {
            return call_user_func($this->get, $key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param string $key configuration key
     *
     * @return string
     */
    private function string($key)
    {
        $value = $this->raw($key);

        return (false === $value || null === $value || is_array($value)) ? '' : (string) $value;
    }

    /**
     * @param string $key configuration key
     * @param bool $default value when missing
     *
     * @return bool
     */
    private function flag($key, $default)
    {
        $value = $this->raw($key);
        if (false === $value || null === $value || '' === $value) {
            return $default;
        }

        return (bool) (int) $value;
    }

    /**
     * @param string $key configuration key
     * @param int $default value when missing or not numeric
     * @param int $min lower bound
     * @param int $max upper bound
     *
     * @return int
     */
    private function int($key, $default, $min, $max)
    {
        $value = $this->raw($key);
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}

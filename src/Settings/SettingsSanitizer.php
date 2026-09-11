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
 * Cleans what the settings form submits before it is stored. Same rules as
 * the WordPress plugin's No404_Options::sanitize(): an empty address restores
 * the default, an invalid one keeps the previous value, an empty or still
 * masked key keeps the stored key.
 */
final class SettingsSanitizer
{
    /** Upper bound of the excluded-paths list, so the field cannot grow without limit. */
    public const MAX_EXCLUDED_LINES = 200;

    /**
     * @param mixed $raw submitted address
     * @param string $current stored address
     *
     * @return array{value: string, valid: bool} valid=false → the previous value was kept
     */
    public static function apiBase($raw, $current)
    {
        $raw = trim((string) $raw);
        if ('' === $raw) {
            return ['value' => Keys::DEFAULT_API_BASE, 'valid' => true];
        }

        $base = SettingsReader::normalizeApiBase($raw);
        if (!SettingsReader::isHttpUrl($base) || preg_match('/[\x00-\x1F\x7F\s]/', $base)) {
            $previous = SettingsReader::normalizeApiBase($current);

            return ['value' => '' !== $previous ? $previous : Keys::DEFAULT_API_BASE, 'valid' => false];
        }

        return ['value' => $base, 'valid' => true];
    }

    /**
     * @param mixed $raw submitted key
     *
     * @return string|null the key to store, or null to keep the stored one
     */
    public static function apiKey($raw)
    {
        $raw = trim((string) $raw);
        if ('' === $raw || false !== strpos($raw, SettingsReader::MASK)) {
            return null;
        }

        $key = SettingsReader::sanitizeApiKey($raw);

        return '' === $key ? null : $key;
    }

    /**
     * @param mixed $raw submitted value
     *
     * @return int
     */
    public static function cacheTtl($raw)
    {
        return self::clamp($raw, Keys::DEFAULT_CACHE_TTL, Keys::MIN_CACHE_TTL, Keys::MAX_CACHE_TTL);
    }

    /**
     * @param mixed $raw submitted value
     *
     * @return int
     */
    public static function timeoutMs($raw)
    {
        return self::clamp($raw, Keys::DEFAULT_TIMEOUT_MS, Keys::MIN_TIMEOUT_MS, Keys::MAX_TIMEOUT_MS);
    }

    /**
     * @param mixed $raw submitted value
     *
     * @return string Keys::BACKEND_FILE unless APCu was explicitly chosen
     */
    public static function cacheBackend($raw)
    {
        return Keys::BACKEND_APCU === $raw ? Keys::BACKEND_APCU : Keys::BACKEND_FILE;
    }

    /**
     * An absolute directory path without ".." segments or control characters.
     * Whether it exists and is writable is checked separately.
     *
     * @param mixed $raw submitted value
     *
     * @return string|null '' = use PrestaShop's cache directory; null = invalid
     */
    public static function cacheDirectory($raw)
    {
        $raw = trim((string) $raw);
        if ('' === $raw) {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $raw)) {
            return null;
        }

        $path = str_replace('\\', '/', $raw);
        $absolute = 0 === strpos($path, '/') || 1 === preg_match('#^[A-Za-z]:/#', $path);
        if (!$absolute || in_array('..', explode('/', $path), true)) {
            return null;
        }

        $trimmed = rtrim($raw, '/\\');

        return '' === $trimmed ? $raw : $trimmed;
    }

    /**
     * One prefix per line: control characters and markup removed, a leading
     * slash added, a trailing one dropped, duplicates removed.
     *
     * @param mixed $raw submitted text
     *
     * @return string newline-separated prefixes
     */
    public static function excludedPaths($raw)
    {
        $lines = preg_split('/[\r\n,]+/', strip_tags((string) $raw));
        $result = [];

        foreach ((array) $lines as $line) {
            $line = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) $line));
            if ('' === $line) {
                continue;
            }
            if (0 !== strpos($line, '/')) {
                $line = '/' . $line;
            }
            $line = rtrim($line, '/');
            if ('' === $line || strlen($line) > 2048) {
                continue;
            }
            $result[] = $line;
        }

        return implode("\n", array_slice(array_values(array_unique($result)), 0, self::MAX_EXCLUDED_LINES));
    }

    /**
     * @param mixed $raw value
     * @param int $default used when not numeric
     * @param int $min lower bound
     * @param int $max upper bound
     *
     * @return int
     */
    private static function clamp($raw, $default, $min, $max)
    {
        if (!is_numeric($raw)) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }
}

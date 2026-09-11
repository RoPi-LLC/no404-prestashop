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
 * `Configuration` keys and their defaults. Everything the module stores lives
 * in the core `configuration` table under the NO404_ prefix — no own table.
 */
final class Keys
{
    public const ENABLED = 'NO404_ENABLED';
    public const API_BASE = 'NO404_API_BASE';
    public const API_KEY = 'NO404_API_KEY';
    public const FORCE_301 = 'NO404_FORCE_301';
    public const CACHE_TTL = 'NO404_CACHE_TTL';
    public const TIMEOUT_MS = 'NO404_TIMEOUT_MS';
    public const EXCLUDED_PATHS = 'NO404_EXCLUDED_PATHS';
    public const DEBUG = 'NO404_DEBUG';
    /** Also handle 404s PrestaShop renders without firing actionNotFound (actionOutputHTMLBefore). */
    public const CATCH_ALL = 'NO404_CATCH_ALL';
    public const CACHE_BACKEND = 'NO404_CACHE_BACKEND';
    /** Base directory for the file cache; '' = PrestaShop's own cache directory. */
    public const CACHE_DIR = 'NO404_CACHE_DIR';

    public const BACKEND_FILE = 'file';
    public const BACKEND_APCU = 'apcu';

    /** Internal state: the last configuration-class error seen on the storefront (JSON). */
    public const LAST_ERROR = 'NO404_LAST_ERROR';

    /**
     * Address of the no404 service. The WWW host is the canonical one:
     * `https://no404.tr` answers every request with a 302 (WP 1.0.0 lesson).
     */
    public const DEFAULT_API_BASE = 'https://www.no404.tr';

    /** The bare domain only redirects; it is rewritten wherever it is read or saved. */
    public const LEGACY_API_BASE = 'https://no404.tr';

    public const DEFAULT_CACHE_TTL = 3600;
    public const MIN_CACHE_TTL = 60;
    public const MAX_CACHE_TTL = 604800;

    public const DEFAULT_TIMEOUT_MS = 1500;
    public const MIN_TIMEOUT_MS = 300;
    /** Hard ceiling from the integration spec: a 404 page must never wait longer. */
    public const MAX_TIMEOUT_MS = 1500;

    /**
     * Settings the merchant edits, with their install-time defaults.
     *
     * @return array<string, int|string>
     */
    public static function defaults()
    {
        return [
            self::ENABLED => 1,
            self::API_BASE => self::DEFAULT_API_BASE,
            self::API_KEY => '',
            self::FORCE_301 => 0,
            self::CACHE_TTL => self::DEFAULT_CACHE_TTL,
            self::TIMEOUT_MS => self::DEFAULT_TIMEOUT_MS,
            self::EXCLUDED_PATHS => '',
            self::DEBUG => 0,
            self::CATCH_ALL => 0,
            self::CACHE_BACKEND => self::BACKEND_FILE,
            self::CACHE_DIR => '',
        ];
    }

    /**
     * Every key the module may have written (uninstall cleanup).
     *
     * @return string[]
     */
    public static function all()
    {
        return array_merge(array_keys(self::defaults()), [self::LAST_ERROR]);
    }
}

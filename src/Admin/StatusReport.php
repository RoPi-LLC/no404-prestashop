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

namespace PrestaShop\Module\No404\Admin;

use PrestaShop\Module\No404\Front\ErrorReporter;
use PrestaShop\Module\No404\Settings\Keys;
use PrestaShop\Module\No404\Settings\SettingsReader;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The status boxes above the settings form. Silence is the worst failure mode
 * of this module ("I installed it and nothing happens"), so every state that
 * stops it from working is spelled out.
 *
 * Pure: the controller gathers the environment; the Twig template holds the
 * wording for each code.
 */
final class StatusReport
{
    public const LEVEL_DANGER = 'danger';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_SUCCESS = 'success';

    /** Warnings that do not stop redirects. */
    private const NON_BLOCKING = ['cache_unwritable', 'apcu_unavailable'];

    /**
     * @param SettingsReader $settings settings of the current context
     * @param array{curl?: bool, rewriting?: bool, cache_usable?: bool, apcu?: bool, last_error?: array{kind: string, at: int}|null, breaker_open?: bool} $env
     *
     * @return array<int, array{level: string, code: string, params: array<string, mixed>}>
     */
    public static function build(SettingsReader $settings, array $env)
    {
        $items = [];

        if (empty($env['curl'])) {
            $items[] = self::item(self::LEVEL_DANGER, 'no_curl');
        }
        if ('' === $settings->apiKey()) {
            $items[] = self::item(self::LEVEL_DANGER, 'no_key');
        }
        if (!$settings->isEnabled()) {
            $items[] = self::item(self::LEVEL_WARNING, 'disabled');
        }
        if (empty($env['rewriting'])) {
            $items[] = self::item(self::LEVEL_WARNING, 'rewriting_off');
        }
        if (empty($env['cache_usable'])) {
            $items[] = self::item(self::LEVEL_WARNING, 'cache_unwritable');
        }
        if (Keys::BACKEND_APCU === $settings->cacheBackend() && empty($env['apcu'])) {
            $items[] = self::item(self::LEVEL_WARNING, 'apcu_unavailable');
        }

        $lastError = $env['last_error'] ?? null;
        if (is_array($lastError)) {
            $code = [
                ErrorReporter::KIND_INVALID_KEY => 'last_error_invalid_key',
                ErrorReporter::KIND_FORBIDDEN => 'last_error_forbidden',
                ErrorReporter::KIND_EXCEPTION => 'last_error_exception',
            ][$lastError['kind']] ?? null;
            if (null !== $code) {
                $items[] = self::item(self::LEVEL_DANGER, $code, ['at' => (int) $lastError['at']]);
            }
        } elseif (!empty($env['breaker_open'])) {
            $items[] = self::item(self::LEVEL_WARNING, 'breaker_open');
        }

        // Active = nothing stops redirects. Cache problems cost quota or speed
        // but redirects still happen, so they do not cancel "active".
        $blocking = array_filter($items, static function ($item) {
            return !in_array($item['code'], self::NON_BLOCKING, true);
        });
        if ([] === $blocking && $settings->isOperable()) {
            array_unshift($items, self::item(self::LEVEL_SUCCESS, 'active'));
        }

        return $items;
    }

    /**
     * @param string $level LEVEL_*
     * @param string $code message code for the template
     * @param array<string, mixed> $params template parameters
     *
     * @return array{level: string, code: string, params: array<string, mixed>}
     */
    private static function item($level, $code, array $params = [])
    {
        return ['level' => $level, 'code' => $code, 'params' => $params];
    }
}

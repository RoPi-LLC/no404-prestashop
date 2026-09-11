<?php

namespace PrestaShop\Module\No404\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\No404\Admin\ConnectionMessage;
use PrestaShop\Module\No404\Admin\StatusReport;
use PrestaShop\Module\No404\Settings\Keys;
use PrestaShop\Module\No404\Settings\SettingsReader;

/**
 * The back-office logic that does not need PrestaShop: connection-test
 * messages and the status boxes.
 */
final class AdminTest extends TestCase
{
    private static function translator()
    {
        return new class {
            public $domains = [];

            public function trans($id, array $parameters = [], $domain = null)
            {
                $this->domains[] = $domain;

                return strtr($id, $parameters);
            }
        };
    }

    private static function ping($code, array $extra = [])
    {
        return array_merge(['code' => $code, 'status' => 0, 'found' => false, 'redirect' => null, 'score' => 0.0, 'source' => 'NONE', 'detail' => ''], $extra);
    }

    public static function messageCases()
    {
        return [
            'ok, no match' => [self::ping('ok', ['status' => 200]), 'Connection succeeded. Your API key is valid'],
            'ok, match' => [self::ping('ok', ['redirect' => '/x', 'source' => 'CATALOG', 'score' => 0.8123]), 'Suggested target for the test path: /x (source: CATALOG, score: 0.81)'],
            'no key' => [self::ping('no_api_key'), 'No API key has been entered'],
            'no base' => [self::ping('no_api_base'), 'The no404 address is empty'],
            'invalid key' => [self::ping('invalid_key', ['status' => 404]), 'Invalid API key (404)'],
            'forbidden with detail' => [self::ping('forbidden', ['status' => 403, 'detail' => 'Subscription inactive']), 'Access denied (403): Subscription inactive.'],
            'forbidden' => [self::ping('forbidden', ['status' => 403]), 'Access denied (403). Your subscription'],
            'rate limited' => [self::ping('rate_limited', ['status' => 429]), 'monthly event quota is used up (429)'],
            'invalid path' => [self::ping('invalid_path', ['status' => 422]), 'rejected the test path (422)'],
            'unreachable' => [self::ping('unreachable', ['detail' => 'Connection timed out']), 'Could not reach the no404 server: Connection timed out.'],
            'server error' => [self::ping('server_error', ['status' => 502]), 'temporary error (5xx)'],
            'unexpected' => [self::ping('unexpected', ['status' => 418]), 'Unexpected response (HTTP 418).'],
        ];
    }

    #[DataProvider('messageCases')]
    public function testEveryPingCodeHasItsOwnMessage(array $result, $expected)
    {
        $translator = self::translator();

        $this->assertStringContainsString($expected, ConnectionMessage::describe($result, $translator));
        $this->assertSame(['Modules.No404.Admin'], array_unique($translator->domains));
    }

    public function testARedirectNamesOnlySchemeAndHost()
    {
        $message = ConnectionMessage::describe(
            self::ping('redirected', ['status' => 302, 'detail' => 'https://WWW.no404.tr/api/v1/resolve/SECRETKEY?path=/x']),
            self::translator()
        );

        $this->assertStringContainsString('Enter https://www.no404.tr in the "no404 address" field', $message);
        $this->assertStringNotContainsString('SECRETKEY', $message, 'the key never reaches the screen');
    }

    public function testARedirectWithoutAUsableLocation()
    {
        $message = ConnectionMessage::describe(self::ping('redirected', ['detail' => 'javascript:x']), self::translator());

        $this->assertStringContainsString('usually the www form of the domain', $message);
    }

    public function testApiBaseFromLocationKeepsThePort()
    {
        $this->assertSame('http://localhost:8404', ConnectionMessage::apiBaseFromLocation('http://localhost:8404/api/v1/resolve?path=/x'));
        $this->assertSame('', ConnectionMessage::apiBaseFromLocation('/relative'));
    }

    // === StatusReport ===

    private static function settings(array $stored)
    {
        return new SettingsReader(static function ($key) use ($stored) {
            return array_key_exists($key, $stored) ? $stored[$key] : false;
        });
    }

    private static function env(array $overrides = [])
    {
        return array_merge(['curl' => true, 'rewriting' => true, 'cache_usable' => true, 'last_error' => null, 'breaker_open' => false], $overrides);
    }

    private static function codes(array $items)
    {
        return array_map(static function ($item) {
            return $item['level'] . ':' . $item['code'];
        }, $items);
    }

    public function testAHealthyShopIsActive()
    {
        $items = StatusReport::build(self::settings([Keys::API_KEY => 'k']), self::env());

        $this->assertSame(['success:active'], self::codes($items));
    }

    public function testWithoutAKeyItSaysSo()
    {
        $this->assertSame(['danger:no_key'], self::codes(StatusReport::build(self::settings([]), self::env())));
    }

    public function testEveryBlockingStateIsListed()
    {
        $items = StatusReport::build(
            self::settings([Keys::ENABLED => '0']),
            self::env(['curl' => false, 'rewriting' => false, 'cache_usable' => false])
        );

        $this->assertSame(
            ['danger:no_curl', 'danger:no_key', 'warning:disabled', 'warning:rewriting_off', 'warning:cache_unwritable'],
            self::codes($items)
        );
    }

    public function testAnUnwritableCacheStillCountsAsActive()
    {
        $items = StatusReport::build(self::settings([Keys::API_KEY => 'k']), self::env(['cache_usable' => false]));

        $this->assertSame(['success:active', 'warning:cache_unwritable'], self::codes($items));
    }

    public function testMissingApcuIsAWarningThatKeepsItActive()
    {
        $items = StatusReport::build(self::settings([Keys::API_KEY => 'k', Keys::CACHE_BACKEND => 'apcu']), self::env(['apcu' => false]));
        $this->assertSame(['success:active', 'warning:apcu_unavailable'], self::codes($items));

        $items = StatusReport::build(self::settings([Keys::API_KEY => 'k', Keys::CACHE_BACKEND => 'apcu']), self::env(['apcu' => true]));
        $this->assertSame(['success:active'], self::codes($items));
    }

    public function testTheLastErrorIsShownWithItsTime()
    {
        $items = StatusReport::build(
            self::settings([Keys::API_KEY => 'k']),
            self::env(['last_error' => ['kind' => 'invalid_key', 'at' => 1757600000], 'breaker_open' => true])
        );

        $this->assertSame(['danger:last_error_invalid_key'], self::codes($items), 'the error explains the pause; no separate breaker box');
        $this->assertSame(1757600000, $items[0]['params']['at']);
    }

    public function testAnOpenBreakerWithoutARecordedErrorIsExplained()
    {
        $items = StatusReport::build(self::settings([Keys::API_KEY => 'k']), self::env(['breaker_open' => true]));

        $this->assertSame(['warning:breaker_open'], self::codes($items));
    }

    public function testFriendlyUrlsOffIsNotActive()
    {
        $items = StatusReport::build(self::settings([Keys::API_KEY => 'k']), self::env(['rewriting' => false]));

        $this->assertSame(['warning:rewriting_off'], self::codes($items));
    }
}

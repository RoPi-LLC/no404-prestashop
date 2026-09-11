<?php

namespace PrestaShop\Module\No404\Tests;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\No404\Settings\Keys;
use PrestaShop\Module\No404\Settings\SettingsReader;

final class SettingsReaderTest extends TestCase
{
    private static function reader(array $stored)
    {
        return new SettingsReader(static function ($key) use ($stored) {
            // Configuration::get() returns false for a missing key.
            return array_key_exists($key, $stored) ? $stored[$key] : false;
        });
    }

    public function testDefaultsWhenNothingIsStored()
    {
        $settings = self::reader([]);

        $this->assertTrue($settings->isEnabled());
        $this->assertFalse($settings->isOperable(), 'no key, not operable');
        $this->assertSame(Keys::DEFAULT_API_BASE, $settings->apiBase());
        $this->assertSame('', $settings->apiKey());
        $this->assertSame('', $settings->maskedKey());
        $this->assertFalse($settings->force301());
        $this->assertSame(3600, $settings->cacheTtl());
        $this->assertSame(1500, $settings->timeoutMs());
        $this->assertSame([], $settings->excludedPrefixes());
        $this->assertFalse($settings->debug());
        $this->assertFalse($settings->catchAll());
        $this->assertSame(Keys::BACKEND_FILE, $settings->cacheBackend());
        $this->assertSame('', $settings->cacheDirectory());
    }

    public function testCacheSettingsAreRead()
    {
        $settings = self::reader([Keys::CATCH_ALL => '1', Keys::CACHE_BACKEND => 'apcu', Keys::CACHE_DIR => '/srv/cache/']);

        $this->assertTrue($settings->catchAll());
        $this->assertSame(Keys::BACKEND_APCU, $settings->cacheBackend());
        $this->assertSame('/srv/cache', $settings->cacheDirectory());
    }

    public function testBrokenCacheSettingsFallBack()
    {
        $settings = self::reader([Keys::CACHE_BACKEND => 'redis', Keys::CACHE_DIR => '../relative']);

        $this->assertSame(Keys::BACKEND_FILE, $settings->cacheBackend());
        $this->assertSame('', $settings->cacheDirectory());
    }

    public function testStoredValuesAreRead()
    {
        $settings = self::reader([
            Keys::ENABLED => '1',
            Keys::API_KEY => 'abcDEF123_-xyz9',
            Keys::FORCE_301 => '1',
            Keys::CACHE_TTL => '7200',
            Keys::TIMEOUT_MS => '800',
            Keys::DEBUG => '1',
        ]);

        $this->assertTrue($settings->isOperable());
        $this->assertSame('abcDEF123_-xyz9', $settings->apiKey());
        $this->assertSame(SettingsReader::MASK . 'xyz9', $settings->maskedKey());
        $this->assertTrue($settings->force301());
        $this->assertSame(7200, $settings->cacheTtl());
        $this->assertSame(800, $settings->timeoutMs());
        $this->assertTrue($settings->debug());
    }

    public function testTheMasterSwitchWins()
    {
        $settings = self::reader([Keys::ENABLED => '0', Keys::API_KEY => 'key']);

        $this->assertFalse($settings->isEnabled());
        $this->assertFalse($settings->isOperable());
    }

    public function testRangesAreClamped()
    {
        $low = self::reader([Keys::CACHE_TTL => '5', Keys::TIMEOUT_MS => '10']);
        $high = self::reader([Keys::CACHE_TTL => '99999999', Keys::TIMEOUT_MS => '9000']);
        $junk = self::reader([Keys::CACHE_TTL => 'abc', Keys::TIMEOUT_MS => '']);

        $this->assertSame(60, $low->cacheTtl());
        $this->assertSame(300, $low->timeoutMs());
        $this->assertSame(604800, $high->cacheTtl());
        $this->assertSame(1500, $high->timeoutMs(), 'the spec caps the timeout at 1500 ms');
        $this->assertSame(3600, $junk->cacheTtl());
        $this->assertSame(1500, $junk->timeoutMs());
    }

    public function testTheBareDomainIsRewrittenToTheCanonicalHost()
    {
        $this->assertSame('https://www.no404.tr', self::reader([Keys::API_BASE => 'https://no404.tr/'])->apiBase());
    }

    public function testASelfHostedAddressIsKept()
    {
        $this->assertSame('https://no404.internal.example', self::reader([Keys::API_BASE => 'https://no404.internal.example/'])->apiBase());
    }

    public function testAnInvalidAddressFallsBackToTheDefault()
    {
        $this->assertSame(Keys::DEFAULT_API_BASE, self::reader([Keys::API_BASE => 'ftp://x.example'])->apiBase());
        $this->assertSame(Keys::DEFAULT_API_BASE, self::reader([Keys::API_BASE => 'not a url'])->apiBase());
        $this->assertSame(Keys::DEFAULT_API_BASE, self::reader([Keys::API_BASE => ''])->apiBase());
    }

    public function testTheKeyIsReducedToKeyCharacters()
    {
        $this->assertSame('abcscript123', self::reader([Keys::API_KEY => " abc<script>123\r\n"])->apiKey());
    }

    public function testExcludedPathsAreParsed()
    {
        $settings = self::reader([Keys::EXCLUDED_PATHS => "/promo/\r\nold-campaign\n\n  /tmp , /promo"]);

        $this->assertSame(['/promo', '/old-campaign', '/tmp'], $settings->excludedPrefixes());
    }

    public function testAFailingGetterNeverBreaksReading()
    {
        $settings = new SettingsReader(static function () {
            throw new \RuntimeException('database down');
        });

        $this->assertFalse($settings->isOperable());
        $this->assertSame(Keys::DEFAULT_API_BASE, $settings->apiBase());
    }

    public function testEveryDefaultKeyIsCleanedUpOnUninstall()
    {
        foreach (array_keys(Keys::defaults()) as $key) {
            $this->assertContains($key, Keys::all());
        }
        $this->assertContains(Keys::LAST_ERROR, Keys::all());
    }
}

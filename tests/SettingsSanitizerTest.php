<?php

namespace PrestaShop\Module\No404\Tests;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\No404\Settings\Keys;
use PrestaShop\Module\No404\Settings\SettingsReader;
use PrestaShop\Module\No404\Settings\SettingsSanitizer;

final class SettingsSanitizerTest extends TestCase
{
    public function testAnEmptyAddressRestoresTheDefault()
    {
        $this->assertSame(['value' => Keys::DEFAULT_API_BASE, 'valid' => true], SettingsSanitizer::apiBase('  ', 'https://self.example'));
    }

    public function testTheBareDomainIsSavedAsTheCanonicalHost()
    {
        $this->assertSame(['value' => 'https://www.no404.tr', 'valid' => true], SettingsSanitizer::apiBase('https://no404.tr/', Keys::DEFAULT_API_BASE));
    }

    public function testASelfHostedAddressIsSavedWithoutTrailingSlash()
    {
        $this->assertSame(['value' => 'https://no404.internal.example', 'valid' => true], SettingsSanitizer::apiBase('https://no404.internal.example/', Keys::DEFAULT_API_BASE));
    }

    public function testAnInvalidAddressKeepsThePreviousValue()
    {
        foreach (['ftp://x.example', 'javascript:alert(1)', 'not a url', "https://x.example/\r\nX: 1"] as $raw) {
            $this->assertSame(['value' => 'https://self.example', 'valid' => false], SettingsSanitizer::apiBase($raw, 'https://self.example'), $raw);
        }
    }

    public function testAnEmptyOrMaskedKeyKeepsTheStoredKey()
    {
        $this->assertNull(SettingsSanitizer::apiKey(''));
        $this->assertNull(SettingsSanitizer::apiKey('   '));
        $this->assertNull(SettingsSanitizer::apiKey(SettingsReader::MASK . 'abcd'));
        $this->assertNull(SettingsSanitizer::apiKey('<>!!'), 'nothing usable left after cleaning');
    }

    public function testANewKeyIsCleaned()
    {
        $this->assertSame('abc-DEF_123', SettingsSanitizer::apiKey(" abc-DEF_123\n"));
        $this->assertSame('abcscript', SettingsSanitizer::apiKey('abc<script>'));
    }

    public function testNumbersAreClamped()
    {
        $this->assertSame(60, SettingsSanitizer::cacheTtl('1'));
        $this->assertSame(604800, SettingsSanitizer::cacheTtl(99999999));
        $this->assertSame(3600, SettingsSanitizer::cacheTtl(null));
        $this->assertSame(300, SettingsSanitizer::timeoutMs(0));
        $this->assertSame(1500, SettingsSanitizer::timeoutMs('5000'));
        $this->assertSame(1500, SettingsSanitizer::timeoutMs('abc'));
    }

    public function testExcludedPathsAreNormalised()
    {
        $this->assertSame(
            "/promo\n/old-campaign\n/tmp",
            SettingsSanitizer::excludedPaths("/promo/\r\nold-campaign\n\n  /tmp , /promo\n<b>/</b>")
        );
        $this->assertSame('', SettingsSanitizer::excludedPaths(null));
    }

    public function testTheExcludedListIsBounded()
    {
        $raw = implode("\n", array_map(static function ($i) {
            return '/p' . $i;
        }, range(1, 500)));

        $this->assertCount(SettingsSanitizer::MAX_EXCLUDED_LINES, explode("\n", SettingsSanitizer::excludedPaths($raw)));
    }

    public function testCacheBackendIsFileUnlessApcuIsChosen()
    {
        $this->assertSame(Keys::BACKEND_APCU, SettingsSanitizer::cacheBackend('apcu'));
        $this->assertSame(Keys::BACKEND_FILE, SettingsSanitizer::cacheBackend('file'));
        $this->assertSame(Keys::BACKEND_FILE, SettingsSanitizer::cacheBackend('redis'));
        $this->assertSame(Keys::BACKEND_FILE, SettingsSanitizer::cacheBackend(null));
    }

    public function testCacheDirectoryMustBeAbsoluteAndClean()
    {
        $this->assertSame('', SettingsSanitizer::cacheDirectory('  '));
        $this->assertSame('/srv/cache', SettingsSanitizer::cacheDirectory('/srv/cache/'));
        $this->assertSame('C:\\cache', SettingsSanitizer::cacheDirectory('C:\\cache\\'));
        $this->assertSame('/', SettingsSanitizer::cacheDirectory('/'));
        $this->assertNull(SettingsSanitizer::cacheDirectory('relative/dir'));
        $this->assertNull(SettingsSanitizer::cacheDirectory('/srv/../etc'));
        $this->assertNull(SettingsSanitizer::cacheDirectory("/srv/ca\nche"), 'a control character inside the path');
        $this->assertSame('/srv/cache', SettingsSanitizer::cacheDirectory("/srv/cache\n"), 'surrounding whitespace is trimmed');
    }

    public function testWhatIsSavedReadsBackTheSame()
    {
        $stored = SettingsSanitizer::excludedPaths("promo/\n/x");
        $reader = new SettingsReader(static function ($key) use ($stored) {
            return Keys::EXCLUDED_PATHS === $key ? $stored : false;
        });

        $this->assertSame($stored, $reader->excludedPathsText());
    }
}

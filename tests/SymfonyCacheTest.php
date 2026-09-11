<?php

namespace PrestaShop\Module\No404\Tests;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\No404\Adapter\NullCache;
use PrestaShop\Module\No404\Adapter\SymfonyCache;
use PrestaShop\Module\No404\Core\Client;
use PrestaShop\Module\No404\Tests\Support\FakeHttp;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SymfonyCacheTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'no404-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    private static function remove($path)
    {
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::remove($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    public function testTheDirectoryIsCreatedWhenMissing()
    {
        $this->assertTrue(SymfonyCache::isUsable($this->dir));
        $this->assertDirectoryExists($this->dir);
    }

    public function testValuesRoundTrip()
    {
        $cache = SymfonyCache::filesystem('shop1', $this->dir);
        $result = ['found' => true, 'redirect' => '/x', 'score' => 0.0, 'source' => 'CATALOG', 'redirect_status' => 0];

        $cache->set('v1:abcd1234:' . md5('/old'), $result, 60);
        $cache->set(Client::OUTAGE_KEY, 1, 60);

        $this->assertSame($result['redirect'], $cache->get('v1:abcd1234:' . md5('/old'))['redirect']);
        $this->assertSame(1, $cache->get(Client::OUTAGE_KEY));
        $this->assertNull($cache->get('missing'));
    }

    public function testKeysWithReservedCharactersWork()
    {
        $cache = SymfonyCache::filesystem('shop1', $this->dir);
        $cache->set('a:b/c{d}(e)@f\\g', 'value', 60);

        $this->assertSame('value', $cache->get('a:b/c{d}(e)@f\\g'));
    }

    public function testShopsAreIsolated()
    {
        $one = SymfonyCache::filesystem('shop1', $this->dir);
        $two = SymfonyCache::filesystem('shop2', $this->dir);

        $one->set(Client::OUTAGE_KEY, 1, 300);

        $this->assertSame(1, $one->get(Client::OUTAGE_KEY));
        $this->assertNull($two->get(Client::OUTAGE_KEY), "one shop's breaker does not silence another");
    }

    public function testFlushClearsOnlyItsOwnShop()
    {
        $one = SymfonyCache::filesystem('shop1', $this->dir);
        $two = SymfonyCache::filesystem('shop2', $this->dir);
        $one->set('k', 'a', 60);
        $two->set('k', 'b', 60);

        $one->flush();

        $this->assertNull($one->get('k'));
        $this->assertSame('b', $two->get('k'));
    }

    public function testItProtectsTheQuotaAcrossRequests()
    {
        $http = new FakeHttp();
        $http->queue = [FakeHttp::ok('{"success":true,"found":true,"redirect":"/new","score":0.9,"source":"CATALOG"}')];
        $config = ['api_base' => 'https://www.no404.tr', 'api_key' => 'k'];

        // Two separate client instances = two separate PHP requests sharing the file cache.
        (new Client($config, $http, SymfonyCache::filesystem('shop1', $this->dir)))->resolve('/old');
        $second = (new Client($config, $http, SymfonyCache::filesystem('shop1', $this->dir)))->resolve('/old');

        $this->assertSame(1, $http->calls);
        $this->assertSame('/new', $second['redirect']);
    }

    public function testAnyPsr6PoolCanBackIt()
    {
        $cache = new SymfonyCache(new ArrayAdapter());
        $cache->set('k', ['a' => 1], 60);

        $this->assertSame(['a' => 1], $cache->get('k'));
    }

    public function testApcuChainWhenAvailable()
    {
        if (!SymfonyCache::isApcuAvailable()) {
            $this->markTestSkipped('APCu is not enabled for this SAPI.');
        }

        $cache = SymfonyCache::apcuThenFilesystem('no404.test.' . bin2hex(random_bytes(3)), 'shop1', $this->dir);
        $cache->set('k', 'v', 60);

        $this->assertSame('v', $cache->get('k'));
        $this->assertSame('v', SymfonyCache::filesystem('shop1', $this->dir)->get('k'), 'files hold the entry too');
    }

    public function testNullCacheRemembersNothing()
    {
        $cache = new NullCache();
        $cache->set('k', 'v', 60);

        $this->assertNull($cache->get('k'));
    }
}

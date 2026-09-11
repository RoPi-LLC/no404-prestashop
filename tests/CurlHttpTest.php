<?php

namespace PrestaShop\Module\No404\Tests;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\No404\Adapter\CurlHttp;
use PrestaShop\Module\No404\Core\Client;
use PrestaShop\Module\No404\Tests\Support\FakeCache;

/**
 * The real cURL transport against tests/fake-api/router.php served by PHP's
 * built-in web server. Measures, rather than assumes, timeouts and call counts.
 */
final class CurlHttpTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    /** @var string */
    private static $base = '';

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('curl_init')) {
            return;
        }

        $port = self::freePort();
        $router = __DIR__ . DIRECTORY_SEPARATOR . 'fake-api' . DIRECTORY_SEPARATOR . 'router.php';
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router];
        $null = 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null';
        self::$server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        self::$base = 'http://127.0.0.1:' . $port;

        // Wait until it accepts connections.
        for ($i = 0; $i < 50; ++$i) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (false !== $socket) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    protected function setUp(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('ext-curl is not available.');
        }
        self::request('DELETE', '/__count');
    }

    private static function freePort()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function request($method, $path)
    {
        $handle = curl_init(self::$base . $path);
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($handle);

        return json_decode((string) $body, true);
    }

    private static function apiCalls()
    {
        return (int) self::request('GET', '/__count')['count'];
    }

    private static function resolveUrl($path)
    {
        return self::$base . '/api/v1/resolve?path=' . rawurlencode($path);
    }

    public function testTheKeyTravelsInTheAuthorizationHeader()
    {
        $response = (new CurlHttp())->get(self::resolveUrl('/echo'), 1500, "no404-prestashop/1.0.0; https://shop.example/\r\nX-Evil: 1", ['Authorization' => 'Bearer testkey123']);

        $this->assertTrue($response['ok']);
        $this->assertSame(200, $response['status']);
        $echo = json_decode($response['body'], true)['echo'];
        $this->assertSame('Bearer testkey123', $echo['authorization']);
        $this->assertSame('application/json', $echo['accept']);
        $this->assertSame('no404-prestashop/1.0.0; https://shop.example/X-Evil: 1', $echo['user_agent'], 'CR/LF cannot inject a header');
        $this->assertStringNotContainsString('testkey123', $echo['query']);
    }

    public function testErrorStatusesAreResponsesNotFailures()
    {
        $response = (new CurlHttp())->get(self::resolveUrl('/status/429'), 1500, 'ua');

        $this->assertTrue($response['ok']);
        $this->assertSame(429, $response['status']);
    }

    public function testRedirectsAreNotFollowedAndTheLocationIsKept()
    {
        $response = (new CurlHttp())->get(self::resolveUrl('/moved'), 1500, 'ua');

        $this->assertSame(302, $response['status']);
        $this->assertSame('https://www.no404.tr/api/v1/resolve?path=%2Fmoved', $response['location']);
    }

    public function testASlowServerIsCutOffAtTheTimeout()
    {
        $start = microtime(true);
        $response = (new CurlHttp())->get(self::resolveUrl('/slow'), 500, 'ua');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertFalse($response['ok']);
        $this->assertSame(0, $response['status']);
        $this->assertLessThan(1500, $elapsedMs, 'the 404 page is not held up');
    }

    public function testAnUnreachableHostFailsQuietly()
    {
        // Port 9 (discard) on localhost: connection refused.
        $response = (new CurlHttp())->get('http://127.0.0.1:9/api/v1/resolve?path=%2Fx', 500, 'ua');

        $this->assertFalse($response['ok']);
        $this->assertNotSame('', $response['error']);
    }

    public function testEndToEndTheCacheProtectsTheQuota()
    {
        $client = new Client(['api_base' => self::$base, 'api_key' => 'k', 'allowed_hosts' => ['shop.example']], new CurlHttp(), new FakeCache());

        $first = $client->resolve('/match');
        $client->resolve('/match');
        $client->resolve('/match?utm_source=x');

        $this->assertSame('/new-product', $first['redirect']);
        $this->assertSame(301, $client->decideStatus($first));
        $this->assertSame(1, self::apiCalls(), 'the fake API saw exactly one request');
    }

    public function testEndToEndTheBreakerStopsLookupsAfterAnOutage()
    {
        $client = new Client(['api_base' => self::$base, 'api_key' => 'k'], new CurlHttp(), new FakeCache());

        $client->resolve('/status/500');
        $client->resolve('/another');
        $client->resolve('/and-another');

        $this->assertSame(1, self::apiCalls());
    }

    public function testConnectTimeoutBudget()
    {
        $this->assertSame(700, CurlHttp::connectTimeout(1500));
        $this->assertSame(300, CurlHttp::connectTimeout(300));
        $this->assertSame(2500, CurlHttp::connectTimeout(5000));
        $this->assertSame(3000, CurlHttp::connectTimeout(10000));
    }
}

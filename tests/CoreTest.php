<?php

namespace PrestaShop\Module\No404\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\No404\Core\Client;
use PrestaShop\Module\No404\Tests\Support\FakeCache;
use PrestaShop\Module\No404\Tests\Support\FakeHttp;

/**
 * Port of the WordPress plugin's tests/test-core.php — every scenario, same
 * inputs, same expectations. If one of these has to change, the two platforms
 * no longer behave the same: change the WP core too.
 */
final class CoreTest extends TestCase
{
    private const HIT = '{"success":true,"found":true,"redirect":"https://store.example/new","score":0.9,"source":"CATALOG"}';

    private static function client(FakeHttp $http, FakeCache $cache, array $extra = [])
    {
        return new Client(
            array_merge(
                [
                    'api_base' => 'https://no404.tr',
                    'api_key' => 'testkey123',
                    'allowed_hosts' => ['store.example', 'www.store.example'],
                    'ignored_prefixes' => ['/wp-admin', '/wp-json'],
                ],
                $extra
            ),
            $http,
            $cache
        );
    }

    private static function plain()
    {
        return self::client(new FakeHttp(), new FakeCache());
    }

    // === normalizePath (must match cleanPath on the server) ===

    public static function normalizeCases()
    {
        return [
            'trailing slash is dropped' => ['/product/gold-ring/', '/product/gold-ring'],
            'double slash collapses' => ['//product//x', '/product/x'],
            'query string is dropped' => ['/product?utm_source=google', '/product'],
            'fragment is dropped' => ['/product#section', '/product'],
            'full URL reduces to a path' => ['https://store.example/product/x', '/product/x'],
            'backslash is normalised' => ['\\product\\x', '/product/x'],
            'root is preserved' => ['/', '/'],
            'newlines are stripped' => ["/product\r\n", '/product'],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function testNormalizePath($input, $expected)
    {
        $this->assertSame($expected, self::plain()->normalizePath($input));
    }

    // === isIgnoredPath (quota protection) ===

    public static function ignoredCases()
    {
        return [
            'css is skipped' => ['/tema/style.css', true],
            'png is skipped' => ['/gorsel/foto.PNG', true],
            'wp-admin is skipped' => ['/wp-admin/edit.php', true],
            'wp-json is skipped' => ['/wp-json/wp/v2/posts', true],
            'well-known is skipped' => ['/.well-known/acme', true],
            'a real product is not skipped' => ['/14-gram-gold-ring-102', false],
            'a slug containing a dot is not skipped' => ['/product/3.5-mm-cable', false],
            'prefix does not match by accident' => ['/wp-administration-guide', false],
        ];
    }

    #[DataProvider('ignoredCases')]
    public function testIsIgnoredPath($path, $expected)
    {
        $this->assertSame($expected, self::plain()->isIgnoredPath($path));
    }

    // === decideStatus (the 301/302 decision) ===

    public static function statusCases()
    {
        return [
            'REDIRECT -> 301' => [['source' => 'REDIRECT', 'score' => 1.0], false, 301],
            'CATALOG high score -> 301' => [['source' => 'CATALOG', 'score' => 0.92], false, 301],
            'CATALOG exactly at the 0.5 threshold -> 301' => [['source' => 'CATALOG', 'score' => 0.5], false, 301],
            'CATALOG low score -> 302' => [['source' => 'CATALOG', 'score' => 0.42], false, 302],
            'FALLBACK -> 302' => [['source' => 'FALLBACK', 'score' => 0.0], false, 302],
            'FALLBACK -> 301 when force_301 is on' => [['source' => 'FALLBACK', 'score' => 0.0], true, 301],
            // The API's redirectStatus (panel threshold).
            'API 302 wins over a high CATALOG score' => [['source' => 'CATALOG', 'score' => 0.92, 'redirect_status' => 302], false, 302],
            'API 301 wins over a low CATALOG score' => [['source' => 'CATALOG', 'score' => 0.42, 'redirect_status' => 301], false, 301],
            'an invalid redirectStatus falls back to the local rule' => [['source' => 'CATALOG', 'score' => 0.42, 'redirect_status' => 307], false, 302],
            'a missing redirectStatus (older API) keeps the local rule' => [['source' => 'CATALOG', 'score' => 0.92], false, 301],
            'force_301 still wins over the API' => [['source' => 'CATALOG', 'score' => 0.42, 'redirect_status' => 302], true, 301],
        ];
    }

    #[DataProvider('statusCases')]
    public function testDecideStatus(array $result, $force301, $expected)
    {
        $client = self::client(new FakeHttp(), new FakeCache(), ['force_301' => $force301]);
        $this->assertSame($expected, $client->decideStatus($result));
    }

    // === validateTarget (open redirect + loop protection) ===

    public static function targetCases()
    {
        return [
            'allowed host passes' => ['https://store.example/new', 'https://store.example/new'],
            'foreign host is rejected' => ['https://evil.com/x', ''],
            'protocol-relative is rejected' => ['//evil.com/x', ''],
            'javascript: is rejected' => ['javascript:alert(1)', ''],
            'data: is rejected' => ['data:text/html,x', ''],
            'relative target passes' => ['/new-product', '/new-product'],
            'LOOP: same path is rejected' => ['https://store.example/old', ''],
            'LOOP: same relative path is rejected' => ['/old', ''],
            'LOOP: a trailing-slash difference is still a loop' => ['https://store.example/old/', ''],
            'header injection is rejected' => ["https://store.example/x\r\nX-Evil: 1", ''],
            'empty target is rejected' => ['', ''],
        ];
    }

    #[DataProvider('targetCases')]
    public function testValidateTarget($target, $expected)
    {
        $this->assertSame($expected, self::plain()->validateTarget($target, '/old'));
    }

    // === resolve ===

    public function testTheCacheProtectsTheQuota()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());
        $http->queue = [FakeHttp::ok(self::HIT)];

        $client->resolve('/old-product');
        $r2 = $client->resolve('/old-product');
        $r3 = $client->resolve('/old-product/');         // normalised -> same key
        $r4 = $client->resolve('/old-product?utm=abc');  // query dropped -> same key

        $this->assertSame(1, $http->calls, 'ONE API call for the same path');
        $this->assertSame('https://store.example/new', $r2['redirect'], 'second call comes from the cache');
        $this->assertSame('https://store.example/new', $r3['redirect'], 'trailing slash does not split the cache');
        $this->assertSame('https://store.example/new', $r4['redirect'], 'a utm parameter does not split the cache');
        $this->assertSame(Client::LOOKUP_CACHE, $client->lastLookup());
    }

    public function testNegativeResultsAreCachedToo()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());
        $http->queue = [
            FakeHttp::ok('{"success":true,"found":false,"redirect":null,"score":0,"source":"NONE"}'),
            FakeHttp::ok('{"success":true,"found":true,"redirect":"https://store.example/x","score":0.9,"source":"CATALOG"}'),
        ];

        $client->resolve('/nothing-here');
        $client->resolve('/nothing-here');
        $client->resolve('/nothing-here');

        $this->assertSame(1, $http->calls, 'an unmatched path is not asked about again');
    }

    public function testAStaticFileIsNeverSentToTheApi()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());

        $client->resolve('/tema/style.css');
        $client->resolve('/wp-admin/x');
        $client->resolve('/gorsel/a.jpg');

        $this->assertSame(0, $http->calls);
        $this->assertSame(Client::LOOKUP_SKIPPED, $client->lastLookup());
    }

    public function testFailOpenWhenNo404IsDown()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());
        $http->queue = [FakeHttp::transportError()];

        $this->assertNull($client->resolve('/old-product'), 'timeout -> null (no redirect)');
        $r = $client->resolve('/another-product');
        $this->assertSame(1, $http->calls, 'CIRCUIT BREAKER: the second 404 does not reach the API');
        $this->assertNull($r, 'result is null while the breaker is open');
        $this->assertSame(Client::LOOKUP_CIRCUIT, $client->lastLookup());
    }

    public static function errorStatuses()
    {
        return [
            '404' => [404, Client::CONFIG_ERROR_TTL],
            '403' => [403, Client::CONFIG_ERROR_TTL],
            '429' => [429, Client::QUOTA_TTL],
            '500' => [500, Client::OUTAGE_TTL],
        ];
    }

    #[DataProvider('errorStatuses')]
    public function testHttpErrorCodes($status, $breakerTtl)
    {
        $http = new FakeHttp();
        $cache = new FakeCache();
        $client = self::client($http, $cache);
        $http->queue = [FakeHttp::status($status)];

        $this->assertNull($client->resolve('/old'));
        $this->assertSame($breakerTtl, $cache->ttls[Client::OUTAGE_KEY] ?? null, 'the breaker waits as long as the spec says');
        $this->assertSame($status, $client->lastStatus());
    }

    public function testA422IsNegativelyCachedWithoutTrippingTheBreaker()
    {
        $http = new FakeHttp();
        $cache = new FakeCache();
        $client = self::client($http, $cache);
        $http->queue = [FakeHttp::status(422)];

        $this->assertNull($client->resolve('/old'));
        $this->assertArrayNotHasKey(Client::OUTAGE_KEY, $cache->store);
        $client->resolve('/old');
        $this->assertSame(1, $http->calls);
    }

    public function testAMalformedResponseIsSwallowed()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());
        $http->queue = [FakeHttp::ok('<html>this is not JSON</html>')];
        $this->assertNull($client->resolve('/old'), 'HTML body -> null');

        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());
        $http->queue = [FakeHttp::ok('{"success":true}')];
        $r = $client->resolve('/old');
        $this->assertTrue(is_array($r) && null === $r['redirect'], 'missing fields do not crash it');
    }

    public function testRequestUrlConstruction()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());
        $client->resolve('/14-gram-altin-yüzük', 'https://google.com/search?q=x');

        $this->assertSame(
            'https://no404.tr/api/v1/resolve?path=' . rawurlencode('/14-gram-altin-yüzük') . '&ref=' . rawurlencode('https://google.com/search?q=x'),
            $http->lastUrl
        );
        $this->assertSame(['Authorization' => 'Bearer testkey123'], $http->lastHeaders, 'the API key travels in the Authorization header');
        $this->assertStringNotContainsString('testkey123', $http->lastUrl, 'the API key is NOT in the URL');
    }

    // === Visitor data: truncated IP + UA, never the full IP ===

    public static function truncateIpCases()
    {
        return [
            'IPv4 -> last octet zeroed' => ['203.0.113.45', '203.0.113.0'],
            'IPv6 -> first 48 bits' => ['2001:db8:1c1c:abcd::1', '2001:db8:1c1c::'],
            'IPv4-mapped IPv6 -> IPv4' => ['::ffff:203.0.113.45', '203.0.113.0'],
            'garbage -> empty' => ['not-an-ip', ''],
        ];
    }

    #[DataProvider('truncateIpCases')]
    public function testTruncateIp($ip, $expected)
    {
        $this->assertSame($expected, self::plain()->truncateIp($ip));
    }

    public function testVisitorHeadersAreSentNextToTheKey()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());

        $client->resolve('/visitor', '', '', ['ip' => '203.0.113.45', 'user_agent' => "Mozilla/5.0\r\nX-Evil: 1", 'country' => 'tr']);
        $this->assertSame(
            [
                'Authorization' => 'Bearer testkey123',
                'X-No404-Visitor-IP' => '203.0.113.0',
                'X-No404-Visitor-UA' => 'Mozilla/5.0X-Evil: 1',
                'X-No404-Visitor-Country' => 'TR',
            ],
            $http->lastHeaders
        );
        $this->assertStringNotContainsString('203.0.113.45', var_export($http->lastHeaders, true) . $http->lastUrl, 'the full IP never leaves the store');

        $client->resolve('/visitor-2', '', '', ['ip' => 'bad', 'country' => 'XX']);
        $this->assertSame(['Authorization' => 'Bearer testkey123'], $http->lastHeaders, 'invalid visitor values are dropped');

        $client->resolve('/visitor-3', '', '', ['user_agent' => str_repeat('a', 600)]);
        $this->assertSame(Client::MAX_USER_AGENT_LENGTH, strlen($http->lastHeaders['X-No404-Visitor-UA']), 'a long UA is cut, not dropped');

        $client->ping();
        $this->assertSame(['Authorization' => 'Bearer testkey123'], $http->lastHeaders, 'the connection test sends no visitor data');
    }

    // === Visitor ID: shop-keyed HMAC of the full IP ===

    public function testVisitorId()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache(), ['visitor_secret' => 'site-secret-A']);
        $idA = $client->visitorId('203.0.113.45');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $idA, 'the ID is a 64-character hex HMAC');
        $this->assertSame($idA, $client->visitorId('203.0.113.45'), 'same IP -> same ID (unique counts work)');
        $this->assertSame($idA, $client->visitorId('::ffff:203.0.113.45'), 'IPv4-mapped spelling -> same ID');
        $this->assertSame($client->visitorId('2001:0DB8:0:0:0:0:0:1'), $client->visitorId('2001:db8::1'), 'two spellings of one IPv6 -> same ID');
        $this->assertNotSame($idA, $client->visitorId('203.0.113.46'), 'a neighbour in the same /24 -> a different ID');
        $this->assertNotSame(
            $idA,
            self::client(new FakeHttp(), new FakeCache(), ['visitor_secret' => 'site-secret-B'])->visitorId('203.0.113.45'),
            'another shop (another secret) -> a different ID'
        );
        $this->assertNotSame(hash('sha256', '203.0.113.45'), $idA, 'it is not a plain, brute-forceable hash of the IP');
        $this->assertNotSame(hash('sha256', (string) inet_pton('203.0.113.45')), $idA);
        $this->assertSame('', self::plain()->visitorId('203.0.113.45'), 'no secret -> no ID');
        $this->assertSame('', $client->visitorId('not-an-ip'), 'garbage -> no ID');

        $client->resolve('/visitor-id', '', '', ['ip' => '203.0.113.45']);
        $this->assertSame(
            [
                'Authorization' => 'Bearer testkey123',
                'X-No404-Visitor-IP' => '203.0.113.0',
                'X-No404-Visitor-Id' => $idA,
            ],
            $http->lastHeaders,
            'the ID travels next to the truncated IP'
        );
    }

    // === detectAdCategory (only the category leaves the store) ===

    public static function adCases()
    {
        return [
            'gclid -> google' => ['/p?gclid=abc', 'google'],
            'gbraid -> google' => ['/p?gbraid=abc', 'google'],
            'msclkid -> microsoft' => ['/p?msclkid=abc', 'microsoft'],
            'paid utm + facebook -> meta' => ['/p?utm_medium=paid&utm_source=facebook', 'meta'],
            "Meta's site_source_name ig -> meta" => ['/p?utm_source=ig&utm_medium=paid', 'meta'],
            'cpc + google source -> google' => ['/p?utm_medium=CPC&utm_source=google', 'google'],
            'paid + unknown source -> other' => ['/p?utm_medium=cpc&utm_source=newsletter', 'other'],
            'ttclid -> other' => ['/p?ttclid=1', 'other'],
            'fbclid alone is NOT an ad' => ['/p?fbclid=xyz', ''],
            'organic utm is not an ad' => ['/p?utm_medium=email&utm_source=newsletter', ''],
            'no query string -> empty' => ['/p', ''],
            'a click ID in the fragment is ignored' => ['/p#gclid=abc', ''],
        ];
    }

    #[DataProvider('adCases')]
    public function testDetectAdCategory($uri, $expected)
    {
        $this->assertSame($expected, self::plain()->detectAdCategory($uri));
    }

    public function testAnAdClickSkipsTheCacheReadAndSendsOnlyTheCategory()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());
        $http->queue = [FakeHttp::ok(self::HIT), FakeHttp::ok(self::HIT)];

        $client->resolve('/old-product');
        $client->resolve('/old-product', '', 'google');
        $this->assertSame(2, $http->calls, 'an ad click reaches the API even when the path is cached');
        $this->assertStringContainsString('&ad=google', $http->lastUrl, 'only the category is sent');

        $client->resolve('/old-product');
        $this->assertSame(2, $http->calls, 'organic traffic still uses the cache');

        $client->resolve('/old-product', '', 'gclid=abc123');
        $this->assertSame(2, $http->calls, 'an unknown category is dropped and the cache is used');
    }

    public function testAnAdClickFallsBackToTheCacheWhenNo404IsDown()
    {
        $http = new FakeHttp();
        $client = self::client($http, new FakeCache());
        $http->queue = [FakeHttp::ok(self::HIT), FakeHttp::transportError('timeout')];

        $client->resolve('/old-product');
        $r = $client->resolve('/old-product', '', 'meta');

        $this->assertSame('https://store.example/new', is_array($r) ? $r['redirect'] : null, 'the cached redirect is still used');
    }

    public function testAnUnconfiguredClientStaysSilent()
    {
        $http = new FakeHttp();
        $client = new Client(['api_base' => 'https://no404.tr', 'api_key' => ''], $http, new FakeCache());

        $this->assertNull($client->resolve('/old'), 'null when there is no key');
        $this->assertSame(0, $http->calls, 'zero calls when there is no key');
    }

    public function testTheConnectionTestNamesTheAddressBehindARedirect()
    {
        $http = new FakeHttp();
        $http->queue = [
            ['ok' => true, 'status' => 302, 'body' => '', 'error' => '', 'location' => 'https://www.no404.tr/api/v1/resolve?path=/test'],
        ];
        $ping = self::client($http, new FakeCache())->ping('/test');

        $this->assertSame('redirected', $ping['code'], 'a 302 is reported as a redirect, not as unexpected');
        $this->assertSame('https://www.no404.tr/api/v1/resolve?path=/test', $ping['detail'], 'the Location header is carried through');
    }

    // === PrestaShop additions (not in the WP suite) ===

    public static function pingCodes()
    {
        return [
            'ok' => [FakeHttp::ok('{"success":true,"found":false,"redirect":null,"score":0,"source":"NONE"}'), 'ok'],
            'invalid_key' => [FakeHttp::status(404), 'invalid_key'],
            'forbidden' => [FakeHttp::status(403), 'forbidden'],
            'rate_limited' => [FakeHttp::status(429), 'rate_limited'],
            'invalid_path' => [FakeHttp::status(422), 'invalid_path'],
            'server_error' => [FakeHttp::status(502), 'server_error'],
            'unreachable' => [FakeHttp::transportError(), 'unreachable'],
        ];
    }

    #[DataProvider('pingCodes')]
    public function testPingCodes(array $response, $code)
    {
        $http = new FakeHttp();
        $http->queue = [$response];
        $client = self::client($http, new FakeCache());

        $this->assertSame($code, $client->ping()['code']);
        $this->assertSame(5000, $http->lastTimeoutMs, 'the connection test may wait longer than a storefront lookup');
    }

    public function testPingBypassesTheCircuitBreaker()
    {
        $http = new FakeHttp();
        $cache = new FakeCache();
        $cache->set(Client::OUTAGE_KEY, 1, 60);
        $client = self::client($http, $cache);

        $client->ping();
        $this->assertSame(1, $http->calls);
    }

    public function testAChangedApiKeyDoesNotReuseOldEntries()
    {
        $http = new FakeHttp();
        $cache = new FakeCache();
        $http->queue = [FakeHttp::ok(self::HIT), FakeHttp::ok(self::HIT)];

        self::client($http, $cache)->resolve('/old-product');
        self::client($http, $cache, ['api_key' => 'otherkey456'])->resolve('/old-product');

        $this->assertSame(2, $http->calls);
    }

    public function testTheApiRedirectStatusIsCarriedIntoTheResult()
    {
        $http = new FakeHttp();
        $http->queue = [FakeHttp::ok('{"success":true,"found":true,"redirect":"/x","score":0.9,"source":"CATALOG","redirectStatus":302}')];
        $client = self::client($http, new FakeCache());

        $result = $client->resolve('/old');
        $this->assertSame(302, $result['redirect_status']);
        $this->assertSame(302, $client->decideStatus($result));
    }
}

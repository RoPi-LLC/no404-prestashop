<?php

namespace PrestaShop\Module\No404\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\No404\Core\Client;
use PrestaShop\Module\No404\Front\ClientFactory;
use PrestaShop\Module\No404\Front\Outcome;
use PrestaShop\Module\No404\Front\Redirector;
use PrestaShop\Module\No404\Front\RequestContext;
use PrestaShop\Module\No404\Tests\Support\FakeCache;
use PrestaShop\Module\No404\Tests\Support\FakeHttp;
use PrestaShop\Module\No404\Tests\Support\RecordingEmitter;

/**
 * Storefront behaviour at unit level: the core client is
 * real, only the transport, the cache and the response are faked.
 */
final class RedirectorTest extends TestCase
{
    /** @var FakeHttp */
    private $http;

    /** @var FakeCache */
    private $cache;

    /** @var RecordingEmitter */
    private $emitter;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->cache = new FakeCache();
        $this->emitter = new RecordingEmitter();
    }

    private function redirector(array $extra = [], $debug = false, $basePath = '')
    {
        $client = new Client(
            array_merge(
                [
                    'api_base' => 'https://www.no404.tr',
                    'api_key' => 'testkey123',
                    'allowed_hosts' => ['shop.example', 'www.shop.example'],
                    'ignored_prefixes' => array_merge(ClientFactory::CORE_IGNORED_PREFIXES, ['/en/page-not-found']),
                ],
                $extra
            ),
            $this->http,
            $this->cache
        );

        return new Redirector($client, $this->emitter, $debug, $basePath);
    }

    private static function match($redirect = 'https://shop.example/new-product', array $fields = [])
    {
        return FakeHttp::ok((string) json_encode(array_merge(
            ['success' => true, 'found' => true, 'redirect' => $redirect, 'score' => 0.8, 'source' => 'CATALOG'],
            $fields
        )));
    }

    public function testADeletedProductIsRedirectedWithTheApiStatus()
    {
        $this->http->queue = [self::match('https://shop.example/new-product', ['redirectStatus' => 301])];

        $outcome = $this->redirector()->handle(new RequestContext('/12-old-product.html'));

        $this->assertTrue($outcome->isRedirect());
        $this->assertSame(
            ['target' => 'https://shop.example/new-product', 'status' => 301, 'headers' => ['X-Redirect-By' => 'no404']],
            $this->emitter->redirect
        );
        $this->assertSame(1, $this->http->calls);
    }

    public function testTheSecondHitIsServedFromTheCache()
    {
        $this->http->queue = [self::match()];
        $this->redirector()->handle(new RequestContext('/12-old-product.html'));
        $outcome = $this->redirector()->handle(new RequestContext('/12-old-product.html'));

        $this->assertTrue($outcome->isRedirect());
        $this->assertSame(Client::LOOKUP_CACHE, $outcome->lookup());
        $this->assertSame(1, $this->http->calls, 'still one API call');
    }

    public static function statusCases()
    {
        return [
            'API 302' => [['redirectStatus' => 302], false, 302],
            'API 302, force 301 on' => [['redirectStatus' => 302], true, 301],
            'no redirectStatus, CATALOG 0.8' => [['score' => 0.8], false, 301],
            'no redirectStatus, CATALOG 0.4' => [['score' => 0.4], false, 302],
            'no redirectStatus, REDIRECT' => [['source' => 'REDIRECT', 'score' => 1.0], false, 301],
            'no redirectStatus, FALLBACK' => [['source' => 'FALLBACK', 'score' => 0.0], false, 302],
        ];
    }

    #[DataProvider('statusCases')]
    public function testStatusDecision(array $fields, $force301, $expected)
    {
        $this->http->queue = [self::match('/new-product', $fields)];
        $this->redirector(['force_301' => $force301])->handle(new RequestContext('/old'));

        $this->assertSame($expected, $this->emitter->redirect['status']);
    }

    public function testNoMatchRendersThe404PageAndIsNegativelyCached()
    {
        $this->http->queue = [FakeHttp::ok('{"success":true,"found":false,"redirect":null,"score":0,"source":"NONE"}')];

        $first = $this->redirector()->handle(new RequestContext('/nothing'));
        $second = $this->redirector()->handle(new RequestContext('/nothing'));

        $this->assertSame(Outcome::SKIP_NONE, $first->skipReason());
        $this->assertSame(Outcome::SKIP_CACHED, $second->skipReason());
        $this->assertNull($this->emitter->redirect);
        $this->assertSame(1, $this->http->calls);
    }

    public static function blacklistedUris()
    {
        return [
            'stylesheet' => ['/themes/classic/assets/css/theme.css'],
            'image' => ['/img/p/1/2/12.jpg'],
            'module asset' => ['/modules/x/y'],
            'module front controller' => ['/modules/ps_emailsubscription/verification'],
            'back office folder' => ['/admin123abc/'],
            'back office folder, bare' => ['/admin'],
            'admin-dev' => ['/admin-dev/index.php'],
            'admin API' => ['/api/products'],
            'webservice' => ['/webservice/dispatcher'],
            'well-known' => ['/.well-known/security.txt'],
            'index.php with query (friendly URLs off)' => ['/index.php?id_product=999&controller=product'],
            'the 404 page itself' => ['/en/page-not-found'],
            'root' => ['/'],
        ];
    }

    #[DataProvider('blacklistedUris')]
    public function testBlacklistedPathsAreNeverAsked($uri)
    {
        $outcome = $this->redirector()->handle(new RequestContext($uri));

        $this->assertSame(Outcome::SKIP_BLACKLIST, $outcome->skipReason());
        $this->assertSame(0, $this->http->calls);
    }

    public function testRealSlugsThatStartWithAdminAreStillAsked()
    {
        $this->redirector()->handle(new RequestContext('/administration-guide'));
        $this->redirector()->handle(new RequestContext('/admin-tips'));

        $this->assertSame(2, $this->http->calls);
    }

    public function testTheBackOfficeShapeIsCheckedBelowTheShopBasePath()
    {
        $outcome = $this->redirector(['ignored_prefixes' => ['/shop/modules']], false, '/shop/')->handle(new RequestContext('/shop/admin42x/'));
        $this->assertSame(Outcome::SKIP_BLACKLIST, $outcome->skipReason());

        $this->redirector(['ignored_prefixes' => ['/shop/modules']], false, '/shop')->handle(new RequestContext('/admin42x/old'));
        $this->assertSame(1, $this->http->calls, 'outside the base path the rule does not apply');
    }

    public static function methodCases()
    {
        return [
            'POST' => ['POST', false, Outcome::SKIP_METHOD],
            'PUT' => ['PUT', false, Outcome::SKIP_METHOD],
            'ajax GET' => ['GET', true, Outcome::SKIP_AJAX],
        ];
    }

    #[DataProvider('methodCases')]
    public function testOnlyRealPageViewsAreHandled($method, $ajax, $reason)
    {
        $outcome = $this->redirector()->handle(new RequestContext('/old', $method, '', $ajax));

        $this->assertSame($reason, $outcome->skipReason());
        $this->assertSame(0, $this->http->calls);
    }

    public function testHeadIsHandled()
    {
        $this->http->queue = [self::match()];
        $outcome = $this->redirector()->handle(new RequestContext('/old', 'HEAD'));

        $this->assertTrue($outcome->isRedirect());
    }

    public function testCliRunsAreIgnored()
    {
        $outcome = $this->redirector()->handle(new RequestContext('/old', 'GET', '', false, true));
        $this->assertSame(Outcome::SKIP_CLI, $outcome->skipReason());
    }

    public function testNothingIsAskedOnceHeadersAreSent()
    {
        $this->emitter->headersSent = true;
        $outcome = $this->redirector([], true)->handle(new RequestContext('/old'));

        $this->assertSame(Outcome::SKIP_HEADERS_SENT, $outcome->skipReason());
        $this->assertSame(0, $this->http->calls);
        $this->assertSame([], $this->emitter->headers, 'no debug header either');
    }

    public function testTheLanguagePrefixIsSentAndTheLocalisedTargetFollowed()
    {
        $this->http->queue = [self::match('https://shop.example/en/new-product')];
        $this->redirector()->handle(new RequestContext('/en/old-product'));

        $this->assertStringContainsString('path=' . rawurlencode('/en/old-product'), $this->http->lastUrl);
        $this->assertSame('https://shop.example/en/new-product', $this->emitter->redirect['target']);
    }

    public function testTheQueryStringIsNeverSent()
    {
        $this->redirector()->handle(new RequestContext('/old?utm_source=x&id=5'));

        $this->assertStringContainsString('path=' . rawurlencode('/old'), $this->http->lastUrl);
        $this->assertStringNotContainsString('utm_source', $this->http->lastUrl);
    }

    public function testOnlyTheAdCategoryLeavesTheStore()
    {
        $this->redirector()->handle(new RequestContext('/old?gclid=secret-click-id'));

        $this->assertStringContainsString('&ad=google', $this->http->lastUrl);
        $this->assertStringNotContainsString('secret-click-id', $this->http->lastUrl);
    }

    public function testOnlyTheAiCategoryLeavesTheStore()
    {
        $this->redirector()->handle(new RequestContext('/old?utm_source=chatgpt.com'));

        $this->assertStringContainsString('&src=chatgpt', $this->http->lastUrl);
        $this->assertStringNotContainsString('utm_source', $this->http->lastUrl);
        $this->assertStringNotContainsString('chatgpt.com', $this->http->lastUrl);
    }

    public function testAnAiReferrerIsReportedAsItsCategory()
    {
        $this->redirector()->handle(new RequestContext('/old', 'GET', 'https://claude.ai/chat/1'));

        $this->assertStringContainsString('&src=claude', $this->http->lastUrl);
    }

    public function testNoSrcIsSentForOrdinaryTraffic()
    {
        $this->redirector()->handle(new RequestContext('/old?utm_source=newsletter', 'GET', 'https://www.google.com/'));

        $this->assertStringNotContainsString('src=', $this->http->lastUrl);
    }

    public function testAnAiClickBypassesTheCacheRead()
    {
        $this->http->queue = [self::match(), self::match()];
        $this->redirector()->handle(new RequestContext('/12-old-product.html'));
        $outcome = $this->redirector()->handle(new RequestContext('/12-old-product.html?utm_source=perplexity'));

        $this->assertTrue($outcome->isRedirect());
        $this->assertSame(Client::LOOKUP_API, $outcome->lookup());
        $this->assertSame(2, $this->http->calls, 'the AI click is counted by the API');
        $this->assertStringContainsString('&src=perplexity', $this->http->lastUrl);
    }

    public function testTheRefererIsForwarded()
    {
        $this->redirector()->handle(new RequestContext('/old', 'GET', 'https://www.google.com/'));

        $this->assertStringContainsString('&ref=' . rawurlencode('https://www.google.com/'), $this->http->lastUrl);
    }

    public static function invalidTargets()
    {
        return [
            'foreign host' => ['https://evil.com/x'],
            'protocol-relative' => ['//evil.com/x'],
            'javascript:' => ['javascript:alert(1)'],
            'loop' => ['https://shop.example/old'],
            'CR/LF' => ["https://shop.example/x\r\nSet-Cookie: a=b"],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function testInvalidTargetsAreNeverFollowed($target)
    {
        $this->http->queue = [self::match($target)];
        $outcome = $this->redirector()->handle(new RequestContext('/old'));

        $this->assertSame(Outcome::SKIP_INVALID_TARGET, $outcome->skipReason());
        $this->assertNull($this->emitter->redirect);
    }

    public function testAnAlternateShopDomainIsAccepted()
    {
        $this->http->queue = [self::match('https://www.shop.example/new')];
        $outcome = $this->redirector()->handle(new RequestContext('/old'));

        $this->assertTrue($outcome->isRedirect());
    }

    public function testFailOpenAndCircuitBreaker()
    {
        $this->http->queue = [FakeHttp::transportError()];

        $first = $this->redirector()->handle(new RequestContext('/old-a'));
        $second = $this->redirector()->handle(new RequestContext('/old-b'));

        $this->assertSame(Outcome::SKIP_NONE, $first->skipReason());
        $this->assertSame(Outcome::SKIP_CIRCUIT, $second->skipReason());
        $this->assertSame(1, $this->http->calls);
        $this->assertNull($this->emitter->redirect);
    }

    public function testConfigurationErrorsCarryTheApiStatus()
    {
        $this->http->queue = [FakeHttp::status(403)];
        $outcome = $this->redirector()->handle(new RequestContext('/old'));

        $this->assertSame(403, $outcome->apiStatus());
        $this->assertSame(Client::LOOKUP_API, $outcome->lookup());
    }

    public function testDebugHeadersOnARedirect()
    {
        $this->http->queue = [self::match('/new', ['score' => 0.8123, 'redirectStatus' => 301])];
        $this->redirector([], true)->handle(new RequestContext('/old'));

        $this->assertSame(
            ['X-Redirect-By' => 'no404', 'X-No404-Source' => 'CATALOG', 'X-No404-Score' => '0.812'],
            $this->emitter->redirect['headers']
        );
    }

    public function testDebugHeaderOnASkip()
    {
        $this->redirector([], true)->handle(new RequestContext('/style.css'));
        $this->assertSame(['X-No404-Skip' => 'blacklist'], $this->emitter->headers);
    }

    public function testNoDebugHeadersByDefault()
    {
        $this->redirector()->handle(new RequestContext('/style.css'));
        $this->assertSame([], $this->emitter->headers);
    }

    public function testRequestContextFromServer()
    {
        $request = RequestContext::fromServer(
            ['REQUEST_URI' => '/x?y=1', 'REQUEST_METHOD' => 'head', 'HTTP_REFERER' => 'https://a.example/', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            false,
            'fpm-fcgi'
        );

        $this->assertSame('/x?y=1', $request->uri());
        $this->assertSame('HEAD', $request->method());
        $this->assertSame('https://a.example/', $request->referer());
        $this->assertTrue($request->isAjax());
        $this->assertFalse($request->isCli());

        $bare = RequestContext::fromServer([], true, 'cli');
        $this->assertSame('GET', $bare->method());
        $this->assertTrue($bare->isAjax());
        $this->assertTrue($bare->isCli());
        $this->assertSame(['ip' => '', 'user_agent' => '', 'country' => ''], $bare->visitor());
    }

    public function testRequestContextReadsTheVisitor()
    {
        $request = RequestContext::fromServer([
            'REQUEST_URI' => '/old',
            'REMOTE_ADDR' => '203.0.113.45',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)',
            'HTTP_CF_IPCOUNTRY' => 'TR',
        ]);
        $this->assertSame(['ip' => '203.0.113.45', 'user_agent' => 'Mozilla/5.0 (iPhone)', 'country' => 'TR'], $request->visitor());

        $proxied = RequestContext::fromServer([
            'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.1',
            'REMOTE_ADDR' => '172.16.0.2',
        ]);
        $this->assertSame('198.51.100.7', $proxied->visitor()['ip'], 'Cloudflare first');

        $forwarded = RequestContext::fromServer(['HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.1', 'REMOTE_ADDR' => '10.0.0.1']);
        $this->assertSame('203.0.113.9', $forwarded->visitor()['ip'], 'the first X-Forwarded-For entry is the client');

        $private = RequestContext::fromServer(['HTTP_X_FORWARDED_FOR' => '192.168.1.5', 'REMOTE_ADDR' => '127.0.0.1']);
        $this->assertSame('', $private->visitor()['ip'], 'private and loopback addresses are never sent');

        $this->assertSame([], $request->withVisitor([])->visitor(), 'the hook can remove the visitor data');
        $this->assertSame('/old', $request->withVisitor([])->uri(), 'and nothing else changes');
        $this->assertSame([], $request->withVisitor('broken')->visitor(), 'a module returning a non-array sends no visitor data');
        $this->assertSame([], $request->withVisitor(null)->visitor(), 'same for null');
    }

    public function testTheVisitorIsForwardedToTheApi()
    {
        $this->redirector(['visitor_secret' => 'shop-secret'])->handle(
            new RequestContext('/old', 'GET', '', false, false, ['ip' => '203.0.113.45', 'user_agent' => 'Mozilla/5.0', 'country' => 'de'])
        );

        $headers = $this->http->lastHeaders;
        $this->assertSame('Bearer testkey123', $headers['Authorization']);
        $this->assertSame('203.0.113.0', $headers['X-No404-Visitor-IP']);
        $this->assertSame('Mozilla/5.0', $headers['X-No404-Visitor-UA']);
        $this->assertSame('DE', $headers['X-No404-Visitor-Country']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $headers['X-No404-Visitor-Id']);
        $this->assertStringNotContainsString('203.0.113.45', var_export($headers, true) . $this->http->lastUrl);
    }

    public function testTheVisitorSecretIsDerivedFromTheCookieKey()
    {
        $this->assertSame('', ClientFactory::visitorSecret('https://shop.example/'), 'no _COOKIE_KEY_ outside PrestaShop -> no ID');
    }
}

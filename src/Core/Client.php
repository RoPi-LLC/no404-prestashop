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

namespace PrestaShop\Module\No404\Core;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * no404 — the platform-independent core (the behaviour contract).
 *
 * This is a port of `No404_Client` from the WordPress plugin
 * (no404-wordpress/includes/class-no404-client.php, 1.0.3). The LOGIC IS KEPT
 * IDENTICAL so both platforms behave the same and the WP core tests could be
 * ported one to one (tests/CoreTest.php). What changed:
 *   - namespace + PSR-1 names (camelCase methods/properties) for the Addons validator;
 *   - `wp_parse_url()` → `parse_url()`;
 *   - the direct-access guard checks `_PS_VERSION_` instead of `ABSPATH`;
 *   - two read-only diagnostics, lastLookup() and lastStatus(), used by the
 *     debug headers and the back-office status box. They do not change any decision.
 *
 * DO NOT add PrestaShop calls here — anything platform-specific belongs in an adapter.
 *
 * Responsibilities:
 *   - Building the request, with a short timeout (fail-open)
 *   - The local cache (quota protection, negatives included)
 *   - Never asking about static/admin paths (the blacklist)
 *   - The 301/302 decision (source + score)
 *   - Target validation: open-redirect and loop protection
 */
class Client
{
    /** Cache schema version — bump it when the shape changes; old entries are skipped. */
    public const CACHE_SCHEMA = 'v1';

    /** Ad categories the no404 API accepts in `ad=` (it drops anything else). */
    public const AD_CATEGORIES = ['google', 'microsoft', 'meta', 'other'];

    /** `utm_medium` values that mark paid traffic (lower case) — same list as the server. */
    public const PAID_MEDIUMS = [
        'cpc',
        'ppc',
        'paid',
        'paidsearch',
        'paid_search',
        'paid-search',
        'paidsocial',
        'paid_social',
        'paid-social',
        'display',
        'cpm',
        'cpv',
        'banner',
        'retargeting',
        'remarketing',
    ];

    /** CATALOG matches above this score count as permanent (301). */
    public const HIGH_CONFIDENCE_SCORE = 0.5;

    /** Default timeout (ms). Short enough not to hold up the store's 404 page. */
    public const DEFAULT_TIMEOUT_MS = 1500;

    /** Default result lifetime (seconds). */
    public const DEFAULT_CACHE_TTL = 3600;

    /** How long to wait before retrying while the API is unreachable (seconds). */
    public const OUTAGE_TTL = 60;

    /** Wait after a quota/limit hit (seconds). If the monthly quota is gone, stop asking. */
    public const QUOTA_TTL = 300;

    /** Wait after a configuration error — invalid key, inactive subscription (seconds). */
    public const CONFIG_ERROR_TTL = 300;

    /** Maximum path length the API accepts (matches ingestHitSchema). */
    public const MAX_PATH_LENGTH = 2048;

    /** Maximum visitor User-Agent length the API keeps. */
    public const MAX_USER_AGENT_LENGTH = 512;

    /** Circuit breaker key. */
    public const OUTAGE_KEY = 'outage';

    /** lastLookup() values. */
    public const LOOKUP_NONE = 'none';
    public const LOOKUP_SKIPPED = 'skipped';
    public const LOOKUP_CACHE = 'cache';
    public const LOOKUP_CIRCUIT = 'circuit';
    public const LOOKUP_API = 'api';

    /**
     * Extensions that have no catalogue counterpart and would just burn quota.
     *
     * @var string[]
     */
    protected $ignoredExtensions = [
        'css', 'js', 'mjs', 'map', 'json', 'xml', 'txt', 'php', 'asp', 'aspx',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tiff',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp3', 'mp4', 'webm', 'ogg', 'wav', 'avi', 'mov',
        'pdf', 'zip', 'gz', 'tar', 'rar', 'doc', 'docx', 'xls', 'xlsx', 'csv',
        'env', 'sql', 'bak', 'log', 'yml', 'yaml', 'ini',
    ];

    /**
     * Path prefixes that are never asked about. The platform wrapper adds its own.
     *
     * @var string[]
     */
    protected $ignoredPrefixes = [
        '/.well-known',
        '/cgi-bin',
    ];

    /** @var HttpInterface */
    protected $http;

    /** @var CacheInterface */
    protected $cache;

    /** @var string API base, without a trailing slash. */
    protected $apiBase = '';

    /** @var string */
    protected $apiKey = '';

    /** @var int */
    protected $timeoutMs = self::DEFAULT_TIMEOUT_MS;

    /** @var int */
    protected $cacheTtl = self::DEFAULT_CACHE_TTL;

    /** @var bool Send every redirect as a 301 (default: off). */
    protected $force301 = false;

    /** @var string[] Hosts allowed as a redirect target (lower case). */
    protected $allowedHosts = [];

    /** @var string Client identity (User-Agent). */
    protected $userAgent = 'no404-module';

    /** @var string Shop-specific secret for the visitor ID ('' = no ID is sent). */
    protected $visitorSecret = '';

    /** @var string How the last resolve() got its answer (diagnostics only). */
    protected $lastLookup = self::LOOKUP_NONE;

    /** @var int HTTP status of the last API call; 0 = no call or transport failure. */
    protected $lastStatus = 0;

    /**
     * @param array<string, mixed> $config settings
     * @param HttpInterface $http HTTP adapter
     * @param CacheInterface $cache cache adapter
     */
    public function __construct(array $config, HttpInterface $http, CacheInterface $cache)
    {
        $this->http = $http;
        $this->cache = $cache;

        if (isset($config['api_base'])) {
            $this->apiBase = rtrim((string) $config['api_base'], '/');
        }
        if (isset($config['api_key'])) {
            $this->apiKey = trim((string) $config['api_key']);
        }
        if (isset($config['timeout_ms'])) {
            $this->timeoutMs = max(200, min(10000, (int) $config['timeout_ms']));
        }
        if (isset($config['cache_ttl'])) {
            $this->cacheTtl = max(60, min(604800, (int) $config['cache_ttl']));
        }
        if (isset($config['force_301'])) {
            $this->force301 = (bool) $config['force_301'];
        }
        if (isset($config['user_agent'])) {
            $this->userAgent = (string) $config['user_agent'];
        }
        if (isset($config['visitor_secret'])) {
            $this->visitorSecret = (string) $config['visitor_secret'];
        }
        if (!empty($config['allowed_hosts']) && is_array($config['allowed_hosts'])) {
            foreach ($config['allowed_hosts'] as $host) {
                $host = strtolower(trim((string) $host));
                if ('' !== $host) {
                    $this->allowedHosts[] = $host;
                }
            }
            $this->allowedHosts = array_values(array_unique($this->allowedHosts));
        }
        if (!empty($config['ignored_prefixes']) && is_array($config['ignored_prefixes'])) {
            foreach ($config['ignored_prefixes'] as $prefix) {
                $prefix = $this->normalizePath($prefix);
                if ('' !== $prefix && '/' !== $prefix) {
                    $this->ignoredPrefixes[] = $prefix;
                }
            }
            $this->ignoredPrefixes = array_values(array_unique($this->ignoredPrefixes));
        }
    }

    /** Is the module operable (does it have a key and a base URL)? */
    public function isConfigured()
    {
        return '' !== $this->apiKey && '' !== $this->apiBase;
    }

    /**
     * Resolves a 404 path.
     *
     * FAIL-OPEN: never throws under any circumstance. If no404 is slow, down, or
     * returns something malformed, this returns `null` and the store renders its
     * own 404 page.
     *
     * AD CLICKS: when `$ad` is a known category (see detectAdCategory), the cache
     * is NOT read — the API is asked every time. Ad 404s are few and each one is a
     * paid click; answering them from the cache would leave them uncounted in the
     * dashboard. If no404 cannot be reached, the cached result is still used.
     *
     * VISITOR: the request leaves from the store's server, so without `$visitor`
     * no404 would record every 404 under the server's IP and the module's user
     * agent. See visitorHeaders(): the IP is truncated to its network before it is sent.
     *
     * @param string $path the path that returned 404
     * @param string $referrer where the visitor came from (optional)
     * @param string $ad ad category from detectAdCategory() ('' = not an ad click)
     * @param array<string, mixed> $visitor ip / user_agent / country of the visitor (optional)
     *
     * @return array<string, mixed>|null found/redirect/score/source/redirect_status, or null when there is no redirect
     */
    public function resolve($path, $referrer = '', $ad = '', array $visitor = [])
    {
        $this->lastLookup = self::LOOKUP_SKIPPED;
        $this->lastStatus = 0;

        try {
            if (!$this->isConfigured()) {
                return null;
            }

            $path = $this->normalizePath($path);
            if ('' === $path || '/' === $path || strlen($path) > self::MAX_PATH_LENGTH) {
                return null;
            }
            if ($this->isIgnoredPath($path)) {
                return null;
            }

            $ad = in_array($ad, self::AD_CATEGORIES, true) ? $ad : '';
            $key = $this->cacheKey($path);
            $cached = $this->cache->get($key);
            $cached = (is_array($cached) && isset($cached['source'])) ? $cached : null;
            if (null !== $cached && '' === $ad) {
                $this->lastLookup = self::LOOKUP_CACHE;

                return $cached;
            }

            // Circuit breaker: while the API is unreachable or out of quota, do not
            // retry on every single 404.
            if (null !== $this->cache->get(self::OUTAGE_KEY)) {
                $this->lastLookup = self::LOOKUP_CIRCUIT;

                return $cached;
            }

            $this->lastLookup = self::LOOKUP_API;
            $response = $this->http->get(
                $this->buildUrl($path, $referrer, $ad),
                $this->timeoutMs,
                $this->userAgent,
                array_merge($this->authHeaders(), $this->visitorHeaders($visitor))
            );

            $result = $this->handleResponse($response, $key);

            // An ad click that could not be answered falls back to what we knew.
            return (null === $result && null !== $cached) ? $cached : $result;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * How the last resolve() call got its answer: none, skipped (blacklist or
     * unconfigured), cache, circuit (breaker open) or api. Diagnostics only.
     *
     * @return string
     */
    public function lastLookup()
    {
        return $this->lastLookup;
    }

    /**
     * HTTP status of the last API call made by resolve(); 0 when no call was made
     * or the transport failed. Diagnostics only.
     *
     * @return int
     */
    public function lastStatus()
    {
        return $this->lastStatus;
    }

    /**
     * Turns an HTTP response into a result and writes the cache entries it needs.
     *
     * @param array<string, mixed> $response output of the HTTP adapter
     * @param string $key the cache key for this path
     *
     * @return array<string, mixed>|null
     */
    protected function handleResponse(array $response, $key)
    {
        // Transport failure (timeout, DNS, TLS) → trip the breaker, do not stall the store.
        if (empty($response['ok'])) {
            $this->cache->set(self::OUTAGE_KEY, 1, self::OUTAGE_TTL);

            return null;
        }

        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $this->lastStatus = $status;

        if (429 === $status) {
            // Rate limit or monthly quota. Neither changes in the short term.
            $this->cache->set(self::OUTAGE_KEY, 1, self::QUOTA_TTL);

            return null;
        }
        if (403 === $status || 404 === $status) {
            // Invalid key, paused site, inactive subscription → a configuration problem.
            $this->cache->set(self::OUTAGE_KEY, 1, self::CONFIG_ERROR_TTL);

            return null;
        }
        if ($status >= 500) {
            $this->cache->set(self::OUTAGE_KEY, 1, self::OUTAGE_TTL);

            return null;
        }

        $payload = $this->decode(isset($response['body']) ? $response['body'] : '');

        if (200 !== $status || null === $payload || empty($payload['success'])) {
            // Including 422 (invalid path): there is no point asking about this PATH again.
            $this->cache->set($key, $this->emptyResult(), $this->cacheTtl);

            return null;
        }

        $result = [
            'found' => !empty($payload['found']),
            'redirect' => isset($payload['redirect']) && is_string($payload['redirect']) ? $payload['redirect'] : null,
            'score' => isset($payload['score']) ? (float) $payload['score'] : 0.0,
            'source' => isset($payload['source']) && is_string($payload['source']) ? $payload['source'] : 'NONE',
            // The API's own 301/302 decision (0 when absent — older API versions).
            'redirect_status' => self::readRedirectStatus($payload),
        ];

        // Negative results are cached too — this is what actually protects the quota.
        $this->cache->set($key, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Decides the redirect's HTTP status code.
     *
     * A 301 is cached PERMANENTLY by browsers and by Google; issuing one for a
     * speculative match cannot be undone, even if the catalogue is corrected later.
     *
     * Order: the "force 301" setting → the API's `redirectStatus` (computed from
     * the 301 threshold the site owner picked in the no404 panel) → the local
     * rule below, which only applies to API versions that don't send the field.
     *
     * @param array<string, mixed> $result output of resolve()
     *
     * @return int 301 or 302
     */
    public function decideStatus(array $result)
    {
        if ($this->force301) {
            return 301;
        }

        $apiStatus = isset($result['redirect_status']) ? (int) $result['redirect_status'] : 0;
        if (301 === $apiStatus || 302 === $apiStatus) {
            return $apiStatus;
        }

        $source = isset($result['source']) ? $result['source'] : 'NONE';
        $score = isset($result['score']) ? (float) $result['score'] : 0.0;

        if ('REDIRECT' === $source) {
            return 301; // A human defined it; it is certain.
        }
        if ('CATALOG' === $source && $score >= self::HIGH_CONFIDENCE_SCORE) {
            return 301;
        }

        return 302; // Low-scoring CATALOG and FALLBACK → keep it reversible.
    }

    /**
     * `redirectStatus` from an API payload: 301, 302, or 0 when absent/invalid.
     * Anything else (a 307, a string, a missing field) is ignored so the local
     * rule in decideStatus() stays in charge.
     *
     * @param array<string, mixed> $payload decoded API response
     *
     * @return int
     */
    private static function readRedirectStatus(array $payload)
    {
        $value = isset($payload['redirectStatus']) && is_numeric($payload['redirectStatus'])
            ? (int) $payload['redirectStatus']
            : 0;

        return (301 === $value || 302 === $value) ? $value : 0;
    }

    /**
     * Validates the redirect target: open-redirect and loop protection.
     *
     * @param mixed $redirect the target returned by the API
     * @param string $currentPath the current (404) path, normalised
     *
     * @return string a safe URL, or an empty string if it is rejected
     */
    public function validateTarget($redirect, $currentPath)
    {
        if (!is_string($redirect) || '' === trim($redirect)) {
            return '';
        }

        // Header injection: control characters never get through.
        if (preg_match('/[\x00-\x1F\x7F]/', $redirect)) {
            return '';
        }

        $redirect = trim($redirect);
        if (strlen($redirect) > self::MAX_PATH_LENGTH) {
            return '';
        }

        $parts = self::parseUrlParts($redirect);
        if (!is_array($parts)) {
            return '';
        }

        // A relative target with no scheme or host is taken to be our own site.
        if (empty($parts['host'])) {
            // "//evil.com" is protocol-relative; non-relative input is rejected too.
            if (0 !== strpos($redirect, '/') || 0 === strpos($redirect, '//')) {
                return '';
            }
            if ($this->normalizePath($redirect) === $currentPath) {
                return ''; // Loop.
            }

            return $redirect;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ('http' !== $scheme && 'https' !== $scheme) {
            return '';
        }

        $host = strtolower($parts['host']);
        if (!in_array($host, $this->allowedHosts, true)) {
            return '';
        }

        // Loop: a redirect to the same path on an allowed host.
        if ($this->normalizePath(isset($parts['path']) ? $parts['path'] : '/') === $currentPath) {
            return '';
        }

        return $redirect;
    }

    /**
     * Should this path never be asked about?
     *
     * @param string $path the normalised path
     *
     * @return bool
     */
    public function isIgnoredPath($path)
    {
        $lower = strtolower($path);

        foreach ($this->ignoredPrefixes as $prefix) {
            $prefix = strtolower($prefix);
            if ($lower === $prefix || 0 === strpos($lower, $prefix . '/')) {
                return true;
            }
        }

        $slash = strrpos($lower, '/');
        $basename = (false === $slash) ? $lower : substr($lower, $slash + 1);
        $dot = strrpos($basename, '.');
        if (false === $dot || $dot === strlen($basename) - 1) {
            return false;
        }

        return in_array(substr($basename, $dot + 1), $this->ignoredExtensions, true);
    }

    /**
     * Splits a URL into its components.
     *
     * The WordPress core goes through `wp_parse_url()` here. `parse_url()` has
     * handled scheme-less URLs ("//host/path") correctly since PHP 5.4.7 — every
     * PHP this module runs on, the 7.2 of the PrestaShop 8 line included — so the
     * plain function is used.
     *
     * @param string $url the URL to parse
     *
     * @return array<string, mixed>|false the components, or false when parsing fails
     */
    private static function parseUrlParts($url)
    {
        return parse_url($url);
    }

    /**
     * Brings a path into canonical form.
     *
     * Applies the SAME rules as `cleanPath` on the no404 server, so the local cache
     * key and the path the server sees line up. The query string is discarded — the
     * server only takes the `pathname` anyway, and keeping `?utm_source=...` would
     * fragment the cache and burn quota for nothing.
     *
     * @param mixed $raw raw path or full URL
     *
     * @return string a path starting with "/", or an empty string
     */
    public function normalizePath($raw)
    {
        $raw = (string) $raw;
        $raw = str_replace(["\r", "\n", "\t", "\0"], '', $raw);
        $raw = trim($raw);
        if ('' === $raw) {
            return '';
        }

        // A full URL: keep only its path.
        if (preg_match('#^https?://#i', $raw)) {
            $parsed = self::parseUrlParts($raw);
            $raw = (is_array($parsed) && isset($parsed['path'])) ? $parsed['path'] : '/';
        }

        // Drop the query string and the fragment.
        $raw = substr($raw, 0, strcspn($raw, '?#'));

        // Treat a backslash as a slash (this is what the WHATWG URL spec does for http(s)).
        $raw = str_replace('\\', '/', $raw);

        if ('' === $raw) {
            return '/';
        }
        if (0 !== strpos($raw, '/')) {
            $raw = '/' . $raw;
        }

        $raw = preg_replace('#/{2,}#', '/', $raw);
        if (strlen($raw) > 1) {
            $raw = rtrim($raw, '/');
        }

        return '' === $raw ? '/' : $raw;
    }

    /**
     * Diagnostic call behind "test the connection" on the settings screen.
     *
     * Deliberately BYPASSES the cache and the circuit breaker — the user needs to
     * see the real state.
     *
     * @param string $path path to test
     *
     * @return array<string, mixed> code/status/found/redirect/score/source/detail
     */
    public function ping($path = '/no404-connection-test')
    {
        $out = [
            'code' => 'unknown',
            'status' => 0,
            'found' => false,
            'redirect' => null,
            'score' => 0.0,
            'source' => 'NONE',
            'detail' => '',
        ];

        if ('' === $this->apiBase) {
            $out['code'] = 'no_api_base';

            return $out;
        }
        if ('' === $this->apiKey) {
            $out['code'] = 'no_api_key';

            return $out;
        }

        $response = $this->http->get(
            $this->buildUrl($this->normalizePath($path), '', ''),
            max(5000, $this->timeoutMs), // During a test the user can afford to wait.
            $this->userAgent,
            $this->authHeaders()
        );

        if (empty($response['ok'])) {
            $out['code'] = 'unreachable';
            $out['detail'] = isset($response['error']) ? (string) $response['error'] : '';

            return $out;
        }

        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $out['status'] = $status;
        $payload = $this->decode(isset($response['body']) ? $response['body'] : '');

        if (is_array($payload) && isset($payload['message']) && is_string($payload['message'])) {
            $out['detail'] = $payload['message'];
        }

        // A redirect the transport did not follow. The address is wrong rather
        // than broken, and the Location header says what the right one is, so
        // this gets its own code instead of "unexpected response".
        if ($status >= 300 && $status < 400) {
            $out['code'] = 'redirected';
            $out['detail'] = isset($response['location']) ? (string) $response['location'] : '';

            return $out;
        }

        if (200 === $status && is_array($payload) && !empty($payload['success'])) {
            $out['code'] = 'ok';
            $out['found'] = !empty($payload['found']);
            $out['redirect'] = isset($payload['redirect']) && is_string($payload['redirect']) ? $payload['redirect'] : null;
            $out['score'] = isset($payload['score']) ? (float) $payload['score'] : 0.0;
            $out['source'] = isset($payload['source']) && is_string($payload['source']) ? $payload['source'] : 'NONE';

            return $out;
        }

        switch ($status) {
            case 404:
                $out['code'] = 'invalid_key';
                break;
            case 403:
                $out['code'] = 'forbidden';
                break;
            case 429:
                $out['code'] = 'rate_limited';
                break;
            case 422:
                $out['code'] = 'invalid_path';
                break;
            default:
                $out['code'] = $status >= 500 ? 'server_error' : 'unexpected';
        }

        return $out;
    }

    /**
     * Builds the request URL.
     *
     * The API key is NOT part of the URL: it travels in the Authorization header
     * (see authHeaders). A key in the URL ends up in web server, proxy and CDN
     * logs; a header does not.
     *
     * @param string $path the normalised path
     * @param string $referrer referrer
     * @param string $ad ad category ('' = not an ad click)
     *
     * @return string
     */
    protected function buildUrl($path, $referrer, $ad = '')
    {
        $query = 'path=' . rawurlencode($path);

        $referrer = trim((string) $referrer);
        if ('' !== $referrer) {
            $query .= '&ref=' . rawurlencode(substr($referrer, 0, self::MAX_PATH_LENGTH));
        }
        if (in_array($ad, self::AD_CATEGORIES, true)) {
            $query .= '&ad=' . $ad;
        }

        return $this->apiBase . '/api/v1/resolve?' . $query;
    }

    /** @return array<string, string> the Authorization header carrying the API key */
    protected function authHeaders()
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    /**
     * Visitor headers. The module's own User-Agent keeps identifying the
     * installation; these describe the visitor who hit the 404.
     *
     * PRIVACY: the full IP never leaves the store — only its network
     * (see truncateIp) and a shop-keyed HMAC of it (see visitorId).
     * Invalid values are dropped, not sent.
     *
     * @param array<string, mixed> $visitor ip / user_agent / country
     *
     * @return array<string, string> header name => value
     */
    protected function visitorHeaders(array $visitor)
    {
        $headers = [];

        $ip = isset($visitor['ip']) ? $this->truncateIp($visitor['ip']) : '';
        if ('' !== $ip) {
            $headers['X-No404-Visitor-IP'] = $ip;
        }

        $id = isset($visitor['ip']) ? $this->visitorId($visitor['ip']) : '';
        if ('' !== $id) {
            $headers['X-No404-Visitor-Id'] = $id;
        }

        $ua = isset($visitor['user_agent']) ? preg_replace('/[\x00-\x1F\x7F]/', '', (string) $visitor['user_agent']) : '';
        $ua = trim(substr((string) $ua, 0, self::MAX_USER_AGENT_LENGTH));
        if ('' !== $ua) {
            $headers['X-No404-Visitor-UA'] = $ua;
        }

        $country = isset($visitor['country']) ? strtoupper(trim((string) $visitor['country'])) : '';
        // Cloudflare sends "XX" when the country is unknown.
        if (1 === preg_match('/^[A-Z]{2}$/', $country) && 'XX' !== $country) {
            $headers['X-No404-Visitor-Country'] = $country;
        }

        return $headers;
    }

    /**
     * Pseudonymous visitor ID: HMAC-SHA256 of the FULL IP, keyed with a secret
     * that only this shop knows. It lets no404 count unique visitors exactly
     * (the truncated IP alone merges a whole /24), while no404 can neither
     * reverse it to an address (a plain hash of an IPv4 address can be brute
     * forced) nor match one visitor across two shops (each has its own secret).
     *
     * The packed address is hashed, so ::ffff:1.2.3.4 and 1.2.3.4 — or two
     * spellings of one IPv6 address — yield the same ID. No secret → ''.
     *
     * @param mixed $ip raw IP address
     *
     * @return string 64 hex characters, or ''
     */
    public function visitorId($ip)
    {
        if ('' === $this->visitorSecret) {
            return '';
        }
        $ip = trim((string) $ip);
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }
        $packed = inet_pton($ip);
        if (false === $packed) {
            return '';
        }

        return hash_hmac('sha256', $packed, $this->visitorSecret);
    }

    /**
     * Truncates an IP address to its network: IPv4 → last octet zeroed
     * (203.0.113.0), IPv6 → first 48 bits (2001:db8:1c1c::). An IPv4-mapped IPv6
     * address counts as IPv4. Invalid input → ''.
     *
     * @param mixed $ip raw IP address
     *
     * @return string
     */
    public function truncateIp($ip)
    {
        $ip = trim((string) $ip);
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string) preg_replace('/\.\d+$/', '.0', $ip);
        }

        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if (false === $packed || 16 !== strlen($packed)) {
                return '';
            }
            $network = inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10));

            return false === $network ? '' : $network;
        }

        return '';
    }

    /**
     * Works out whether a request came from an ad click, from its RAW request URI
     * (query string included). Returns only the CATEGORY — google, microsoft, meta,
     * other — or '' for organic traffic. The raw click ID (gclid, msclkid…) never
     * leaves the store. Mirrors the no404 server's own rules (lib/ad-source.ts):
     * fbclid alone is NOT an ad, because Facebook adds it to organic shares too.
     *
     * @param string $rawUri request URI, e.g. /product?gclid=abc
     *
     * @return string
     */
    public function detectAdCategory($rawUri)
    {
        $raw = (string) $rawUri;
        $q = strpos($raw, '?');
        if (false === $q) {
            return '';
        }

        $query = substr($raw, $q + 1);
        $hash = strpos($query, '#');
        if (false !== $hash) {
            $query = substr($query, 0, $hash);
        }

        $params = [];
        parse_str($query, $params);
        $value = function ($key) use ($params) {
            return (isset($params[$key]) && is_string($params[$key])) ? strtolower(trim($params[$key])) : '';
        };

        foreach (['gclid', 'gbraid', 'wbraid', 'gclsrc'] as $key) {
            if ('' !== $value($key)) {
                return 'google';
            }
        }
        if ('' !== $value('msclkid')) {
            return 'microsoft';
        }
        if (in_array($value('utm_medium'), self::PAID_MEDIUMS, true)) {
            return self::categoryForSource($value('utm_source'));
        }
        foreach (['ttclid', 'twclid', 'li_fat_id'] as $key) {
            if ('' !== $value($key)) {
                return 'other';
            }
        }

        return '';
    }

    /**
     * The ad network of a click already known to be paid, from `utm_source`.
     * Meta's `{{site_source_name}}` yields fb, ig, an or msg — all four are Meta.
     *
     * @param string $source lower-case utm_source
     *
     * @return string
     */
    private static function categoryForSource($source)
    {
        if (preg_match('/google|adwords/', $source)) {
            return 'google';
        }
        if (preg_match('/bing|microsoft/', $source)) {
            return 'microsoft';
        }
        if (preg_match('/facebook|instagram|meta|messenger|audience_network|threads|^(fb|ig|an|msg)$/', $source)) {
            return 'meta';
        }

        return 'other';
    }

    /**
     * Cache key for a path. Changing the API key drops the old entries.
     * (The PrestaShop cache adapter namespaces its entries per shop.)
     *
     * @param string $path the normalised path
     *
     * @return string
     */
    protected function cacheKey($path)
    {
        return self::CACHE_SCHEMA . ':' . substr(md5($this->apiKey), 0, 8) . ':' . md5($path);
    }

    /** @return array<string, mixed> the "no match found" result (used for negative caching) */
    protected function emptyResult()
    {
        return [
            'found' => false,
            'redirect' => null,
            'score' => 0.0,
            'source' => 'NONE',
            'redirect_status' => 0,
        ];
    }

    /**
     * Decodes JSON; a malformed body never turns into an exception.
     *
     * @param mixed $body the raw body
     *
     * @return array<string, mixed>|null
     */
    protected function decode($body)
    {
        if (!is_string($body) || '' === $body) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}

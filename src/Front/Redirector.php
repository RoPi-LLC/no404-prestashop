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

namespace PrestaShop\Module\No404\Front;

use PrestaShop\Module\No404\Core\Client;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The 404 catcher, called from the `actionNotFound` hook: gate → core client →
 * target validation → 301/302.
 *
 * It only ever runs on requests PrestaShop has already decided are 404s, and
 * PrestaShop's own product/category redirect settings run before that point —
 * no404 is the last resort.
 *
 * Behaviour mirrors the WordPress plugin's No404_Redirector: GET/HEAD only, no
 * ajax/CLI, a single redirect carrying `X-Redirect-By: no404`, target validated
 * by the core (same host, no loop, no control characters).
 *
 * FAIL-OPEN: every "no" is a quiet return; the 404 page renders as usual.
 * This class has no PrestaShop dependency — the PrestaShop wiring lives in
 * ClientFactory and the module class.
 */
final class Redirector
{
    /** @var Client */
    private $client;

    /** @var ResponseEmitter */
    private $emitter;

    /** @var bool */
    private $debug;

    /** @var string shop physical base path without trailing slash ('' at the web root) */
    private $basePath;

    /**
     * @param Client $client configured core client
     * @param ResponseEmitter $emitter response writer
     * @param bool $debug send X-No404-* diagnostic headers
     * @param string $basePath shop physical base path, e.g. '/shop' ('' at the web root)
     */
    public function __construct(Client $client, ResponseEmitter $emitter, $debug = false, $basePath = '')
    {
        $this->client = $client;
        $this->emitter = $emitter;
        $this->debug = (bool) $debug;
        $this->basePath = rtrim((string) $basePath, '/');
    }

    /**
     * Decides and acts: on a match the emitter sends the redirect and ends the
     * request (so in production this only returns on a skip).
     *
     * @param RequestContext $request the current request
     *
     * @return Outcome
     */
    public function handle(RequestContext $request)
    {
        $outcome = $this->decide($request);

        if ($outcome->isRedirect()) {
            $headers = ['X-Redirect-By' => 'no404'];
            if ($this->debug) {
                $headers['X-No404-Source'] = $outcome->source();
                $headers['X-No404-Score'] = sprintf('%.3f', $outcome->score());
            }
            $this->emitter->redirect($outcome->target(), $outcome->status(), $headers);

            return $outcome;
        }

        if ($this->debug && Outcome::SKIP_HEADERS_SENT !== $outcome->skipReason() && !$this->emitter->headersSent()) {
            $this->emitter->headers(['X-No404-Skip' => $outcome->skipReason()]);
        }

        return $outcome;
    }

    /**
     * The decision alone, without touching the response.
     *
     * @param RequestContext $request the current request
     *
     * @return Outcome
     */
    public function decide(RequestContext $request)
    {
        // Real page views only.
        if ('GET' !== $request->method() && 'HEAD' !== $request->method()) {
            return Outcome::skip(Outcome::SKIP_METHOD);
        }
        if ($request->isAjax()) {
            return Outcome::skip(Outcome::SKIP_AJAX);
        }
        if ($request->isCli()) {
            return Outcome::skip(Outcome::SKIP_CLI);
        }
        if ($this->emitter->headersSent()) {
            return Outcome::skip(Outcome::SKIP_HEADERS_SENT); // Cannot redirect any more; do not break the page.
        }

        $path = $this->client->normalizePath($request->uri());
        if ('' === $path || '/' === $path || $this->isAdminLikePath($path) || $this->client->isIgnoredPath($path)) {
            return Outcome::skip(Outcome::SKIP_BLACKLIST);
        }

        // Ad measurement: only the CATEGORY (google, meta…) leaves the store, never
        // the raw click ID. Read from the raw URI, before the query string is dropped.
        $ad = $this->client->detectAdCategory($request->uri());
        // AI assistants (ChatGPT, Claude…): likewise only the category, from
        // utm_source or the referrer's host.
        $src = $this->client->detectAiSource($request->uri(), $request->referer());

        $result = $this->client->resolve($path, $request->referer(), $ad, $request->visitor(), $src);
        $lookup = $this->client->lastLookup();
        $apiStatus = $this->client->lastStatus();

        if (null === $result || empty($result['redirect'])) {
            return Outcome::skip(self::skipReasonFor($lookup), $lookup, $apiStatus);
        }

        $target = $this->client->validateTarget($result['redirect'], $path);
        if ('' === $target) {
            return Outcome::skip(Outcome::SKIP_INVALID_TARGET, $lookup, $apiStatus);
        }

        return Outcome::redirect(
            $target,
            $this->client->decideStatus($result),
            isset($result['source']) ? (string) $result['source'] : 'NONE',
            isset($result['score']) ? (float) $result['score'] : 0.0,
            $lookup,
            $apiStatus
        );
    }

    /**
     * PrestaShop's back-office folder is renamed at install to "admin" plus random
     * letters and digits (admin634ghbf7w). Its name is not known on the storefront,
     * so probes at it are recognised by shape: exactly "admin" or "admin-dev", or
     * "admin" followed by an alphanumeric run containing at least one digit. That
     * leaves real slugs such as /administration or /admin-guide alone.
     *
     * @param string $path normalised path
     *
     * @return bool
     */
    private function isAdminLikePath($path)
    {
        if ('' !== $this->basePath) {
            $base = strtolower($this->basePath);
            $lower = strtolower($path);
            if (0 !== strpos($lower, $base . '/')) {
                return false;
            }
            $path = substr($path, strlen($this->basePath));
        }

        $segments = explode('/', ltrim($path, '/'), 2);
        $first = strtolower($segments[0]);

        return 'admin' === $first
            || 'admin-dev' === $first
            || 1 === preg_match('/^admin(?=[a-z0-9]*\d)[a-z0-9]+$/', $first);
    }

    /**
     * @param string $lookup Client::LOOKUP_*
     *
     * @return string
     */
    private static function skipReasonFor($lookup)
    {
        switch ($lookup) {
            case Client::LOOKUP_CACHE:
                return Outcome::SKIP_CACHED;
            case Client::LOOKUP_CIRCUIT:
                return Outcome::SKIP_CIRCUIT;
            case Client::LOOKUP_SKIPPED:
                return Outcome::SKIP_BLACKLIST;
            default:
                return Outcome::SKIP_NONE;
        }
    }
}

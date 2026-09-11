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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The parts of the incoming request the redirector looks at, as an immutable
 * value. Built from `$_SERVER` in the hook; built by hand in the tests.
 * Validation of the URI happens in the core (normalizePath + isIgnoredPath).
 */
final class RequestContext
{
    /** @var string raw request URI, query string included */
    private $uri;

    /** @var string upper-case HTTP method */
    private $method;

    /** @var string */
    private $referer;

    /** @var bool */
    private $ajax;

    /** @var bool */
    private $cli;

    /** @var array<string, mixed> ip / user_agent / country of the visitor */
    private $visitor;

    /**
     * @param string $uri raw request URI, query string included
     * @param string $method HTTP method
     * @param string $referer Referer header ('' when absent)
     * @param bool $ajax XHR or PrestaShop `ajax` parameter
     * @param bool $cli command-line run
     * @param array<string, mixed> $visitor ip / user_agent / country (the core validates and truncates them)
     */
    public function __construct($uri, $method = 'GET', $referer = '', $ajax = false, $cli = false, array $visitor = [])
    {
        $this->uri = (string) $uri;
        $this->method = strtoupper(trim((string) $method));
        $this->referer = (string) $referer;
        $this->ajax = (bool) $ajax;
        $this->cli = (bool) $cli;
        $this->visitor = $visitor;
    }

    /**
     * @param array<string, mixed> $server usually $_SERVER
     * @param bool $ajaxParam whether the PrestaShop `ajax` request parameter is set
     * @param string $sapi PHP SAPI name
     *
     * @return self
     */
    public static function fromServer(array $server, $ajaxParam = false, $sapi = PHP_SAPI)
    {
        $value = static function ($key) use ($server) {
            return (isset($server[$key]) && is_string($server[$key])) ? $server[$key] : '';
        };

        $xhr = 'xmlhttprequest' === strtolower($value('HTTP_X_REQUESTED_WITH'));

        return new self(
            $value('REQUEST_URI'),
            '' !== $value('REQUEST_METHOD') ? $value('REQUEST_METHOD') : 'GET',
            $value('HTTP_REFERER'),
            $ajaxParam || $xhr,
            'cli' === $sapi || 'phpdbg' === $sapi,
            [
                'ip' => self::visitorIp($server),
                'user_agent' => $value('HTTP_USER_AGENT'),
                'country' => $value('HTTP_CF_IPCOUNTRY'),
            ]
        );
    }

    /**
     * The visitor's public IP. Behind Cloudflare or a reverse proxy REMOTE_ADDR is
     * the proxy, so the usual forwarding headers are read first. A visitor can
     * forge those, but the value only feeds that shop's own statistics (and is
     * truncated anyway); no404's rate limit uses the store server's real address.
     * Private and reserved addresses are never sent.
     *
     * @param array<string, mixed> $server usually $_SERVER
     *
     * @return string
     */
    private static function visitorIp(array $server)
    {
        $public = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!isset($server[$key]) || !is_string($server[$key]) || '' === $server[$key]) {
                continue;
            }
            $list = explode(',', $server[$key]);
            $first = trim($list[0]);
            if (false !== filter_var($first, FILTER_VALIDATE_IP, $public)) {
                return $first;
            }
        }

        return '';
    }

    /**
     * The same request with other visitor data (the `actionNo404Visitor` hook).
     *
     * Takes any value: the hook hands the variable to other modules by
     * reference, so what comes back is not guaranteed to be an array. Anything
     * else sends no visitor data.
     *
     * @param mixed $visitor ip / user_agent / country; [] sends none
     *
     * @return self
     */
    public function withVisitor($visitor)
    {
        return new self(
            $this->uri,
            $this->method,
            $this->referer,
            $this->ajax,
            $this->cli,
            is_array($visitor) ? $visitor : []
        );
    }

    /** @return string */
    public function uri()
    {
        return $this->uri;
    }

    /** @return string */
    public function method()
    {
        return $this->method;
    }

    /** @return string */
    public function referer()
    {
        return $this->referer;
    }

    /** @return bool */
    public function isAjax()
    {
        return $this->ajax;
    }

    /** @return bool */
    public function isCli()
    {
        return $this->cli;
    }

    /** @return array<string, mixed> ip / user_agent / country */
    public function visitor()
    {
        return $this->visitor;
    }
}

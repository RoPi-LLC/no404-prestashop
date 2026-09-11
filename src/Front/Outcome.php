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
 * What the redirector decided for one request: a redirect (target + status)
 * or a skip with its reason. The skip reason is what `X-No404-Skip` carries
 * when debug headers are on.
 */
final class Outcome
{
    /** Not a GET/HEAD request. */
    public const SKIP_METHOD = 'method';
    /** XHR or PrestaShop ajax call. */
    public const SKIP_AJAX = 'ajax';
    /** Command-line run. */
    public const SKIP_CLI = 'cli';
    /** Output already started; a Location header cannot be sent any more. */
    public const SKIP_HEADERS_SENT = 'headers-sent';
    /** Static file, internal/admin path, excluded path, the 404 page itself, or the root. */
    public const SKIP_BLACKLIST = 'blacklist';
    /** Answered from the local cache, and the answer was "no redirect". */
    public const SKIP_CACHED = 'cached';
    /** Circuit breaker open after an outage, a 429 or a 403/404. */
    public const SKIP_CIRCUIT = 'circuit';
    /** The service had no match (or the lookup failed). */
    public const SKIP_NONE = 'none';
    /** The service proposed a target that failed validation. */
    public const SKIP_INVALID_TARGET = 'invalid-target';

    /** @var string '' for a redirect */
    private $skipReason;

    /** @var string */
    private $target;

    /** @var int */
    private $status;

    /** @var string */
    private $source;

    /** @var float */
    private $score;

    /** @var string Client::LOOKUP_* */
    private $lookup;

    /** @var int HTTP status of the API call, 0 when none */
    private $apiStatus;

    private function __construct($skipReason, $target, $status, $source, $score, $lookup, $apiStatus)
    {
        $this->skipReason = (string) $skipReason;
        $this->target = (string) $target;
        $this->status = (int) $status;
        $this->source = (string) $source;
        $this->score = (float) $score;
        $this->lookup = (string) $lookup;
        $this->apiStatus = (int) $apiStatus;
    }

    /**
     * @param string $target validated target URL
     * @param int $status 301 or 302
     * @param string $source match source (CATALOG, REDIRECT, FALLBACK)
     * @param float $score match score
     * @param string $lookup Client::LOOKUP_*
     * @param int $apiStatus HTTP status of the API call
     *
     * @return self
     */
    public static function redirect($target, $status, $source, $score, $lookup, $apiStatus)
    {
        return new self('', $target, $status, $source, $score, $lookup, $apiStatus);
    }

    /**
     * @param string $reason one of the SKIP_* constants
     * @param string $lookup Client::LOOKUP_*
     * @param int $apiStatus HTTP status of the API call
     *
     * @return self
     */
    public static function skip($reason, $lookup = 'none', $apiStatus = 0)
    {
        return new self($reason, '', 0, 'NONE', 0.0, $lookup, $apiStatus);
    }

    /** @return bool */
    public function isRedirect()
    {
        return '' === $this->skipReason;
    }

    /** @return string */
    public function skipReason()
    {
        return $this->skipReason;
    }

    /** @return string */
    public function target()
    {
        return $this->target;
    }

    /** @return int */
    public function status()
    {
        return $this->status;
    }

    /** @return string */
    public function source()
    {
        return $this->source;
    }

    /** @return float */
    public function score()
    {
        return $this->score;
    }

    /** @return string */
    public function lookup()
    {
        return $this->lookup;
    }

    /** @return int */
    public function apiStatus()
    {
        return $this->apiStatus;
    }
}

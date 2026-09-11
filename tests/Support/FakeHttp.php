<?php

namespace PrestaShop\Module\No404\Tests\Support;

use PrestaShop\Module\No404\Core\HttpInterface;

/** Fake HTTP: returns queued responses and counts the calls. */
final class FakeHttp implements HttpInterface
{
    /** @var int */
    public $calls = 0;

    /** @var array<int, array<string, mixed>> */
    public $queue = [];

    /** @var string */
    public $lastUrl = '';

    /** @var array<string, string> */
    public $lastHeaders = [];

    /** @var int */
    public $lastTimeoutMs = 0;

    /** @var string */
    public $lastUserAgent = '';

    public function get($url, $timeoutMs, $userAgent, array $headers = [])
    {
        ++$this->calls;
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastTimeoutMs = $timeoutMs;
        $this->lastUserAgent = $userAgent;
        if (empty($this->queue)) {
            return self::ok('{"success":true,"found":false,"redirect":null,"score":0,"source":"NONE"}');
        }

        return array_shift($this->queue);
    }

    /** @return array<string, mixed> */
    public static function ok($body)
    {
        return ['ok' => true, 'status' => 200, 'body' => $body, 'error' => ''];
    }

    /** @return array<string, mixed> */
    public static function status($status, $body = '{"success":false,"message":"x"}')
    {
        return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => ''];
    }

    /** @return array<string, mixed> */
    public static function transportError($error = 'cURL timeout')
    {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $error];
    }
}

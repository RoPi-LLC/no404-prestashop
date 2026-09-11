<?php

namespace PrestaShop\Module\No404\Tests\Support;

use PrestaShop\Module\No404\Front\ResponseEmitter;

/** Records what would have been sent instead of sending it. */
final class RecordingEmitter implements ResponseEmitter
{
    /** @var bool */
    public $headersSent = false;

    /** @var array<string, string> */
    public $headers = [];

    /** @var array{target: string, status: int, headers: array<string, string>}|null */
    public $redirect;

    public function headersSent()
    {
        return $this->headersSent;
    }

    public function headers(array $headers)
    {
        $this->headers = array_merge($this->headers, $headers);
    }

    public function redirect($target, $status, array $headers)
    {
        $this->redirect = ['target' => $target, 'status' => $status, 'headers' => $headers];
    }
}

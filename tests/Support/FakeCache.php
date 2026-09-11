<?php

namespace PrestaShop\Module\No404\Tests\Support;

use PrestaShop\Module\No404\Core\CacheInterface;

/** Fake cache: in memory; TTLs are recorded but not enforced (the tests are short-lived). */
final class FakeCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public $store = [];

    /** @var array<string, int> */
    public $ttls = [];

    public function get($key)
    {
        return isset($this->store[$key]) ? $this->store[$key] : null;
    }

    public function set($key, $value, $ttl)
    {
        $this->store[$key] = $value;
        $this->ttls[$key] = $ttl;
    }

    public function flush()
    {
        $this->store = [];
        $this->ttls = [];
    }
}

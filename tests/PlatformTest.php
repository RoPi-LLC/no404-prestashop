<?php

namespace PrestaShop\Module\No404\Tests;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\No404\Platform;

/**
 * Which PrestaShop releases fire actionNotFound decides whether the catch-all
 * path must always run. Checked against the PrestaShop sources (11 Sep 2026):
 * the hook appears in 9.1.5; 8.x and 9.0.0 – 9.1.4 never call it.
 */
final class PlatformTest extends TestCase
{
    public function testOnlyPrestaShop915AndLaterFireTheNotFoundHook()
    {
        foreach (['8.0.0', '8.1.7', '8.2.8', '9.0.0', '9.0.3', '9.1.0', '9.1.4'] as $version) {
            $this->assertFalse(Platform::firesNotFoundHook($version), $version . ' does not fire actionNotFound');
        }
        foreach (['9.1.5', '9.1.10', '9.2.0', '10.0.0'] as $version) {
            $this->assertTrue(Platform::firesNotFoundHook($version), $version . ' fires actionNotFound');
        }
    }
}

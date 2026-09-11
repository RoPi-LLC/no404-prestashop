<?php
/**
 * Test bootstrap: the module's code runs WITHOUT PrestaShop loaded.
 * Only the direct-access guard constant is defined.
 */
define('_PS_VERSION_', '9.1.5');

require __DIR__ . '/../vendor/autoload.php';

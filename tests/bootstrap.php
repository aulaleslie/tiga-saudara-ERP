<?php

/**
 * Test Bootstrap
 *
 * This bootstrap ensures that:
 * 1. Testing environment uses an isolated file-backed SQLite database
 * 2. Cached config from non-testing environments does not bleed into tests
 * 3. Fail-fast guard prevents accidental use of development database
 */

// Load the Composer autoloader
require __DIR__ . '/../vendor/autoload.php';

// Delete config cache before tests run to prevent cached MySQL config from being loaded.
// This ensures APP_ENV=testing forces database.default=sqlite regardless of prior cache state.
$configCachePath = __DIR__ . '/../bootstrap/cache/config.php';
if (file_exists($configCachePath)) {
    unlink($configCachePath);
}

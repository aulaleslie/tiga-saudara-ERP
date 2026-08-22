<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Ensure database directory and test SQLite file exist
        $databaseDir = __DIR__.'/../database';
        if (!is_dir($databaseDir)) {
            mkdir($databaseDir, 0755, true);
        }

        // Create empty test database file if it doesn't exist
        // SQLite will populate it with schema during migrations
        $testDbPath = $databaseDir . '/testing.sqlite';
        if (!file_exists($testDbPath)) {
            touch($testDbPath);
        }

        // Fail-fast guard: Verify testing environment is isolated
        // Prevents accidental mutations to development database
        $this->verifyTestDatabaseIsolation($app);

        return $app;
    }

    /**
     * Verify that testing environment uses an isolated SQLite database.
     *
     * @throws \RuntimeException if database configuration is unsafe
     */
    private function verifyTestDatabaseIsolation($app): void
    {
        $dbConnection = $app['config']['database.default'];
        $dbConfig = $app['config']["database.connections.{$dbConnection}"];

        if ($dbConnection === 'mysql_test') {
            $database = $dbConfig['database'] ?? '';
            if (!str_ends_with($database, '_test')) {
                throw new \RuntimeException(
                    "Testing MySQL environment database must end in '_test', got: '{$database}'. Aborting."
                );
            }
            return;
        }

        if ($dbConnection !== 'sqlite') {
            throw new \RuntimeException(
                "Testing environment resolved to non-SQLite connection: {$dbConnection}. " .
                "This would use the development database and cause data loss. Aborting test execution."
            );
        }

        $database = $dbConfig['database'] ?? '';

        if (empty($database) || $database === ':memory:') {
            throw new \RuntimeException(
                "Testing environment has no dedicated SQLite database file. " .
                "Expected a file path like 'database/testing.sqlite', got: '{$database}'. Aborting."
            );
        }

        if (strpos($database, 'testing') === false) {
            throw new \RuntimeException(
                "Testing database does not appear to be isolated. " .
                "Expected a path containing 'testing', got: '{$database}'. Aborting."
            );
        }
    }
}

## Why

The test environment is not safely isolated: PHPUnit enters `APP_ENV=testing`, but cached Laravel configuration can still point the app at the local MySQL development database. This causes `RefreshDatabase`, artisan test commands, and migration-based tests to mutate or destroy developer data, which is unacceptable for routine test execution.

## What Changes

- Standardize automated test execution on a dedicated file-backed SQLite database instead of the developer's MySQL database.
- Define a testing bootstrap path that remains correct even when Laravel config is cached for non-testing environments.
- Establish explicit rules for PHPUnit, artisan test commands, and migration refresh flows so test runs never reuse the local development connection.
- Document the expected lifecycle of the test database file, including creation, reset, and cleanup behavior.

## Capabilities

### New Capabilities
- `test-suite-sqlite-isolation`: Automated test execution uses an isolated SQLite database file and must not touch the configured local development database.

### Modified Capabilities
<!-- None -->

## Impact

- Affected systems: Laravel testing bootstrap, database configuration, PHPUnit configuration, artisan test flows, and test-support tooling.
- Affected code: `phpunit.xml`, `.env.testing`, `config/database.php`, bootstrap/config-cache handling, `tests/`, and any scripts or documentation used to run tests.
- Developer workflow impact: local and CI test runs become deterministic and non-destructive.

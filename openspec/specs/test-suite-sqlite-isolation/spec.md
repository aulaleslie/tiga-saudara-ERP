# test-suite-sqlite-isolation Specification

## Purpose
TBD - created by archiving change harden-phpunit-sqlite-isolation. Update Purpose after archive.
## Requirements
### Requirement: Automated tests use a dedicated SQLite database file
The system SHALL execute automated test runs against a dedicated file-backed SQLite database and SHALL NOT reuse the developer's normal database connection.

#### Scenario: PHPUnit boot resolves dedicated SQLite file
- **WHEN** PHPUnit or `php artisan test` boots the application in the testing environment
- **THEN** the default database connection is `sqlite`
- **AND** the configured SQLite database points to the dedicated test database file
- **AND** the resolved connection is distinct from the local development database

### Requirement: Testing bootstrap ignores non-testing cached database config
The system SHALL prevent cached non-testing database configuration from overriding the testing database connection.

#### Scenario: Normal config cache exists
- **WHEN** the repository contains a cached Laravel config file generated from the normal development environment
- **AND** automated tests boot with `APP_ENV=testing`
- **THEN** the testing bootstrap does not use that cached database configuration
- **AND** the resolved testing connection remains the dedicated SQLite database file

### Requirement: Unsafe test database resolution fails fast
The system SHALL abort automated test execution before destructive database work begins when the resolved testing connection is not the dedicated SQLite database.

#### Scenario: Testing boot resolves MySQL or another unsafe connection
- **WHEN** automated tests start in the testing environment
- **AND** the resolved default database connection is not the dedicated SQLite test database
- **THEN** the test bootstrap fails immediately with an explicit configuration error
- **AND** no test migration or database refresh operation is executed against the unsafe connection

### Requirement: Dedicated test database file lifecycle is deterministic
The system SHALL create or reuse the dedicated SQLite test database file in a deterministic way that supports repeated local and CI test runs.

#### Scenario: Test database file is missing
- **WHEN** automated tests start and the dedicated SQLite database file does not yet exist
- **THEN** the test setup creates the file before migration-backed tests run
- **AND** subsequent test database refresh operations use that file without requiring manual developer setup


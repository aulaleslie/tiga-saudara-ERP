## 1. Testing Bootstrap Isolation

- [x] 1.1 Point PHPUnit and the testing environment at a dedicated file-backed SQLite database path.
- [x] 1.2 Redirect the testing config-cache lookup so automated tests do not load the normal cached MySQL configuration.
- [x] 1.3 Add a fail-fast guard in the test bootstrap path that aborts when `APP_ENV=testing` resolves to an unsafe database connection.

## 2. Test Database Lifecycle

- [x] 2.1 Ensure the dedicated SQLite database file is created on demand and remains untracked in version control.
- [x] 2.2 Update test-support code, helper scripts, and any documented test commands that still assume `:memory:` SQLite or the local MySQL database.
- [x] 2.3 Review migration-backed tests for any immediate connection assumptions that would bypass the dedicated SQLite test database.

## 3. Verification

- [x] 3.1 Verify the testing environment reports SQLite and no longer reports cached MySQL database configuration during automated test boot.
- [x] 3.2 Run representative migration-backed PHPUnit and artisan test flows to confirm they operate on the dedicated SQLite file instead of the developer database.
- [x] 3.3 Capture any newly exposed SQLite compatibility follow-ups separately if isolation succeeds but individual tests still fail for database-specific reasons.

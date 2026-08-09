## 1. Backup Configuration and Command Foundation

- [x] 1.1 Add environment-backed configuration for the MySQL dump executable, destination directory, two final slot filenames, temporary workspace settings, and optional archive password; document safe Windows defaults in `.env.example`.
- [x] 1.2 Implement a dedicated database backup Artisan command that reads the active MySQL connection settings and validates the dump executable and destination before work begins.
- [x] 1.3 Build the `mysqldump` child process with single-transaction, schema/data, routines, events, and triggers enabled; keep the database password out of process arguments.

## 2. Safe Archive and Retention Workflow

- [x] 2.1 Create a unique same-volume temporary workspace and write the SQL dump there, capturing process output and failure status.
- [x] 2.2 Package a successful non-empty dump into a non-empty ZIP archive, enabling archive encryption only when an archive password is configured.
- [x] 2.3 Select the missing or oldest of the two configured final slots and atomically promote the temporary archive only after every validation step succeeds.
- [x] 2.4 Add guaranteed/best-effort temporary workspace cleanup, success output with final slot and size, non-zero failure exits, and application logging for all error paths.

## 3. Automated Verification

- [x] 3.1 Add focused command tests for successful first-slot creation and alternate-slot replacement while preserving the newer existing slot.
- [x] 3.2 Add focused failure-path tests for a missing executable, an unwritable destination, failed dump/archive work, and preservation of existing final archives.
- [x] 3.3 Add tests asserting the dump process includes single-transaction, routines, events, triggers, and uses configured connection values without exposing the password in its arguments.
- [x] 3.4 Run the focused backup tests and the applicable Laravel test suite; record any Windows-only manual validation required by the environment.

## 4. Windows Deployment Documentation

- [x] 4.1 Document production `.env` configuration for `C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe`, `D:\Backup_WK`, slot rotation, and optional archive password handling.
- [x] 4.2 Document the direct Windows Task Scheduler task: hourly trigger at minute 05, `php.exe artisan <backup-command>`, Laravel root as Start in directory, run-as account permissions, missed-run handling, and no-overlap policy.
- [x] 4.3 Document manual backup verification, two-run rotation verification, Drive sync confirmation, and a safe restore test into a new temporary MySQL database.

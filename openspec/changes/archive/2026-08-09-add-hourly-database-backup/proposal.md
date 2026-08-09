## Why

The ERP's operational configuration and business data are stored in its MySQL database, but the existing backup package is configured to archive the whole application and no backup is scheduled. A Windows deployment needs a small, recoverable, bounded-storage database backup that can be triggered hourly by Windows Task Scheduler and copied off-server by the operator's Drive sync.

## What Changes

- Add a database-only Artisan backup command for the configured MySQL connection.
- Create compressed SQL dumps with a safe temporary-file-to-final-file promotion so a failed dump cannot replace the last successful backup.
- Retain a fixed two-slot rotating backup set in the configured Windows backup directory, preventing unbounded disk growth while keeping one fallback copy.
- Make the MySQL dump executable path, destination directory, slot names, and optional archive encryption password environment-configurable.
- Provide command output and application logging suitable for diagnosing a scheduled execution.
- Document the Windows Task Scheduler configuration that the deployment operator must create, including hourly triggering, missed-run handling, working directory, permissions, and no-overlap behavior.

## Capabilities

### New Capabilities

- `hourly-database-backup`: Produce a complete, consistent, bounded-retention backup of the application's configured MySQL database through an operator-scheduled Artisan command on Windows.

### Modified Capabilities

- None.

## Impact

- Affected code: Laravel console commands and backup configuration/environment example.
- Affected systems: MySQL client tools (`mysqldump.exe`), `D:\Backup_WK`, Windows Task Scheduler, and the operator-managed Drive sync.
- Dependencies: uses the already-installed MySQL 8 client executable and Laravel configuration; no new Composer dependency is required.
- Operations: a deployment operator creates and owns the Windows scheduled task; the application does not create a Windows task automatically.

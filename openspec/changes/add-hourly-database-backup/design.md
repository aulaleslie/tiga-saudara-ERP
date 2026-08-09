## Context

This Laravel 10 ERP uses a single configured MySQL connection. Spatie Laravel Backup 9 is installed, but its checked-in configuration includes the application filesystem and produces timestamped archives; no scheduled backup is defined. The Windows server has MySQL 8's `mysqldump.exe` at `C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe`. The operator will use Windows Task Scheduler to invoke an Artisan command hourly and will sync `D:\Backup_WK` to Drive. The server is routinely off overnight.

The system needs a consistent database-only recovery artifact without allowing an unsuccessful backup or a growing archive history to exhaust the local backup disk.

## Goals / Non-Goals

**Goals:**

- Produce a complete logical backup of the configured MySQL database, including schema, data, triggers, routines, and events.
- Preserve the prior successful backup when the current dump, compression, validation, or final promotion fails.
- Keep exactly a bounded two-slot local backup set suitable for Drive synchronization.
- Make Windows paths and operational settings deployable through environment configuration.
- Provide an Artisan command that is safe for the operator to call directly from Windows Task Scheduler.

**Non-Goals:**

- Create, configure, or manage a Windows Task Scheduler task from Laravel.
- Back up application source code, `.env`, uploaded files, MySQL binary data files, or Drive configuration.
- Supply continuous point-in-time recovery, multi-server replication, or cloud-retention administration.
- Automatically restore a production database.

## Decisions

### Use a dedicated Artisan command rather than the existing Spatie archive command

The application will add a dedicated database backup command. It will read Laravel's active MySQL connection configuration rather than duplicate connection credentials in a shell script.

Spatie's `backup:run --only-db` is not selected because it writes its generated archive directly to the destination and is designed around timestamped backup collections and cleanup. A dedicated command can make safe final-file promotion and two-slot retention explicit.

Alternatives considered:

- **Spatie only-db with a fixed filename**: lower code volume, but no explicit atomic promotion contract and destination behavior is coupled to the package.
- **PowerShell-only scheduled dump**: workable, but duplicates database credentials and operational behavior outside application configuration.

### Execute the configured `mysqldump.exe` securely and consistently

The command will require a configured executable path, defaulting to the known Windows MySQL 8 path. It will validate that the executable is available before starting work. It will pass the host, port, username, and database name as process arguments, and provide the password through the child-process environment (not a visible command-line argument).

The dump command will use `--single-transaction`, `--routines`, `--events`, and `--triggers`. This provides a non-blocking consistent snapshot for the expected InnoDB ERP schema and includes database objects required for restoration.

Alternatives considered:

- **Copying MySQL data files**: unsafe for a running MySQL server without a physical-backup workflow.
- **PHP table-by-table export**: less complete and less reliable for MySQL objects.

### Produce a temporary compressed archive, validate it, then atomically promote it

`mysqldump` will write an SQL file inside a unique working directory on the same destination volume. The command will package it into a temporary ZIP archive, optionally encrypting the ZIP using an environment-provided password. Before promoting the archive, the command will confirm the dump exited successfully and the archive exists with non-zero size. It will then rename the temporary archive to the selected final slot on `D:\Backup_WK`; same-volume rename keeps the last good final file intact until the new artifact is complete.

Temporary files will be removed after success and best-effort removed after failure. The task will fail with a non-zero exit status and log the reason when any stage fails.

Alternatives considered:

- **Write directly to the final filename**: can destroy the only viable backup on interruption or disk-full failure.
- **Plain `.sql` only**: simplest restoration but consumes more Drive and local storage; a ZIP retains a familiar Windows restoration artifact and can be encrypted.

### Retain two fixed backup slots

The command will retain `database-backup-a.zip` and `database-backup-b.zip` by default. Each successful run writes the older/missing slot, alternating replacement without needing a database state record. At most two final archives exist; temporary working files are excluded from the retention count and removed after each run.

Alternatives considered:

- **One fixed file**: lowest disk use, but no fallback after a newly completed yet unusable backup.
- **Timestamped backups plus cleanup**: preserves history but conflicts with the operator's bounded-storage requirement and complicates Drive sync.

### Windows Task Scheduler owns scheduling

Laravel will expose the backup command but will not add it to `app/Console/Kernel.php`. Deployment documentation will direct the operator to invoke it directly every hour at minute 05, with the project root as the working directory, no concurrent instance, and “run as soon as possible after a missed start.” This works when the server is off overnight: no backup runs while it is off and one run occurs after startup.

Alternatives considered:

- **Laravel `schedule:run` every minute**: appropriate for a broader app scheduler, but adds an unnecessary outer scheduler for this one deployment-owned task.
- **Shutdown event backup**: unreliable during abrupt shutdown and may not give Drive time to sync.

## Risks / Trade-offs

- [A non-InnoDB table cannot participate in an InnoDB-consistent `--single-transaction` snapshot] → Document the assumption and surface `mysqldump` failures; assess table engines before production rollout.
- [The configured executable path is missing or inaccessible] → Fail before replacing a slot and show the exact configuration error.
- [Backup directory is not writable by the scheduled-task account] → Validate directory creation/writability before dumping and document the required Modify permission.
- [The server is off at a scheduled time] → Windows Task Scheduler runs once after startup when its missed-run option is enabled.
- [Drive sync has not completed before a server/disk failure] → Keep two local slots, monitor sync health operationally, and verify an off-server restore periodically.
- [An encrypted archive password is lost] → Treat the password as recovery-critical deployment secret and document storage outside the archive and repository.
- [A logical dump does not give point-in-time recovery] → Scope remains hourly snapshots; recovery granularity is bounded by the last completed backup.

## Migration Plan

1. Deploy the command, configuration defaults, environment example entries, and Windows scheduler documentation.
2. Configure the production `.env` with `D:\Backup_WK`, the confirmed `mysqldump.exe` path, and an archive password if encryption is enabled.
3. Grant the task account Modify permission to the destination directory.
4. Run the command manually; confirm an archive is created and a second run creates/updates the alternate slot.
5. Restore a synced archive into a newly created temporary MySQL database and verify the expected schema/data objects.
6. Create the Windows Task Scheduler task. It directly invokes the Artisan command at minute 05 every hour, prevents concurrent execution, and runs a missed execution after startup.

Rollback is to disable the Windows scheduled task and remove the new backup configuration/command in a future release. Existing backup archives remain available for manual restoration.

## Open Questions

- None for implementation. Archive encryption will be enabled when a non-empty deployment password is configured and otherwise remain disabled, preserving a straightforward documented restore path.

## Context

`DatabaseBackupService` currently derives its unique temporary workspace from `destination_dir`. On production Windows, that destination is `D:\Backup_WK`, which is watched by Google Drive. Drive therefore observes the intermediate SQL dump and ZIP, then retains their deletion in trash. The scheduled command already runs successfully every 15 minutes with overlapping executions disabled, and its two-slot rotation must remain intact.

The existing backup contract requires a completed archive to be promoted by a same-volume rename so an incomplete file never appears as a final backup. Separating the directories must preserve that property. The scheduled-task account is the operational stakeholder and needs Modify access to both locations.

## Goals / Non-Goals

**Goals:**

- Keep all dump, compression, and cleanup activity outside the synchronized destination.
- Leave only the two configured completed ZIP slots in the destination.
- Preserve validation, failure isolation, cleanup, encryption, logging, and slot rotation behavior.
- Preserve same-volume rename promotion on Windows.
- Provide safe configuration defaults and focused automated coverage.

**Non-Goals:**

- Change the ZIP contents, encryption format, dump flags, slot naming, or restore procedure.
- Add cloud-provider APIs or manage Google Drive synchronization and trash directly.
- Move scheduling into Laravel or modify Windows Task Scheduler automatically.
- Add timestamped retention or more than two final backup slots.

## Decisions

### Introduce a dedicated environment-backed working directory

Add `working_dir` to `config/database-backup.php`, backed by `DB_BACKUP_WORKING_DIR`. On Windows it defaults to `D:\Backup_Work`; on other platforms it defaults to a distinct path under Laravel storage. `destination_dir` remains the location of final archives only.

The service will create each unique `temp_*` workspace beneath `working_dir`. Both `dump.sql` and `temp_backup.zip` remain there until the archive is validated and promoted. This is preferred over the system temporary directory because the deployment needs predictable permissions and same-volume placement.

Alternative considered: use `sys_get_temp_dir()`. It provides separation but may be on `C:` while the destination is on `D:`, preventing an atomic same-volume rename.

### Require separate directories on the same volume

Configuration validation will reject equivalent working and destination paths. On Windows it will also verify that their drive roots match before creating a dump. Documentation will explicitly configure `D:\Backup_Work` and `D:\Backup_WK`.

The directories must differ to keep residual files outside Drive sync, while the shared volume preserves atomic promotion. The check should normalize trailing separators and compare Windows paths case-insensitively.

Alternative considered: allow cross-volume promotion with `copy()` followed by deletion. A copied archive could be visible to Google Drive before it is complete and does not preserve the existing atomic-promotion guarantee.

### Promote only the completed archive into the selected final slot

After dump and ZIP validation, the service continues to select the missing or oldest slot and renames the completed archive from the working directory to that destination path. Cleanup then removes the remaining SQL file and unique workspace. No `.part`, SQL, or temporary directory is created in the destination.

Existing final-slot replacement behavior is retained. A failed dump, archive, validation, or promotion leaves both existing slots untouched and cleans the run-specific workspace on a best-effort basis.

### Limit verification to affected backup behavior

Implementation verification will run the focused backup feature, process, and encryption tests. Tests will use distinct working and destination test directories and assert that successful and failed runs leave no temporary artifacts under the destination. The full application test suite is outside this corrective change.

## Risks / Trade-offs

- [The working directory is configured on another Windows drive] → Fail configuration validation before dumping and explain that both directories must be on the same volume.
- [The scheduled-task account lacks permission on the new directory] → Validate creation and writability before dumping and document Modify permission for both directories.
- [Existing deployments omit the new setting] → Supply a Windows default on `D:` and document the required production `.env` value; deployment verification must confirm the directory is not synchronized.
- [Path aliases or junctions make lexical path comparison imperfect] → Normalize ordinary separators, trailing separators, and case; document that junction-based or mapped-drive aliases are unsupported for the atomicity guarantee.
- [A process is terminated before `finally` cleanup] → A stale workspace may remain only in the unsynchronized working directory; it does not pollute Drive or replace a valid backup.

## Migration Plan

1. Deploy the configuration, service, focused tests, and documentation changes.
2. Create `D:\Backup_Work` outside Google Drive synchronization and grant the scheduled-task account Modify permission.
3. Set `DB_BACKUP_WORKING_DIR="D:\Backup_Work"` while retaining `DB_BACKUP_DESTINATION_DIR="D:\Backup_WK"`, then clear/rebuild Laravel's configuration cache if used.
4. Run `php artisan db:backup` manually and confirm the working directory is cleaned and only a final rotating ZIP changes in the destination.
5. Let the 15-minute task run and verify Google Drive no longer receives SQL, temporary ZIP, or temporary-directory trash entries.

Rollback is to disable the scheduled task before reverting the application. Reverting to the old behavior while Drive sync remains enabled would reintroduce the reported storage issue; existing final ZIP files remain usable throughout rollback.

## Open Questions

None. The known production paths support a separate same-volume working directory, and the existing two-slot destination behavior remains authoritative.

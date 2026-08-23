## Why

The database backup command currently creates and deletes its SQL dump, temporary ZIP, and workspace inside the Google Drive-synchronized destination. Every 15-minute run therefore uploads short-lived artifacts and moves them to Drive trash, consuming cloud storage and creating unnecessary sync activity.

## What Changes

- Add an environment-configurable backup working directory that is separate from the final backup destination.
- Create the SQL dump and temporary ZIP only inside a unique workspace beneath the unsynchronized working directory.
- Keep the two rotating final ZIP slots in the existing destination directory and promote only a completed, validated archive there.
- Require the Windows working and destination directories to be on the same volume so final promotion can retain same-volume rename semantics without exposing a partial archive to synchronization.
- Validate and clean up the working directory independently from the final destination.
- Update focused backup tests, `.env.example`, and Windows deployment guidance for the separate-directory setup and the existing 15-minute schedule.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `hourly-database-backup`: Change temporary workspace placement from the synchronized destination directory to a separately configured, same-volume working directory while leaving only completed rotating archives in the destination.

## Impact

- Affected code: `config/database-backup.php`, `app/Services/DatabaseBackupService.php`, and focused backup tests.
- Affected configuration: add `DB_BACKUP_WORKING_DIR`; retain the existing destination and slot settings.
- Affected operations: Windows deployments create an unsynchronized working directory on the same volume as `D:\Backup_WK` and grant the scheduled-task account Modify permission.
- Affected documentation: `.env.example` and `docs/DATABASE-BACKUP-WINDOWS-DEPLOYMENT.md`.
- Dependencies and command interface: no new package dependency and no change to the `php artisan db:backup` command name.

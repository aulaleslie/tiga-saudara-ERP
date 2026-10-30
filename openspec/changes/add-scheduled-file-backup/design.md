# Design

## Context

See proposal.md for motivation. `config/filesystems.php` maps the public disk to `storage/app/public` and `public/storage` to that directory. Installed Media Library defaults to the public disk; product images, avatars, business attachments, payment proofs, and return shipping attachments use it. Import controllers and jobs retain source files and results beneath `storage/app/imports`, including database-referenced result CSVs. `imports/sales/temp` is staging. Other staging includes `storage/app/temp` and `storage/app/pos/temp_payment_images`.

`DatabaseBackupService` already creates ZIPs using ZipArchive, selects missing/older slots, promotes with rename, and cleans a per-run workspace. `config/database-backup.php` exposes working directory, destination, filenames, and optional password; Windows defaults are `D:\Backup_Work` and `D:\Backup_WK`. The database command is operator-scheduled, not registered in the Laravel scheduler. Existing backup feature and encryption tests provide nearby conventions.

Design is warranted by failure-safe replacement, Windows filesystem behavior, and archive integrity concerns.

## Goals / Non-Goals

**Goals:** Recover the two identified persistent file trees; reuse existing backup locations; bound retained archive count; expose failures without destroying prior recovery artifacts.

**Non-Goals:** Scheduler setup or cadence, cloud storage integration, application-code or secret backup, database backup refactoring, automated restore, coordinated database/filesystem snapshots, full application test execution.

## Decisions

### Dedicated command and service, shared path configuration

Add `BackupFilesCommand`, `FileBackupService`, and small `config/file-backup.php` for distinct slot names. Resolve working directory, destination, and optional password at runtime from `config('database-backup')`. Do not duplicate destination environment variables or change existing database behavior. Reject file slot names that collide with database slots, are equal to each other, or contain directory traversal/separators. Reuse the database archive password by default so recovery configuration stays consistent.

Alternative: Spatie backup collections introduce timestamp retention/cleanup behavior beyond the required two-slot contract. Avoid a broad shared-service refactor in this change.

### Explicit source trees and portable layout

Read `storage/app/public` and `storage/app/imports` directly; exclude `imports/sales/temp` and repository placeholder `.gitignore` files. Everything else outside these trees is excluded by construction. Preserve originals and conversions alike. Store a root `manifest.json` and payloads under `public/` and `imports/`.

Missing source directories are recorded as absent, supporting fresh installations; existing unreadable directories or files fail. Never follow descendant symlinks/junctions outside source roots; reject unsupported links rather than silently claiming coverage. Validate resolved paths with Windows case-insensitive comparison where applicable. Fail when working/destination paths overlap each other unsafely or overlap source trees. Production deployment must verify the active media disk is local/public; unsupported media overrides fail clearly rather than claim complete coverage.

Alternative: archiving all `storage/` collects logs, caches, temporary uploads, and potentially backups. Archiving only the public tree loses retained imports referenced by batch records.

### Build, verify, then promote

Acquire a nonblocking process-scoped filesystem lock in the shared working directory; hold it through slot selection, promotion, and cleanup. Use a distinct lock and unique file-backup workspace so database runs can coexist. OS file locks release on process termination without cache TTL expiry permitting a second run.

Enumerate files, stream their metadata/checksums, add them to the ZIP without loading entire files into memory, and finalize a manifest with UTC start/end timestamps and counts. Detect changes to discovered files during capture/verification and fail rather than silently omit them. Newly created files after enumeration belong to a later run; this is not a filesystem snapshot.

If password is nonempty, encrypt every entry including manifest using AES-256; unsupported encryption fails. Reopen the completed archive, read entries in streams, and verify sizes/checksums against the manifest, including entry count. Manifest-only archives are valid for empty installations.

Select a missing slot or the oldest modification time (A on ties). Promote by same-volume replacement without pre-deleting the old slot. Windows rename/locked-file failure must return failure and retain both old slots; no delete-then-rename fallback. Clean only the run-specific workspace in finally and release the lock. Leave the lock file outside the synchronized destination. Log concise diagnostics without secrets.

Alternative: writing directly to a final ZIP risks destroying a usable backup. Checking ZIP size alone does not establish payload readability. A general cache lock can expire while a large backup still runs.

### Focused verification and recovery documentation

Use isolated temporary fixtures with actual ZIP payload extraction, three-run rotation, failure injection, manifest/hash verification, password reads, empty/missing sources, exclusions, path/collision validation, and concurrent lock contention. Use deterministic timestamps rather than sleeps. Run only new file-backup tests and relevant existing database-backup tests when configuration integration affects them; no full suite or fresh-database suite is planned.

Document manual invocation and recovery in a dedicated file-backup guide. Restore into an isolated temporary directory first, validate checksums, then place `public/` and `imports/` under `storage/app` during an operator-controlled recovery window. Recreate `public/storage` with `php artisan storage:link` if needed; validate representative attachment records against files. Do not introduce task-scheduler steps or Laravel schedule changes.

## Risks / Trade-offs

- [Archives grow with uploads] -> Two final slots bound count, not bytes. Account for two retained ZIPs plus a new working ZIP, manifest, and verification overhead; document production capacity checks.
- [Two slots provide short deletion history] -> Document the limitation; longer retention is outside the agreed bounded rotation.
- [Independent snapshots can disagree] -> Record file run timestamps; document attachment-reference checks and operator selection of compatible database/file backups. No atomic pairing is promised.
- [Live modifications interrupt a run] -> Preserve existing slots and report the affected path; operator can retry during a quieter period.
- [Cloud sync or Windows antivirus locks destination] -> Fail promotion without deleting old slots; operator retries. Explicit Windows manual replacement verification remains necessary.
- [Local checkout contains almost no files] -> Test meaningful fixtures locally and document a representative production-size duration/capacity check without inventing server measurements.

## Migration Plan

No schema migration. Deploy command/service/config and guide; keep current database backup settings. Operator verifies production media disk, directory permissions, encryption capability, capacity, manual invocation, three-run rotation, and isolated extraction. Scheduler execution remains entirely operator-managed and outside this change. Rollback stops invoking the command and reverts the added code; retain completed file archives for recovery.

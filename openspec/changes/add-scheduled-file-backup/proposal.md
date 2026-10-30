# Proposal

## Why

Database backups preserve attachment metadata but cannot recover uploaded images, payment proofs, or other local files. Add a bounded file backup alongside the existing database archives so recovery includes both records and their files without moving uploads to an external storage service.

## What Changes

- Provide `files:backup` to archive permanent public media and retained import sources/results with restorable relative paths.
- Reuse the database backup destination and working-directory configuration, retaining two distinct rotating file ZIP archives alongside the database ZIPs.
- Validate completed archives before promotion; preserve existing backups on failure, prevent concurrent file backups, and report results through console output and logs.
- Include a verification manifest, support optional archive encryption, and handle empty installations.
- Document manual invocation and restoration. Scheduler setup, scheduling cadence, and Laravel schedule changes are outside scope and remain operator-managed.
- Limit implementation verification to focused file-backup tests and relevant database-backup regression checks; do not plan or run the full application suite.

## Capabilities

### New Capabilities

- `local-file-backup`: Recoverable, bounded two-slot archives of local permanent application files, sharing the database backup destination.

### Modified Capabilities

None. Existing `hourly-database-backup` behavior remains unchanged.

## Impact

New console command, file-backup service/configuration, focused tests, environment examples if needed, and recovery documentation. Reuse PHP ZipArchive and existing Laravel facilities; no new cloud service or dependency is required. Production disk usage includes two retained file archives plus temporary space for the archive being built. Independent file and database backups do not constitute a coordinated snapshot.

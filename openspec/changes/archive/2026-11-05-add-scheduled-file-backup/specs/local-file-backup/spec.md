# Spec Delta

## Purpose

Preserve local application uploads and retained import artifacts in recoverable archives with bounded retention alongside database backups.

## ADDED Requirements

### Requirement: Local permanent file coverage
The system SHALL provide `files:backup` to archive `storage/app/public` and `storage/app/imports`, preserving paths relative to `storage/app`. It MUST exclude `imports/sales/temp`, upload staging, POS temporary payment images, caches, logs, and backup artifacts. It MUST read actual source directories rather than traverse `public/storage`.

#### Scenario: Permanent files and staging coexist
- **WHEN** public media, import sources/results, and staging files exist
- **THEN** the archive contains permanent media and retained imports under `public/` and `imports/` with original relative paths, and excludes staging files

#### Scenario: Empty installation
- **WHEN** source directories are empty or have not yet been created
- **THEN** the command creates a valid manifest-bearing archive recording zero payload files and any absent source directories

### Requirement: Shared backup directories
The system SHALL obtain its destination and working directory from the existing database backup configuration, with no independent file-backup destination. Temporary artifacts MUST stay outside the destination and source trees, and working and destination directories MUST be separate and on the same volume.

#### Scenario: Existing database destination changes
- **WHEN** the database backup destination is configured to a different directory
- **THEN** file backups use that directory without a second destination setting

#### Scenario: Unsafe directory configuration
- **WHEN** directories overlap source trees, working storage is inside the destination, or working and destination volumes differ
- **THEN** the command fails before archiving or replacing a backup

### Requirement: Bounded safe rotation
The system SHALL retain only two final file archives, defaulting to `files-backup-a.zip` and `files-backup-b.zip`. It MUST choose a missing slot or the older slot and promote only a completed, validated archive. Failure MUST preserve both existing slots and attempt temporary workspace cleanup. Database archives MUST remain untouched.

#### Scenario: Three successful executions
- **WHEN** the command succeeds three times with no initial file archives
- **THEN** the first two runs populate the two slots and the third replaces only the older slot

#### Scenario: Failed run
- **WHEN** reading a discovered source file, compression, verification, or promotion fails
- **THEN** both prior file archives and all database archives remain unchanged and the command reports failure

### Requirement: Verifiable archives
The system SHALL include a manifest with run start/end timestamps, source coverage, relative file paths, file sizes, modification times, SHA-256 checksums, and total payload count and bytes. Before promotion it MUST reopen the archive and verify readable payload entries against the manifest. Optional password encryption MUST protect all entries including the manifest.

#### Scenario: Archive verification detects corruption
- **WHEN** an archived entry cannot be read or differs from its recorded size or checksum
- **THEN** the command fails without promoting the archive

#### Scenario: Password configured
- **WHEN** an archive password is configured
- **THEN** payloads and manifest require that password to read and the command verifies them using it before promotion

### Requirement: Exclusive observable execution
The system SHALL prevent simultaneous file backup runs on the same server, report failures through console output and application logging, and return a non-zero exit status. Successful runs SHALL report destination slot, archive size, and payload count. It MUST NOT configure a scheduler or require one for manual execution.

#### Scenario: Concurrent invocation
- **WHEN** a file backup is already running and another invocation starts
- **THEN** the second invocation exits unsuccessfully without modifying slots or the active workspace

#### Scenario: Manual execution
- **WHEN** an operator invokes `files:backup` with valid configuration
- **THEN** the command completes independently of scheduler setup and reports its result

### Requirement: Recoverable file layout
The system SHALL document manual invocation, shared path configuration, two-slot storage requirements, archive verification, and restoration into `storage/app`. Guidance MUST explain independent database/file snapshot timing and attachment-reference checks after restoration. Scheduler installation and configuration MUST remain outside this change.

#### Scenario: Operator restores files
- **WHEN** an operator follows recovery guidance using a verified archive and a separately restored database
- **THEN** files return to their original relative locations and guidance identifies how to recreate the public storage link and check attachment references

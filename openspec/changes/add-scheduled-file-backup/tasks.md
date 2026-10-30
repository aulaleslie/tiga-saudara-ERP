# Tasks

## 1. Shared configuration and source coverage

- [x] 1.1 Add file-backup slot configuration and resolve working/destination/password from existing database-backup settings; verify focused configuration tests demonstrate destination changes apply to both commands and reject filename collisions/traversal.
- [x] 1.2 Implement source inventory for public media and retained imports, excluding sales import staging and placeholder files; verify fixture tests cover nested files, conversions, exclusions, absent/empty roots, unreadable paths, unsupported media disks, and descendant links.
- [x] 1.3 Validate resolved directory separation, source overlap, and same-volume requirements with Windows path handling; verify focused path tests including aliases and unsafe nesting.
- [x] 1.4 Start `docs/FILE-BACKUP.md` with source coverage, shared configuration, filenames, and temporary-space requirements; verify its paths match configuration and fixture archive layout.

## 2. Verified archives and safe rotation

- [x] 2.1 Implement streaming archive capture and manifest metadata/checksums with optional AES-256 encryption for every entry; verify focused archive tests read payloads and manifest, reject wrong passwords, and cover empty installations.
- [x] 2.2 Reopen completed archives and verify manifest, payload counts, sizes, and hashes; verify corruption, disappearing files, and detected source changes fail before promotion.
- [x] 2.3 Implement exclusive file-backup locking, unique workspaces, missing/older slot selection, same-volume promotion, and finally cleanup; verify concurrent invocation rejection, three-run rotation with deterministic timestamps, database-slot preservation, and injected compression/verification/promotion failures preserving prior slot bytes.
- [x] 2.4 Extend the guide with manifest verification, encryption recovery requirements, rotation behavior, and Windows locked-destination failure handling; verify examples against focused fixtures and identify Windows-only manual checks explicitly.

## 3. Console entry point and recovery

- [x] 3.1 Add `files:backup` console integration with success slot/size/count reporting, diagnostic logging, and non-zero failure exits; verify focused command tests for success, empty sources, lock contention, and service failures without requiring scheduler setup or a live database.
- [x] 3.2 Complete manual invocation and isolated extraction/restoration guidance, including placement under `storage/app`, `storage:link`, and attachment-reference checks against a separately restored database; verify with a fixture round trip and include operator checks for production size, media configuration, permissions, and Windows replacement. Do not configure or document scheduler setup.

## 4. Focused integration verification

- [x] 4.1 Run only new file-backup test classes using `php artisan test` with explicit paths or filters; record results for coverage, encryption, locking, rotation, failure preservation, and recovery round trip. Do not run or require the full suite or `composer test:fresh-sqlite`.
- [x] 4.2 Run relevant existing database-backup test classes only if shared configuration integration touches their behavior; record results or explain why unchanged database implementation does not require those regression checks.
- [x] 4.3 Review the final diff to verify no Laravel/Windows scheduler changes, cloud-storage integration, database backup behavior changes, or unrelated edits; record any Windows-only validation left for the operator without claiming it ran locally.

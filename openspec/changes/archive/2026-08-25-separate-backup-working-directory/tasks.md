## 1. Backup Directory Configuration

- [x] 1.1 Add the environment-backed `working_dir` setting with distinct Windows and non-Windows defaults in `config/database-backup.php` and `.env.example`.
- [x] 1.2 Update `DatabaseBackupService` to load, create, and validate the working directory independently from the final destination.
- [x] 1.3 Add normalized path validation that rejects matching working/destination directories and rejects different Windows volume roots before dump creation.

## 2. Separated Backup Workflow

- [x] 2.1 Create each unique run workspace beneath the configured working directory so `dump.sql` and `temp_backup.zip` never appear in the final destination.
- [x] 2.2 Preserve completed-archive validation, two-slot selection, same-volume rename promotion, failure isolation, and best-effort cleanup of only the run-specific workspace.
- [x] 2.3 Ensure success and failure messages continue to identify the relevant final destination or working-directory configuration error without changing the `db:backup` command interface.

## 3. Focused Automated Verification

- [x] 3.1 Update backup feature-test setup and configuration fixtures to use separate working and destination directories.
- [x] 3.2 Add focused success and failure assertions that temporary SQL, ZIP, and workspace artifacts are absent from the destination and cleaned from the working directory.
- [x] 3.3 Add focused validation coverage for matching directories and, through Windows-path-aware unit coverage, different volume roots while confirming existing slots remain untouched.
- [x] 3.4 Run only `tests/Feature/DatabaseBackupCommandTest.php`, `tests/Unit/DatabaseBackupServiceProcessTest.php`, and `tests/Unit/DatabaseBackupEncryptionTest.php` and resolve failures caused by this change.

## 4. Windows Deployment Documentation

- [x] 4.1 Update the Windows guide to configure unsynchronized `D:\Backup_Work` alongside synchronized `D:\Backup_WK`, including same-volume and Modify-permission requirements.
- [x] 4.2 Update Task Scheduler guidance and verification checklists for the working-directory separation, the established 15-minute interval, configuration-cache refresh, and confirmation that Drive sees only the two final ZIP slots.

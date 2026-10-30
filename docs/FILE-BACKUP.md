# Local File Backup Guide

## Overview

The `files:backup` Artisan command creates verified, two-slot rotating ZIP archives of local permanent application files (`storage/app/public` and `storage/app/imports`) alongside the existing MySQL database backup (`db:backup`).

> **Scope Note:** This guide covers configuration, archive structure, manual execution, verification, and recovery. Scheduler installation, scheduling cadence, and Laravel scheduler configuration are outside the scope of this feature and remain operator-managed.

## Source Coverage and Archive Layout

`files:backup` reads the actual source directories under `storage/app` directly (it never traverses the `public/storage` link) and preserves paths relative to `storage/app`:

| Source Root on Disk | Path Prefix in ZIP | Included Content |
| --- | --- | --- |
| `storage/app/public` | `public/` | Spatie Media Library originals, `conversions/`, `responsive-images/`, product images, avatars, business attachments, payment proofs, and return shipping attachments |
| `storage/app/imports` | `imports/` | Retained import source CSV/XLSX files and batch result CSVs (e.g., `imports/sales/*.csv`, `imports/sales/results/*.csv`, `imports/purchases/*`, `imports/expenses/*`) |
| *(Generated per run)* | `manifest.json` | Run timestamps, source root status, payload file count/bytes, and per-file size, modification time, and SHA-256 checksum |

### Exclusions and Safety Rules

- **Excluded staging inside imports:** `storage/app/imports/sales/temp` (`imports/sales/temp`) is excluded so chunked upload staging is never archived.
- **Excluded placeholders:** Repository `.gitignore` files inside `storage/app/public` and `storage/app/imports` are excluded.
- **Excluded outside source trees:** `storage/app/temp`, `storage/app/pos/temp_payment_images`, `storage/logs`, `storage/framework`, `storage/backups`, `storage/backup_work`, and `public/storage` are outside the two source roots and excluded by construction.
- **Symlinks and junctions rejected:** Any symbolic link or Windows junction at `storage/app`, `storage/app/public`, `storage/app/imports`, or any descendant path causes the backup to fail rather than follow links outside the source roots.
- **Active media disk requirement:** Media Library must use the `public` disk (`MEDIA_DISK=public` / `config('media-library.disk_name') === 'public'`) backed by the `local` driver at `storage/app/public`. Unsupported media disk overrides fail before archiving.

### Example Archive Layout

```text
files-backup-a.zip
├── manifest.json
├── public/
│   ├── 14/
│   │   ├── product.jpg
│   │   ├── conversions/
│   │   │   └── product-thumb.jpg
│   │   └── responsive-images/
│   │       └── product___media_library_original.jpg
│   └── payment_proofs/
│       └── 2026/10/proof.png
└── imports/
    ├── expenses/2026_expenses.csv
    ├── purchases/2026_purchases.csv
    └── sales/
        ├── 2026_sales.csv
        └── results/batch_1_result.csv
```

## Shared Configuration and Filenames

`files:backup` shares its working directory, destination directory, and optional archive password with `db:backup` via `config/database-backup.php` so both backup sets are written to the same destination directory without duplicating path settings.

File-specific slot filenames are configured in `config/file-backup.php`:

```env
# Shared with db:backup (config/database-backup.php)
DB_BACKUP_WORKING_DIR="D:\Backup_Work"
DB_BACKUP_DESTINATION_DIR="D:\Backup_WK"
DB_BACKUP_ARCHIVE_PASSWORD=

# Optional file-backup slot overrides (config/file-backup.php)
FILE_BACKUP_SLOT_A="files-backup-a.zip"
FILE_BACKUP_SLOT_B="files-backup-b.zip"
```

### Default Paths and Slots

- **Working directory (`DB_BACKUP_WORKING_DIR`):**
  - Windows default: `D:\Backup_Work`
  - Linux default: `storage/backup_work`
- **Destination directory (`DB_BACKUP_DESTINATION_DIR`):**
  - Windows default: `D:\Backup_WK`
  - Linux default: `storage/backups`
- **File backup slots (`FILE_BACKUP_SLOT_A` / `FILE_BACKUP_SLOT_B`):**
  - Defaults: `files-backup-a.zip` and `files-backup-b.zip`
  - Coexist alongside `database-backup-a.zip` and `database-backup-b.zip` in `DB_BACKUP_DESTINATION_DIR`.
  - Slot names must be plain filenames without path separators or `..`, must differ from each other, and must not collide with `DB_BACKUP_SLOT_A` or `DB_BACKUP_SLOT_B`.

### Directory Separation Requirements

1. `DB_BACKUP_WORKING_DIR` and `DB_BACKUP_DESTINATION_DIR` must be separate directories (neither identical nor nested inside each other).
2. Both directories must reside on the **same volume** (for example, both on `D:\`) so completed archives can be promoted atomically via same-volume rename.
3. Neither directory may equal, contain, or be nested inside `storage/app/public` or `storage/app/imports`.

## Disk Capacity and Temporary-Space Requirements

Two-slot rotation bounds the **number** of retained archives to two, not their byte size. Because archives grow with uploaded media and retained imports, provision sufficient free space on the backup volume for:

1. **Two retained file archives** in `DB_BACKUP_DESTINATION_DIR` (`files-backup-a.zip` + `files-backup-b.zip`).
2. **One in-progress working file archive** and temporary `manifest.json` in `DB_BACKUP_WORKING_DIR` (`temp_files_<id>_<timestamp>/temp_files_backup.zip`) before promotion replaces the older slot.
3. **Two retained database archives** (`database-backup-a.zip` + `database-backup-b.zip`) and any concurrent database working dump/archive.

As a rule of thumb, free disk space on the backup volume must comfortably exceed **3x the compressed size of `storage/app/public` + `storage/app/imports`** in addition to database backup space.

## Manifest Structure and Archive Verification

Every archive generated by `files:backup` contains a root `manifest.json` file recording UTC run timestamps, source coverage, total payload counts/bytes, and per-file metadata:

```json
{
    "version": 1,
    "started_at": "2026-10-30T15:00:00Z",
    "completed_at": "2026-10-30T15:00:01Z",
    "sources": {
        "public": {
            "root": "public",
            "exists": true,
            "file_count": 2,
            "total_bytes": 55
        },
        "imports": {
            "root": "imports",
            "exists": true,
            "file_count": 1,
            "total_bytes": 43
        }
    },
    "excluded": [
        "imports/sales/temp",
        ".gitignore"
    ],
    "total_files": 3,
    "total_bytes": 98,
    "files": [
        {
            "path": "public/products/conversions/item-thumb.png",
            "size": 28,
            "mtime": 1700000200,
            "modified_at": "2023-11-14T22:16:40Z",
            "sha256": "c6f0b006a5f087cf840c0154266ca1cf92f2957a29bf6ef14d181a80ff68ab0e"
        }
    ]
}
```

### Automatic Pre-Promotion Verification

Before replacing any destination slot, `files:backup`:

1. Verifies that no discovered source file was modified or deleted while the archive was being built.
2. Reopens the completed temporary ZIP archive from `DB_BACKUP_WORKING_DIR`.
3. Streams `manifest.json` and every listed payload entry, confirming that the archive entry count equals `total_files + 1` and that every payload entry's uncompressed byte count and SHA-256 digest match the manifest.
4. For fresh or empty installations where `storage/app/public` or `storage/app/imports` is empty or not yet created, produces and verifies a valid manifest-only archive (`total_files: 0`, `total_bytes: 0`).

## Archive Encryption Requirements

When `DB_BACKUP_ARCHIVE_PASSWORD` is set to a non-empty value:

- Every entry in the file archive—including `manifest.json` and all files under `public/` and `imports/`—is encrypted using **AES-256** (`ZipArchive::EM_AES_256`).
- If the PHP `zip` / `libzip` runtime does not support AES-256 encryption, `files:backup` fails immediately without modifying existing backup slots.
- **Recovery requirement:** Keep `DB_BACKUP_ARCHIVE_PASSWORD` in secure operator secret storage outside the repository and server disk. Standard Windows tools that support WinZip AES-256 (such as **7-Zip** or PHP `ZipArchive`) are required to extract password-protected archives; built-in Windows Explorer ZIP extraction does not support AES-256 encrypted ZIPs.

## Two-Slot Rotation, Locking, and Failure Handling

### Exclusive File-Backup Lock

- Each run acquires a non-blocking OS file lock at `<DB_BACKUP_WORKING_DIR>/file-backup.lock` and holds it through source enumeration, archive construction, verification, slot selection, promotion, and workspace cleanup.
- If another `files:backup` process is already running, the second invocation exits immediately with a non-zero status without modifying destination slots or the active workspace.
- Because `file-backup.lock` and `temp_files_<id>_<timestamp>` live in `DB_BACKUP_WORKING_DIR`, they are never uploaded by cloud-sync clients watching `DB_BACKUP_DESTINATION_DIR`, and `db:backup` can run concurrently without lock or workspace collisions.

### Slot Selection and Safe Promotion

1. **Run 1 (no file archives exist):** Creates `files-backup-a.zip`.
2. **Run 2 (`files-backup-a.zip` exists, `files-backup-b.zip` missing):** Creates `files-backup-b.zip`.
3. **Run 3+ (both slots exist):** Compares file modification timestamps and replaces only the **older** slot (choosing `files-backup-a.zip` on timestamp ties).
4. **Database slot preservation:** `database-backup-a.zip` and `database-backup-b.zip` are never modified or deleted by `files:backup`.

### Windows Locked-Destination and Mid-Run Failure Handling

- Promotion uses a direct same-volume rename from `DB_BACKUP_WORKING_DIR` into `DB_BACKUP_DESTINATION_DIR` **without pre-deleting the target slot**.
- If a cloud-sync client (Google Drive, OneDrive, Dropbox) or Windows antivirus temporarily locks the target `.zip` file in `D:\Backup_WK`, or if a source file changes mid-run, the command logs the error, cleans up only its temporary workspace in `D:\Backup_Work`, and exits with code `1`.
- Neither `files-backup-a.zip` nor `files-backup-b.zip` is deleted before replacement, preserving both existing recovery slots on failure.

## Manual Invocation

Run `files:backup` from the Laravel project root (clear cached configuration first if `.env` was modified):

```bash
php artisan config:clear
php artisan files:backup
```

- **Success (exit code `0`):** Outputs and logs the promoted slot path, archive size in bytes, and payload file count:
  ```text
  File backup successful: D:\Backup_WK\files-backup-a.zip (18452019 bytes, 412 files)
  ```
- **Failure (exit code `1`):** Outputs and logs a diagnostic message without exposing passwords or secrets, preserves existing slots, and cleans up the temporary run workspace.

> **Important:** `files:backup` is not registered in the Laravel scheduler (`app/Console/Kernel.php`), and this guide intentionally does not configure or prescribe scheduler setup. Manual execution works independently of any scheduler or live database connection.

## Isolated Extraction and Restoration Procedure

Because database backups (`db:backup`) and file backups (`files:backup`) run independently, they do not form a coordinated atomic snapshot. Follow these steps during an operator-controlled maintenance window to restore files safely:

### 1. Select Compatible Database and File Backups

1. Inspect `files-backup-a.zip` and `files-backup-b.zip` alongside `database-backup-a.zip` and `database-backup-b.zip`.
2. Choose the verified file archive whose `manifest.json` timestamps (`started_at` / `completed_at`) are closest to—and ideally at or slightly after—the restored database dump timestamp so recently recorded database attachments are present in the file archive.

### 2. Extract to an Isolated Temporary Directory First

Never extract a backup archive directly over live `storage/app`. Extract into an isolated staging directory first:

- **Unencrypted or AES-256 encrypted archive (using 7-Zip on Windows):**
  ```powershell
  mkdir D:\Restore_Staging\files_restore
  7z x "D:\Backup_WK\files-backup-a.zip" -o"D:\Restore_Staging\files_restore"
  ```
  *(Enter `DB_BACKUP_ARCHIVE_PASSWORD` when prompted if encryption is enabled.)*

- **Cross-platform extraction via PHP `ZipArchive`:**
  ```bash
  php -r '
  $zip = new ZipArchive();
  if ($zip->open("storage/backups/files-backup-a.zip") !== true) { exit(1); }
  $pw = getenv("DB_BACKUP_ARCHIVE_PASSWORD") ?: "";
  if ($pw !== "") { $zip->setPassword($pw); }
  if (!$zip->extractTo("/tmp/files_restore")) { exit(1); }
  $zip->close();
  echo "Extracted successfully\n";
  '
  ```

### 3. Verify Extracted Files Against `manifest.json`

Verify every extracted file's byte size and SHA-256 digest against the extracted `manifest.json` before moving anything into `storage/app`:

```bash
php -r '
$base = "/tmp/files_restore"; // Or D:\\Restore_Staging\\files_restore on Windows
$manifest = json_decode(file_get_contents($base . "/manifest.json"), true);
if (!is_array($manifest)) { fwrite(STDERR, "Invalid manifest\n"); exit(1); }
foreach ($manifest["files"] as $entry) {
    $file = $base . "/" . $entry["path"];
    if (!is_file($file) || filesize($file) !== $entry["size"] || hash_file("sha256", $file) !== $entry["sha256"]) {
        fwrite(STDERR, "Verification failed for: " . $entry["path"] . "\n");
        exit(1);
    }
}
echo "Verified " . $manifest["total_files"] . " files (" . $manifest["total_bytes"] . " bytes)\n";
'
```

You can also spot-check individual files manually using `Get-FileHash -Algorithm SHA256 <file>` in Windows PowerShell or `sha256sum <file>` on Linux.

### 4. Place `public/` and `imports/` Under `storage/app`

1. Place the application in maintenance mode or stop incoming uploads during replacement.
2. Move aside any existing `storage/app/public` and `storage/app/imports` directories as a rollback precaution.
3. Copy or move the verified `public` and `imports` directories from the isolated staging directory into `storage/app`:
   - `<staging>/public` -> `storage/app/public`
   - `<staging>/imports` -> `storage/app/imports`
4. Restore repository `.gitignore` placeholders in `storage/app/public/.gitignore` and `storage/app/imports/.gitignore` if desired (`git checkout -- storage/app/public/.gitignore`).

### 5. Recreate or Verify the Public Storage Link

Ensure `public/storage` points to `storage/app/public`:

```bash
php artisan storage:link
```

### 6. Check Attachment References Against the Restored Database

After restoring the MySQL database (`dump.sql`) and placing `storage/app/public` and `storage/app/imports`, verify that database attachment records resolve on disk:

1. **Spatie Media Library attachments (`media` table):**
   Verify representative records (`storage/app/public/<id>/<file_name>`) for product images, avatars, business attachments, payment proofs, and return shipping attachments:
   ```bash
   php artisan tinker --execute='
   $missing = \Spatie\MediaLibrary\MediaCollections\Models\Media::query()
       ->where("disk", "public")
       ->get()
       ->filter(fn ($m) => !file_exists($m->getPath()));
   echo "Missing media files: " . $missing->count() . PHP_EOL;
   '
   ```
2. **Retained import source and result files (`import_batches` table):**
   Verify that batch records referencing paths under `imports/` (such as `imports/sales/...`, `imports/purchases/...`, `imports/expenses/...`, and `result_file_path`) exist under `storage/app/imports/`.
3. **Handling snapshot timing differences:**
   - If the database backup is slightly newer than the file backup, media records created during the gap may report missing files and should be re-uploaded by the operator.
   - If the file backup is slightly newer than the database backup, extra files on disk are harmless or can be reconciled after verifying recent transactions.
4. Remove the isolated temporary staging directory (`D:\Restore_Staging\files_restore` or `/tmp/files_restore`) once verification completes.

## Operator Pre-Production and Windows Verification Checklist

### General Operator Checks
- [ ] **Media disk configuration:** Confirm `config('media-library.disk_name')` is `'public'` and `config('filesystems.disks.public.driver')` is `'local'` pointing to `storage/app/public`.
- [ ] **Production size and free capacity:** Measure current size of `storage/app/public` and `storage/app/imports` and confirm the backup volume (`D:\`) has free space exceeding 3x the compressed file archive size plus both database backup slots.
- [ ] **Manual invocation and three-run rotation:** Run `php artisan files:backup` three times and verify that `files-backup-a.zip` is created first, `files-backup-b.zip` second, and the older slot is replaced on the third run while `database-backup-a.zip` and `database-backup-b.zip` remain untouched.
- [ ] **Isolated restore round trip:** Extract a completed archive to an isolated temporary directory, run the `manifest.json` SHA-256 verification check, and confirm payload readability (including with `DB_BACKUP_ARCHIVE_PASSWORD` if configured).

### Windows-Only Manual Checks (Must Be Verified on the Windows Host)
The following checks depend on Windows NTFS/Win32 semantics and must be verified manually by the operator on the Windows deployment server (they cannot be claimed by Linux automated tests):
- [ ] **Windows NTFS permissions:** Confirm the operator / task-runner account has Read permission on `storage\app\public` and `storage\app\imports`, and Modify permission on `D:\Backup_Work` and `D:\Backup_WK`.
- [ ] **Windows same-volume slot replacement:** On the Windows server, run `php artisan files:backup` three times against `D:\Backup_Work` and `D:\Backup_WK` to confirm Win32 `rename()` atomically replaces an existing `.zip` slot on NTFS.
- [ ] **Windows locked-destination failure preservation:** While `D:\Backup_WK\files-backup-a.zip` and `files-backup-b.zip` both exist, hold an exclusive file handle on the older slot (or pause/lock via a Windows process) and run `php artisan files:backup`; confirm the command exits with code `1`, cleans `D:\Backup_Work\temp_files_*`, and leaves both `files-backup-a.zip` and `files-backup-b.zip` intact.



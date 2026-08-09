# Windows Database Backup Deployment Guide

## Overview

This document describes how to configure hourly MySQL database backups on a Windows deployment. The application provides an Artisan command (`db:backup`) that creates compressed, rotating backups in a configured local directory for synchronization to cloud storage.

## Prerequisites

- Windows Server with MySQL 8.0 installed
- MySQL `mysqldump.exe` available at a known path (typically `C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe`)
- A dedicated backup directory on the server (e.g., `D:\Backup_WK`)
- Windows Task Scheduler configured to run the command hourly
- Sufficient local disk space for two rotating backup archives
- Operator account with Modify permission to the backup directory

## Production `.env` Configuration

Add or update the following environment variables in your production `.env` file:

```env
# Database Backup Configuration
DB_BACKUP_MYSQLDUMP_PATH="C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe"
DB_BACKUP_DESTINATION_DIR="D:\Backup_WK"
DB_BACKUP_SLOT_A="database-backup-a.zip"
DB_BACKUP_SLOT_B="database-backup-b.zip"
DB_BACKUP_ARCHIVE_PASSWORD=
```

### Configuration Details

- **`DB_BACKUP_MYSQLDUMP_PATH`**: Full path to the MySQL dump executable. Verify the path by opening a Command Prompt and running:
  ```
  dir "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe"
  ```

- **`DB_BACKUP_DESTINATION_DIR`**: Windows directory where backup archives are stored. This directory will be synchronized to cloud storage (e.g., Google Drive). The operator must have Modify permission.

- **`DB_BACKUP_SLOT_A` and `DB_BACKUP_SLOT_B`**: Two fixed filenames for the rotating backup set. The command alternates between these, preserving one fallback copy at all times.

- **`DB_BACKUP_ARCHIVE_PASSWORD`**: Optional password to encrypt the backup ZIP archives (WinZip/7-Zip compatible). Leave empty to store unencrypted. If set, treat as a recovery-critical secret and store outside the repository.

### Granting Permissions

Ensure the account running the scheduled task has Modify permission to the backup directory:

1. Right-click the backup directory (e.g., `D:\Backup_WK`) → Properties
2. Select the Security tab → Edit
3. Select the task-runner account → Click Edit
4. Check "Modify" → Apply → OK

## Windows Task Scheduler Configuration

The deployment operator must manually create a Windows Task Scheduler task. This ensures scheduled backups are not automatic or tied to Laravel's scheduler.

### Step-by-Step Task Creation

1. Open Windows Task Scheduler:
   - Press `Win+R`, type `taskschd.msc`, and press Enter

2. Create a new task:
   - Click "Create Task..." in the right panel

3. Configure the General tab:
   - **Name**: "Hourly Database Backup"
   - **Description**: "Creates hourly backup of production database"
   - Check "Run whether user is logged in or not"
   - Check "Run with highest privileges" (if task-runner account permits)

4. Configure the Triggers tab:
   - Click "New..." and select "On a schedule"
   - Choose "Daily"
   - Set "Repeat every" to "1 hour"
   - Set "Start time" to any time
   - Set "Repeat every" with a start time of **:05** (5 minutes past each hour)
   - Check "Synchronize across time zones"

5. Configure the Actions tab:
   - **Action**: "Start a program"
   - **Program/script**: `php.exe` (or full path if needed)
   - **Add arguments**: `artisan db:backup`
   - **Start in**: The Laravel project root (e.g., `D:\WebApps\tiga-saudara-erp`)

6. Configure the Conditions tab:
   - Uncheck "Start the task only if the computer is on AC power" (if backing up from a UPS)
   - Uncheck "Stop the task if it runs longer than" (or set a long timeout like 30 minutes)

7. Configure the Settings tab:
   - Check "Allow task to be run on demand"
   - Check "Run task as soon as possible after a missed schedule" (critical for overnight server downtime)
   - Check "If the task fails, restart every" and set to a reasonable interval (e.g., 10 minutes, 3 retries)
   - In the "If the task is already running, then the following rule applies" dropdown, select **"Do not start a new instance."** — This is critical to prevent concurrent backup processes from interfering with each other.
   - Uncheck "Stop the task if it runs longer than" or set to a timeout matching your expected backup duration

8. Save the task with the task-runner account credentials

### Verifying the Task Configuration

After creating the task, verify it is correctly configured:

- Right-click the task → Run
- Check that a new backup file appears in `D:\Backup_WK` within a few seconds
- On the second run, verify that the alternate slot file is created
- On the third run, verify that the older slot file is replaced while the newer one remains

Run `Get-Date` in PowerShell to confirm your local time zone, then verify the next scheduled run is correct.

## Manual Backup Verification

To verify a backup manually:

1. Open `D:\Backup_WK` in File Explorer
2. Confirm both `database-backup-a.zip` and `database-backup-b.zip` exist and have recent timestamps
3. Note the file size (typically 50 MB–500 MB depending on database size)

## Testing Backup Rotation

To verify the two-slot rotation is working:

1. Open Task Scheduler
2. Right-click the "Hourly Database Backup" task → Run
3. Wait 1–2 seconds and refresh the backup directory
4. Note the timestamp of the first slot
5. Run the task again
6. Verify the second slot was created with a newer timestamp
7. Run the task a third time
8. Verify the first slot timestamp updated while the second slot remained unchanged

This confirms the alternating slot replacement is working correctly.

## Cloud Synchronization

Configure your cloud sync client (e.g., Google Drive, OneDrive, Dropbox) to monitor `D:\Backup_WK`:

- The sync client will automatically upload new backups as they are created
- Verify that both slot files have synced to the cloud before any server downtime
- Consider enabling version history on cloud storage to preserve historical backups

## Safe Restore Test

Before relying on backups in production, perform a safe restore test:

1. Download one of the backup ZIP files from cloud storage
2. Extract the `dump.sql` file to a temporary location
3. Create a temporary MySQL database on a test or development server:
   ```sql
   CREATE DATABASE restore_test;
   ```
4. Restore the dump:
   ```
   mysql -h localhost -u root -p restore_test < dump.sql
   ```
5. Verify the restored database contains expected tables, data, and objects
6. Delete the temporary test database

This test confirms:
- Backups are valid and restorable
- Stored passwords and encryption keys (if used) are accessible
- The dump includes all required schema objects (tables, triggers, routines, events)

## Troubleshooting

### No backup files appear

1. Check that `D:\Backup_WK` exists and the task-runner account has Modify permission
2. Open Event Viewer and search for Task Scheduler errors
3. Run the task manually and check the output (right-click task → Properties → History)
4. Verify `mysqldump.exe` exists and the path in `.env` is correct

### Backups stop after a few runs

1. Check disk space on `D:\` drive
2. Verify the task-runner account still has Modify permission to the directory
3. Check Event Viewer for permission or I/O errors

### Archive password is not being applied

1. Confirm `DB_BACKUP_ARCHIVE_PASSWORD` is set to a non-empty value in `.env`
2. Verify the `.env` file is readable by the task-runner account
3. Test a manual backup with the password configured and inspect the ZIP

### Backup file size is suspiciously small

1. Verify the dump is not being truncated
2. Restore a backup and confirm all tables are present
3. Check MySQL error logs for warnings during dump

## Operational Monitoring

Consider monitoring these aspects:

- **Backup frequency**: Verify a new backup appears every hour
- **Backup size**: Track backup file sizes to detect unexpected database growth
- **Cloud sync health**: Confirm backups sync to cloud within 5–15 minutes of creation
- **Disk space**: Monitor `D:\` to prevent running out of storage
- **Backup restore capability**: Test a restore at least quarterly or after major schema changes

## Post-Deployment Verification Checklist

Complete this checklist before declaring the backup system operational:

### Manual Backup Verification
- [ ] Backup directory `D:\Backup_WK` exists and is readable/writable by task account
- [ ] Run backup manually: `php artisan db:backup` from the project root
- [ ] Verify `database-backup-a.zip` appears in the directory with a size > 0 bytes
- [ ] Open the ZIP file and confirm `dump.sql` is present and contains SQL schema

### Two-Run Rotation Verification
- [ ] Run backup a second time
- [ ] Verify `database-backup-b.zip` is created
- [ ] Both files exist with recent timestamps
- [ ] Run backup a third time
- [ ] Verify `database-backup-a.zip` timestamp updated while `database-backup-b.zip` remains unchanged

### Cloud Synchronization Verification
- [ ] Configure your cloud sync client (Google Drive, OneDrive, Dropbox) to monitor `D:\Backup_WK`
- [ ] Wait 5–15 minutes for backups to sync
- [ ] Verify both backup files appear in cloud storage
- [ ] Note the cloud storage path for recovery reference

### Safe Restore Test
- [ ] Download a backup ZIP from cloud storage to a temporary location
- [ ] Extract `dump.sql`
- [ ] Create a temporary MySQL database: `CREATE DATABASE restore_test;`
- [ ] Restore the dump: `mysql -h localhost -u root -p restore_test < dump.sql`
- [ ] Query the restored database to confirm tables and data integrity
- [ ] Drop the temporary database: `DROP DATABASE restore_test;`
- [ ] Document the restore procedure and any deviations

### Scheduled Task Verification
- [ ] Open Windows Task Scheduler
- [ ] Locate "Hourly Database Backup" task
- [ ] Verify the task is enabled
- [ ] Right-click and select "Run" to manually trigger
- [ ] Observe the next scheduled run time (should be at :05 of the hour)
- [ ] Let the task run for at least 2 hours and confirm backups appear hourly

## Rollback

To disable backups:

1. Open Task Scheduler
2. Right-click the "Hourly Database Backup" task → Disable
3. Backups are no longer created, but existing archives remain available
4. Existing backup archives can still be restored manually if needed

To remove the backup command from the application in a future release, remove these files:

- `config/database-backup.php`
- `app/Services/DatabaseBackupService.php`
- `app/Console/Commands/BackupDatabaseCommand.php`
- Remove configuration entries from `.env` and `.env.example`

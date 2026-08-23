<?php

$defaultMysqldump = PHP_OS_FAMILY === 'Windows'
    ? 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe'
    : 'mysqldump';

$defaultWorkingDir = PHP_OS_FAMILY === 'Windows'
    ? 'D:\\Backup_Work'
    : storage_path('backup_work');

$defaultDestDir = PHP_OS_FAMILY === 'Windows'
    ? 'D:\\Backup_WK'
    : storage_path('backups');

return [
    'mysqldump_path' => env('DB_BACKUP_MYSQLDUMP_PATH', $defaultMysqldump),
    'working_dir' => env('DB_BACKUP_WORKING_DIR', $defaultWorkingDir),
    'destination_dir' => env('DB_BACKUP_DESTINATION_DIR', $defaultDestDir),
    'slot_a' => env('DB_BACKUP_SLOT_A', 'database-backup-a.zip'),
    'slot_b' => env('DB_BACKUP_SLOT_B', 'database-backup-b.zip'),
    'archive_password' => env('DB_BACKUP_ARCHIVE_PASSWORD', ''),
];

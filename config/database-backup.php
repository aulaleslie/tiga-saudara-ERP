<?php

$defaultMysqldump = PHP_OS_FAMILY === 'Windows'
    ? 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe'
    : 'mysqldump';

$defaultDestDir = PHP_OS_FAMILY === 'Windows'
    ? 'D:\\Backup_WK'
    : storage_path('backups');

return [
    'mysqldump_path' => env('DB_BACKUP_MYSQLDUMP_PATH', $defaultMysqldump),
    'destination_dir' => env('DB_BACKUP_DESTINATION_DIR', $defaultDestDir),
    'slot_a' => env('DB_BACKUP_SLOT_A', 'database-backup-a.zip'),
    'slot_b' => env('DB_BACKUP_SLOT_B', 'database-backup-b.zip'),
    'archive_password' => env('DB_BACKUP_ARCHIVE_PASSWORD', ''),
];

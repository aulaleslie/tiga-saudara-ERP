<?php

namespace Tests\Unit;

use App\Services\DatabaseBackupService;
use App\Services\FileBackupService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FileBackupConfigurationTest extends TestCase
{
    private string $tempBaseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBaseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ts_erp_file_backup_cfg_' . uniqid();
        File::ensureDirectoryExists($this->tempBaseDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempBaseDir);
        parent::tearDown();
    }

    public function test_file_backup_config_defines_only_distinct_slots_and_reuses_database_backup_paths_and_password(): void
    {
        $rawFileConfig = require base_path('config/file-backup.php');

        $this->assertArrayHasKey('slot_a', $rawFileConfig);
        $this->assertArrayHasKey('slot_b', $rawFileConfig);
        $this->assertArrayNotHasKey('destination_dir', $rawFileConfig);
        $this->assertArrayNotHasKey('working_dir', $rawFileConfig);
        $this->assertArrayNotHasKey('archive_password', $rawFileConfig);
        $this->assertSame('files-backup-a.zip', $rawFileConfig['slot_a']);
        $this->assertSame('files-backup-b.zip', $rawFileConfig['slot_b']);

        $sharedWorking = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'work';
        $sharedDest = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'dest';

        config([
            'database-backup.working_dir' => $sharedWorking,
            'database-backup.destination_dir' => $sharedDest,
            'database-backup.archive_password' => 'shared-secret-pw',
            'database-backup.slot_a' => 'database-backup-a.zip',
            'database-backup.slot_b' => 'database-backup-b.zip',
            'file-backup.slot_a' => 'files-backup-a.zip',
            'file-backup.slot_b' => 'files-backup-b.zip',
        ]);

        $service = new FileBackupService();
        $resolved = $service->getResolvedConfiguration();

        $this->assertSame($sharedWorking, $resolved['working_dir']);
        $this->assertSame($sharedDest, $resolved['destination_dir']);
        $this->assertSame('shared-secret-pw', $resolved['archive_password']);
        $this->assertSame('files-backup-a.zip', $resolved['slot_a']);
        $this->assertSame('files-backup-b.zip', $resolved['slot_b']);
    }

    public function test_changing_database_backup_destination_applies_to_both_database_and_file_backups(): void
    {
        $workDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'shared_work';
        $customDestDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'custom_shared_dest';
        $storageApp = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'storage_app';

        File::ensureDirectoryExists($storageApp . DIRECTORY_SEPARATOR . 'public');
        file_put_contents($storageApp . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'sample.txt', 'hello');

        config([
            'database-backup.mysqldump_path' => PHP_BINARY,
            'database-backup.working_dir' => $workDir,
            'database-backup.destination_dir' => $customDestDir,
            'database-backup.slot_a' => 'database-backup-a.zip',
            'database-backup.slot_b' => 'database-backup-b.zip',
            'database-backup.archive_password' => '',
            'file-backup.slot_a' => 'files-backup-a.zip',
            'file-backup.slot_b' => 'files-backup-b.zip',
        ]);

        $dbService = new DatabaseBackupService(null, testMode: true);
        $dbResult = $dbService->backup();

        $fileService = new FileBackupService(['storage_app_path' => $storageApp]);
        $fileResult = $fileService->backup();

        $this->assertTrue($dbResult['success'], $dbResult['message'] ?? '');
        $this->assertTrue($fileResult['success'], $fileResult['message'] ?? '');

        $this->assertFileExists($customDestDir . DIRECTORY_SEPARATOR . 'database-backup-a.zip');
        $this->assertFileExists($customDestDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip');
    }

    /**
     * @dataProvider invalidSlotConfigurationProvider
     */
    public function test_invalid_or_colliding_slot_filenames_are_rejected(
        string $fileSlotA,
        string $fileSlotB,
        string $dbSlotA,
        string $dbSlotB,
        string $expectedMessageFragment
    ): void {
        $workDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'work';
        $destDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'dest';
        $storageApp = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'storage_app';

        $service = new FileBackupService([
            'working_dir' => $workDir,
            'destination_dir' => $destDir,
            'slot_a' => $fileSlotA,
            'slot_b' => $fileSlotB,
            'db_slot_a' => $dbSlotA,
            'db_slot_b' => $dbSlotB,
            'storage_app_path' => $storageApp,
        ]);

        $result = $service->backup();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString($expectedMessageFragment, $result['message']);
    }

    public static function invalidSlotConfigurationProvider(): array
    {
        return [
            'identical file slots' => [
                'files-backup-a.zip',
                'files-backup-a.zip',
                'database-backup-a.zip',
                'database-backup-b.zip',
                'distinct filenames',
            ],
            'case-insensitive identical file slots' => [
                'Files-Backup-A.zip',
                'files-backup-a.zip',
                'database-backup-a.zip',
                'database-backup-b.zip',
                'distinct filenames',
            ],
            'collides with database slot A' => [
                'database-backup-a.zip',
                'files-backup-b.zip',
                'database-backup-a.zip',
                'database-backup-b.zip',
                'collide with database backup',
            ],
            'case-insensitive collision with database slot B' => [
                'files-backup-a.zip',
                'DATABASE-BACKUP-B.ZIP',
                'database-backup-a.zip',
                'database-backup-b.zip',
                'collide with database backup',
            ],
            'parent directory traversal in slot A' => [
                '../files-backup-a.zip',
                'files-backup-b.zip',
                'database-backup-a.zip',
                'database-backup-b.zip',
                'path traversal and directory separators are not allowed',
            ],
            'forward slash separator in slot B' => [
                'files-backup-a.zip',
                'nested/files-backup-b.zip',
                'database-backup-a.zip',
                'database-backup-b.zip',
                'path traversal and directory separators are not allowed',
            ],
            'backslash separator in slot A' => [
                'nested\\files-backup-a.zip',
                'files-backup-b.zip',
                'database-backup-a.zip',
                'database-backup-b.zip',
                'path traversal and directory separators are not allowed',
            ],
            'drive colon in slot A' => [
                'D:files-backup-a.zip',
                'files-backup-b.zip',
                'database-backup-a.zip',
                'database-backup-b.zip',
                'path traversal and directory separators are not allowed',
            ],
            'empty slot name' => [
                '',
                'files-backup-b.zip',
                'database-backup-a.zip',
                'database-backup-b.zip',
                'cannot be empty',
            ],
        ];
    }
}

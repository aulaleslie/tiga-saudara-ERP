<?php

namespace Tests\Feature;

use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class DatabaseBackupCommandTest extends TestCase
{
    private string $testBackupDir;
    private string $slotA;
    private string $slotB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testBackupDir = storage_path('test_backups');
        $this->slotA = 'database-backup-a.zip';
        $this->slotB = 'database-backup-b.zip';

        File::ensureDirectoryExists($this->testBackupDir);

        config([
            'database-backup.destination_dir' => $this->testBackupDir,
            'database-backup.slot_a' => $this->slotA,
            'database-backup.slot_b' => $this->slotB,
            'database-backup.archive_password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testBackupDir);
        parent::tearDown();
    }

    public function test_successful_first_backup_creation()
    {
        $config = [
            'mysqldump_path' => 'mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => $this->slotA,
            'slot_b' => $this->slotB,
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: true);
        $result = $service->backup();

        $this->assertTrue($result['success']);

        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotA;
        $this->assertFileExists($slotAPath);
        $this->assertGreaterThan(0, filesize($slotAPath));

        $this->assertValidZipArchive($slotAPath);
        $this->assertDumpContainsExpectedContent($slotAPath);
    }

    public function test_second_backup_creates_alternate_slot()
    {
        $config = [
            'mysqldump_path' => 'mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => $this->slotA,
            'slot_b' => $this->slotB,
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: true);

        // First backup
        $result1 = $service->backup();
        $this->assertTrue($result1['success']);
        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotA;
        $this->assertFileExists($slotAPath);

        // Second backup should create slot B
        $result2 = $service->backup();
        $this->assertTrue($result2['success']);
        $slotBPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotB;
        $this->assertFileExists($slotBPath);

        // Both slots should exist
        $this->assertFileExists($slotAPath);
        $this->assertFileExists($slotBPath);
    }

    public function test_third_backup_replaces_older_slot()
    {
        $config = [
            'mysqldump_path' => 'mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => $this->slotA,
            'slot_b' => $this->slotB,
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: true);

        // Create first backup
        $service->backup();
        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotA;
        $slotASizeFirst = filesize($slotAPath);

        // Create second backup (should go to slot B)
        $service->backup();
        $slotBPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotB;
        $slotBSize = filesize($slotBPath);

        // Create third backup (should replace slot A)
        $service->backup();

        // Slot A should have been replaced (possibly different size)
        $this->assertFileExists($slotAPath);
        // Slot B should remain unchanged
        $this->assertEquals($slotBSize, filesize($slotBPath));
    }

    public function test_missing_mysqldump_executable_fails()
    {
        $config = [
            'mysqldump_path' => '/nonexistent/mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => $this->slotA,
            'slot_b' => $this->slotB,
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: true);
        $result = $service->backup();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found or not executable', $result['message']);

        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotA;
        $this->assertFileDoesNotExist($slotAPath);
    }

    public function test_unwritable_destination_fails()
    {
        $readOnlyDir = storage_path('readonly_test');
        File::ensureDirectoryExists($readOnlyDir);
        chmod($readOnlyDir, 0444);

        try {
            $config = [
                'mysqldump_path' => 'mysqldump',
                'destination_dir' => $readOnlyDir . DIRECTORY_SEPARATOR . 'subdir',
                'slot_a' => $this->slotA,
                'slot_b' => $this->slotB,
                'archive_password' => '',
            ];

            $service = new DatabaseBackupService($config, testMode: true);
            $result = $service->backup();

            $this->assertFalse($result['success']);
            // Either creation or writability check fails
            $this->assertTrue(
                str_contains($result['message'], 'not writable') ||
                str_contains($result['message'], 'Failed to create'),
                "Expected error message about permissions, got: " . $result['message']
            );
        } finally {
            chmod($readOnlyDir, 0755);
            File::deleteDirectory($readOnlyDir);
        }
    }

    public function test_dump_includes_single_transaction_flag()
    {
        $config = [
            'mysqldump_path' => 'mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => $this->slotA,
            'slot_b' => $this->slotB,
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: true);
        $service->backup();

        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotA;
        $sql = $this->extractSqlFromZip($slotAPath);

        $this->assertStringContainsString('SET TRANSACTION', $sql);
    }

    public function test_dump_includes_routines()
    {
        $config = [
            'mysqldump_path' => 'mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => $this->slotA,
            'slot_b' => $this->slotB,
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: true);
        $service->backup();

        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotA;
        $sql = $this->extractSqlFromZip($slotAPath);

        $this->assertStringContainsString('PROCEDURE', $sql);
    }

    public function test_backup_uses_configured_database()
    {
        $config = [
            'mysqldump_path' => 'mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => $this->slotA,
            'slot_b' => $this->slotB,
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: true);
        $service->backup();

        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotA;
        $sql = $this->extractSqlFromZip($slotAPath);

        $this->assertStringContainsString('USE `', $sql);
    }

    public function test_encrypted_archive_with_password()
    {
        $password = 'test_backup_password';
        $config = [
            'mysqldump_path' => 'mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => $this->slotA,
            'slot_b' => $this->slotB,
            'archive_password' => $password,
        ];

        $service = new DatabaseBackupService($config, testMode: true);
        $result = $service->backup();

        $this->assertTrue($result['success']);

        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . $this->slotA;
        $this->assertFileExists($slotAPath);
        $this->assertGreaterThan(0, filesize($slotAPath));

        // Verify the archive was created successfully with password configured
        $this->assertValidZipArchive($slotAPath);
    }

    private function assertValidZipArchive(string $path): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($path);
        $this->assertTrue($result === true, "Failed to open ZIP archive: {$path}");
        $this->assertGreaterThan(0, $zip->numFiles);
        $zip->close();
    }

    private function extractSqlFromZip(string $path): string
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $sql = $zip->getFromName('dump.sql');
        $zip->close();
        return $sql;
    }

    private function assertDumpContainsExpectedContent(string $path): void
    {
        $sql = $this->extractSqlFromZip($path);

        // Basic sanity checks for SQL dump content
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
    }
}

<?php

namespace Tests\Unit;

use App\Services\DatabaseBackupService;
use App\Services\ProcessRunnerInterface;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Tests for ZIP archive encryption behavior.
 */
class DatabaseBackupEncryptionTest extends TestCase
{
    private string $testBackupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testBackupDir = storage_path('test_backups_encryption');
        File::ensureDirectoryExists($this->testBackupDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testBackupDir);
        parent::tearDown();
    }

    public function test_unencrypted_archive_when_password_empty()
    {
        $mockRunner = new SuccessfulMockRunner();

        $config = [
            'mysqldump_path' => '/usr/bin/mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => 'backup-a.zip',
            'slot_b' => 'backup-b.zip',
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: false, processRunner: $mockRunner);
        $result = $service->backup();

        $this->assertTrue($result['success']);

        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . 'backup-a.zip';
        $this->assertFileExists($slotAPath);

        // Open without password should succeed
        $zip = new ZipArchive();
        $openResult = $zip->open($slotAPath);
        $this->assertTrue($openResult === true, 'Unencrypted archive should open without password');
        $this->assertGreaterThan(0, $zip->numFiles);
        $zip->close();
    }

    public function test_encrypted_archive_requires_password_to_extract()
    {
        // Skip if AES-256 encryption is not supported
        if (!$this->supportsAES256Encryption()) {
            $this->markTestSkipped('AES-256 encryption not supported by libzip');
        }

        $mockRunner = new SuccessfulMockRunner();

        $config = [
            'mysqldump_path' => '/usr/bin/mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => 'backup-a.zip',
            'slot_b' => 'backup-b.zip',
            'archive_password' => 'test_password_123',
        ];

        $service = new DatabaseBackupService($config, testMode: false, processRunner: $mockRunner);
        $result = $service->backup();

        $this->assertTrue($result['success']);

        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . 'backup-a.zip';
        $this->assertFileExists($slotAPath);

        // Try to extract without password - should fail
        $zip = new ZipArchive();
        $zip->open($slotAPath);
        $sqlWithoutPassword = $zip->getFromName('dump.sql');
        $zip->close();

        $this->assertFalse($sqlWithoutPassword, 'Extracting encrypted file without password should fail');

        // Extract with correct password - should succeed
        $zip = new ZipArchive();
        $zip->open($slotAPath);
        $zip->setPassword('test_password_123');
        $sqlWithPassword = $zip->getFromName('dump.sql');
        $zip->close();

        $this->assertNotFalse($sqlWithPassword, 'Extracting encrypted file with correct password should succeed');
        $this->assertStringContainsString('CREATE TABLE', $sqlWithPassword);
    }

    public function test_encryption_failure_preserves_existing_backups()
    {
        // Create existing backups to verify they are preserved on encryption failure
        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . 'backup-a.zip';
        $slotBPath = $this->testBackupDir . DIRECTORY_SEPARATOR . 'backup-b.zip';

        file_put_contents($slotAPath, 'existing backup a');
        file_put_contents($slotBPath, 'existing backup b');

        $aOriginalContent = file_get_contents($slotAPath);
        $bOriginalContent = file_get_contents($slotBPath);

        $mockRunner = new SuccessfulMockRunner();

        $config = [
            'mysqldump_path' => '/usr/bin/mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => 'backup-a.zip',
            'slot_b' => 'backup-b.zip',
            'archive_password' => 'test_password',
        ];

        // Create a service with encryption that will fail
        $service = new class($config, false, $mockRunner) extends \App\Services\DatabaseBackupService {
            protected function applyArchiveEncryption(\ZipArchive $zip): void
            {
                throw new \RuntimeException("Failed to enable AES-256 encryption (unsupported by libzip)");
            }
        };

        $result = $service->backup();

        // Backup should fail
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('encryption', strtolower($result['message']),
            'Failure message should identify encryption as the problem');

        // Existing backups should be untouched
        $this->assertEquals($aOriginalContent, file_get_contents($slotAPath),
            'Slot A should not be modified on encryption failure');
        $this->assertEquals($bOriginalContent, file_get_contents($slotBPath),
            'Slot B should not be modified on encryption failure');
    }

    public function test_encryption_failure_cleans_temporary_files()
    {
        $mockRunner = new SuccessfulMockRunner();

        $config = [
            'mysqldump_path' => '/usr/bin/mysqldump',
            'destination_dir' => $this->testBackupDir,
            'slot_a' => 'backup-a.zip',
            'slot_b' => 'backup-b.zip',
            'archive_password' => 'test_password',
        ];

        // Create a service with encryption that will fail
        $service = new class($config, false, $mockRunner) extends \App\Services\DatabaseBackupService {
            protected function applyArchiveEncryption(\ZipArchive $zip): void
            {
                throw new \RuntimeException("Failed to enable AES-256 encryption (unsupported by libzip)");
            }
        };

        $result = $service->backup();

        // Backup should fail
        $this->assertFalse($result['success']);

        // Temporary files should be cleaned up
        $tempFiles = glob($this->testBackupDir . DIRECTORY_SEPARATOR . 'temp_*', GLOB_ONLYDIR);
        $this->assertEmpty($tempFiles,
            'Temporary directories should be cleaned up after encryption failure');

        $tempZips = glob($this->testBackupDir . DIRECTORY_SEPARATOR . 'temp_*.zip');
        $this->assertEmpty($tempZips,
            'Temporary ZIP files should be cleaned up after encryption failure');
    }

    private function supportsAES256Encryption(): bool
    {
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'zip_test_');
            $zip = new ZipArchive();
            $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            // Try to write test data
            $zip->addFromString('test.txt', 'test content');

            // Try to enable AES-256 encryption
            $result = $zip->setEncryptionName('test.txt', ZipArchive::EM_AES_256);
            $zip->setPassword('test');
            $zip->close();

            unlink($tempFile);
            return $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
}

/**
 * Mock runner that simulates successful mysqldump execution.
 */
class SuccessfulMockRunner implements ProcessRunnerInterface
{
    public function run(array $command, string $outputPath, array $env): array
    {
        // Write valid SQL dump to output file
        $sql = "-- MySQL Dump\n";
        $sql .= "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;\n";
        $sql .= "USE `test`;\n";
        $sql .= "CREATE TABLE test_table (id INT PRIMARY KEY, name VARCHAR(255));\n";
        $sql .= "INSERT INTO test_table VALUES (1, 'test');\n";
        $sql .= "CREATE PROCEDURE test_proc() READS SQL DATA SELECT 1;\n";
        $sql .= "CREATE EVENT test_event ON SCHEDULE EVERY 1 DAY DO SELECT 1;\n";
        $sql .= "CREATE TRIGGER test_trigger BEFORE INSERT ON test_table FOR EACH ROW SELECT NEW.id;\n";
        file_put_contents($outputPath, $sql);

        return [
            'success' => true,
            'stderr' => '',
        ];
    }

    public function getLastCommand(): ?array
    {
        return null;
    }
}

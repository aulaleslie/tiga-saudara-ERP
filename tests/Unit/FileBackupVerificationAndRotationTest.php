<?php

namespace Tests\Unit;

use App\Services\FileBackupService;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class FileBackupVerificationAndRotationTest extends TestCase
{
    private string $tempBaseDir;
    private string $workDir;
    private string $destDir;
    private string $storageApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBaseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ts_erp_file_backup_rot_' . uniqid();
        $this->workDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'work';
        $this->destDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'dest';
        $this->storageApp = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app';

        File::ensureDirectoryExists($this->workDir);
        File::ensureDirectoryExists($this->destDir);
        File::ensureDirectoryExists($this->storageApp . '/public');
        File::ensureDirectoryExists($this->storageApp . '/imports/sales');

        file_put_contents($this->storageApp . '/public/logo.png', 'initial-logo-bytes');
        file_put_contents($this->storageApp . '/imports/sales/batch_1.csv', "id,total\n1,100\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempBaseDir);
        parent::tearDown();
    }

    private function baseConfig(): array
    {
        return [
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'slot_a' => 'files-backup-a.zip',
            'slot_b' => 'files-backup-b.zip',
            'db_slot_a' => 'database-backup-a.zip',
            'db_slot_b' => 'database-backup-b.zip',
            'archive_password' => '',
            'storage_app_path' => $this->storageApp,
        ];
    }

    public function test_corrupted_archive_payload_checksum_or_count_mismatch_fails_before_promotion(): void
    {
        $slotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        file_put_contents($slotAPath, 'original-slot-a-bytes');

        // 1. Tamper with payload content inside ZIP so SHA-256 differs from manifest (same byte length)
        $serviceChecksumTamper = new class($this->baseConfig()) extends FileBackupService {
            protected function onAfterArchiveCreated(string $archiveFile, array $inventory): void
            {
                $zip = new ZipArchive();
                $zip->open($archiveFile);
                $zip->addFromString('public/logo.png', 'tamperd-logo-bytes'); // same length (18 bytes), different hash
                $zip->close();
            }
        };

        $resChecksum = $serviceChecksumTamper->backup();
        $this->assertFalse($resChecksum['success']);
        $this->assertStringContainsString('SHA-256 checksum mismatch', $resChecksum['message']);
        $this->assertSame('original-slot-a-bytes', file_get_contents($slotAPath));
        $this->assertFileDoesNotExist($this->destDir . DIRECTORY_SEPARATOR . 'files-backup-b.zip');

        // 2. Tamper by adding an unexpected extra entry to the ZIP archive
        $serviceCountTamper = new class($this->baseConfig()) extends FileBackupService {
            protected function onAfterArchiveCreated(string $archiveFile, array $inventory): void
            {
                $zip = new ZipArchive();
                $zip->open($archiveFile);
                $zip->addFromString('public/unexpected.txt', 'extra');
                $zip->close();
            }
        };

        $resCount = $serviceCountTamper->backup();
        $this->assertFalse($resCount['success']);
        $this->assertStringContainsString('ZIP entry count', $resCount['message']);
        $this->assertSame('original-slot-a-bytes', file_get_contents($slotAPath));

        // 3. Corrupt manifest.json with invalid JSON
        $serviceManifestTamper = new class($this->baseConfig()) extends FileBackupService {
            protected function onAfterArchiveCreated(string $archiveFile, array $inventory): void
            {
                $zip = new ZipArchive();
                $zip->open($archiveFile);
                $zip->addFromString(self::MANIFEST_FILENAME, '{not-valid-json');
                $zip->close();
            }
        };

        $resManifest = $serviceManifestTamper->backup();
        $this->assertFalse($resManifest['success']);
        $this->assertStringContainsString('manifest.json', $resManifest['message']);
        $this->assertSame('original-slot-a-bytes', file_get_contents($slotAPath));
    }

    public function test_disappearing_or_modified_source_file_during_capture_fails_before_promotion(): void
    {
        $slotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        file_put_contents($slotAPath, 'safe-slot-a');

        $logoPath = $this->storageApp . '/public/logo.png';

        // 1. Source file modified before archive finishes
        $serviceModified = new class($this->baseConfig(), $logoPath) extends FileBackupService {
            private string $targetPath;

            public function __construct(array $config, string $targetPath)
            {
                parent::__construct($config);
                $this->targetPath = $targetPath;
            }

            protected function onBeforeArchiveFinalize(ZipArchive $zip, array $inventory): void
            {
                file_put_contents($this->targetPath, 'modified-during-backup-with-different-length');
                touch($this->targetPath, time() + 60);
            }
        };

        $resModified = $serviceModified->backup();
        $this->assertFalse($resModified['success']);
        $this->assertStringContainsString('changed during backup capture', $resModified['message']);
        $this->assertSame('safe-slot-a', file_get_contents($slotAPath));

        // 2. Source file disappears during capture
        file_put_contents($logoPath, 'restored-logo-bytes');
        $serviceDisappeared = new class($this->baseConfig(), $logoPath) extends FileBackupService {
            private string $targetPath;

            public function __construct(array $config, string $targetPath)
            {
                parent::__construct($config);
                $this->targetPath = $targetPath;
            }

            protected function onAfterArchiveCreated(string $archiveFile, array $inventory): void
            {
                @unlink($this->targetPath);
            }
        };

        $resDisappeared = $serviceDisappeared->backup();
        $this->assertFalse($resDisappeared['success']);
        $this->assertStringContainsString('disappeared during backup capture', $resDisappeared['message']);
        $this->assertSame('safe-slot-a', file_get_contents($slotAPath));
        $this->assertEmpty(glob($this->workDir . DIRECTORY_SEPARATOR . 'temp_files_*'));
    }

    public function test_concurrent_invocation_is_rejected_without_touching_slots_or_active_workspace(): void
    {
        $slotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        file_put_contents($slotAPath, 'existing-slot-a');

        // Simulate an active first backup process holding the exclusive lock and workspace
        $activeWorkspace = $this->workDir . DIRECTORY_SEPARATOR . 'temp_files_active_process_1';
        File::ensureDirectoryExists($activeWorkspace);
        file_put_contents($activeWorkspace . DIRECTORY_SEPARATOR . 'in_progress.zip', 'building');

        $lockPath = $this->workDir . DIRECTORY_SEPARATOR . FileBackupService::LOCK_FILENAME;
        $lockHandle = fopen($lockPath, 'c+');
        $this->assertNotFalse($lockHandle);
        $this->assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        try {
            $secondService = new FileBackupService($this->baseConfig());
            $secondResult = $secondService->backup();

            $this->assertFalse($secondResult['success']);
            $this->assertStringContainsString('already running', $secondResult['message']);

            // Active workspace of the first process and existing slots must remain untouched
            $this->assertFileExists($activeWorkspace . DIRECTORY_SEPARATOR . 'in_progress.zip');
            $this->assertSame('existing-slot-a', file_get_contents($slotAPath));
            $this->assertFileDoesNotExist($this->destDir . DIRECTORY_SEPARATOR . 'files-backup-b.zip');
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function test_three_run_rotation_with_deterministic_timestamps_and_database_slot_preservation(): void
    {
        $dbSlotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'database-backup-a.zip';
        $dbSlotBPath = $this->destDir . DIRECTORY_SEPARATOR . 'database-backup-b.zip';
        file_put_contents($dbSlotAPath, 'db-backup-slot-a-bytes');
        file_put_contents($dbSlotBPath, 'db-backup-slot-b-bytes');
        touch($dbSlotAPath, 1700000010);
        touch($dbSlotBPath, 1700000020);

        $fileSlotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        $fileSlotBPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-b.zip';

        $service = new FileBackupService($this->baseConfig());

        // Run 1: populates missing slot A
        $res1 = $service->backup();
        $this->assertTrue($res1['success']);
        $this->assertSame($fileSlotAPath, $res1['slot']);
        $this->assertFileExists($fileSlotAPath);
        $this->assertFileDoesNotExist($fileSlotBPath);
        touch($fileSlotAPath, 1700000100);
        $slotARun1Hash = hash_file('sha256', $fileSlotAPath);

        // Run 2: populates missing slot B
        file_put_contents($this->storageApp . '/public/run2.txt', 'run-2-payload');
        $res2 = $service->backup();
        $this->assertTrue($res2['success']);
        $this->assertSame($fileSlotBPath, $res2['slot']);
        $this->assertFileExists($fileSlotAPath);
        $this->assertFileExists($fileSlotBPath);
        touch($fileSlotBPath, 1700000200);
        $slotBRun2Hash = hash_file('sha256', $fileSlotBPath);

        // Slot A must still match Run 1
        $this->assertSame($slotARun1Hash, hash_file('sha256', $fileSlotAPath));

        // Run 3: Slot A (1700000100) is older than Slot B (1700000200) -> replaces Slot A only
        file_put_contents($this->storageApp . '/public/run3.txt', 'run-3-payload');
        $res3 = $service->backup();
        $this->assertTrue($res3['success']);
        $this->assertSame($fileSlotAPath, $res3['slot']);
        touch($fileSlotAPath, 1700000300);

        $this->assertNotSame($slotARun1Hash, hash_file('sha256', $fileSlotAPath));
        $this->assertSame($slotBRun2Hash, hash_file('sha256', $fileSlotBPath));
        $this->assertSame(1700000200, filemtime($fileSlotBPath));

        // Run 4: Slot B (1700000200) is now older than Slot A (1700000300) -> replaces Slot B only
        $slotARun3Hash = hash_file('sha256', $fileSlotAPath);
        file_put_contents($this->storageApp . '/public/run4.txt', 'run-4-payload');
        $res4 = $service->backup();
        $this->assertTrue($res4['success']);
        $this->assertSame($fileSlotBPath, $res4['slot']);

        $this->assertSame($slotARun3Hash, hash_file('sha256', $fileSlotAPath));
        $this->assertNotSame($slotBRun2Hash, hash_file('sha256', $fileSlotBPath));

        // Database archives must remain untouched throughout all runs and lock file must stay out of destination
        $this->assertSame('db-backup-slot-a-bytes', file_get_contents($dbSlotAPath));
        $this->assertSame('db-backup-slot-b-bytes', file_get_contents($dbSlotBPath));
        $this->assertSame(1700000010, filemtime($dbSlotAPath));
        $this->assertSame(1700000020, filemtime($dbSlotBPath));
        $this->assertFileDoesNotExist($this->destDir . DIRECTORY_SEPARATOR . FileBackupService::LOCK_FILENAME);
        $this->assertEmpty(glob($this->workDir . DIRECTORY_SEPARATOR . 'temp_files_*'));
    }

    public function test_injected_compression_verification_and_promotion_failures_preserve_prior_slot_bytes(): void
    {
        $fileSlotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        $fileSlotBPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-b.zip';
        $dbSlotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'database-backup-a.zip';

        file_put_contents($fileSlotAPath, 'prior-file-slot-a-bytes');
        file_put_contents($fileSlotBPath, 'prior-file-slot-b-bytes');
        file_put_contents($dbSlotAPath, 'prior-db-slot-a-bytes');
        touch($fileSlotAPath, 1700000100);
        touch($fileSlotBPath, 1700000200);

        // 1. Injected compression failure
        $compressionFailService = new class($this->baseConfig()) extends FileBackupService {
            protected function createArchive(array $inventory, string $tempDir, string $startedAt): array
            {
                file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'partial.zip', 'partial-bytes');
                throw new RuntimeException('Simulated ZIP compression failure');
            }
        };

        $resComp = $compressionFailService->backup();
        $this->assertFalse($resComp['success']);
        $this->assertStringContainsString('Simulated ZIP compression failure', $resComp['message']);
        $this->assertSame('prior-file-slot-a-bytes', file_get_contents($fileSlotAPath));
        $this->assertSame('prior-file-slot-b-bytes', file_get_contents($fileSlotBPath));
        $this->assertSame('prior-db-slot-a-bytes', file_get_contents($dbSlotAPath));
        $this->assertEmpty(glob($this->workDir . DIRECTORY_SEPARATOR . 'temp_files_*'));

        // 2. Injected verification failure
        $verifyFailService = new class($this->baseConfig()) extends FileBackupService {
            protected function verifyArchive(string $archiveFile, array $inventory): array
            {
                throw new RuntimeException('Simulated archive verification failure');
            }
        };

        $resVer = $verifyFailService->backup();
        $this->assertFalse($resVer['success']);
        $this->assertStringContainsString('Simulated archive verification failure', $resVer['message']);
        $this->assertSame('prior-file-slot-a-bytes', file_get_contents($fileSlotAPath));
        $this->assertSame('prior-file-slot-b-bytes', file_get_contents($fileSlotBPath));
        $this->assertEmpty(glob($this->workDir . DIRECTORY_SEPARATOR . 'temp_files_*'));

        // 3. Injected promotion failure (e.g. Windows destination file locked by sync client or antivirus)
        $promoteFailService = new class($this->baseConfig()) extends FileBackupService {
            protected function promoteArchive(string $archiveFile, string $finalSlot): void
            {
                throw new RuntimeException("Failed to promote archive to final slot: {$finalSlot}");
            }
        };

        $resProm = $promoteFailService->backup();
        $this->assertFalse($resProm['success']);
        $this->assertStringContainsString('Failed to promote archive to final slot', $resProm['message']);
        $this->assertSame('prior-file-slot-a-bytes', file_get_contents($fileSlotAPath));
        $this->assertSame('prior-file-slot-b-bytes', file_get_contents($fileSlotBPath));
        $this->assertSame('prior-db-slot-a-bytes', file_get_contents($dbSlotAPath));
        $this->assertEmpty(glob($this->workDir . DIRECTORY_SEPARATOR . 'temp_files_*'));
    }
}


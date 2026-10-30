<?php

namespace Tests\Feature;

use App\Services\FileBackupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use ZipArchive;

class FileBackupCommandTest extends TestCase
{
    private string $tempBaseDir;
    private string $workDir;
    private string $destDir;
    private string $storageApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBaseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ts_erp_file_backup_cmd_' . uniqid();
        $this->workDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'work';
        $this->destDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'dest';
        $this->storageApp = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app';

        File::ensureDirectoryExists($this->workDir);
        File::ensureDirectoryExists($this->destDir);
        File::ensureDirectoryExists($this->storageApp);

        config([
            'database-backup.working_dir' => $this->workDir,
            'database-backup.destination_dir' => $this->destDir,
            'database-backup.slot_a' => 'database-backup-a.zip',
            'database-backup.slot_b' => 'database-backup-b.zip',
            'database-backup.archive_password' => '',
            'file-backup.slot_a' => 'files-backup-a.zip',
            'file-backup.slot_b' => 'files-backup-b.zip',
            'file-backup.storage_app_path' => $this->storageApp,
            'media-library.disk_name' => 'public',
            'filesystems.disks.public.driver' => 'local',
            'filesystems.disks.public.root' => $this->storageApp . DIRECTORY_SEPARATOR . 'public',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempBaseDir);
        parent::tearDown();
    }

    public function test_command_succeeds_and_reports_slot_size_and_file_count_and_logs_info(): void
    {
        Log::spy();

        File::ensureDirectoryExists($this->storageApp . '/public/10/conversions');
        File::ensureDirectoryExists($this->storageApp . '/imports/sales');

        file_put_contents($this->storageApp . '/public/10/photo.jpg', 'photo-bytes');
        file_put_contents($this->storageApp . '/public/10/conversions/photo-thumb.jpg', 'thumb-bytes');
        file_put_contents($this->storageApp . '/imports/sales/batch_10.csv', "id,amount\n10,500\n");

        $expectedSlot = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';

        $exitCode = \Illuminate\Support\Facades\Artisan::call('files:backup');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('File backup successful:', $output);
        $this->assertStringContainsString($expectedSlot, $output);
        $this->assertStringContainsString('bytes, 3 files)', $output);

        $this->assertFileExists($expectedSlot);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'File backup successful:')
                && str_contains($message, $expectedSlot)
                && str_contains($message, '3 files)'));
    }

    public function test_command_succeeds_on_empty_installation_without_database_or_scheduler(): void
    {
        Log::spy();

        $expectedSlot = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';

        $exitCode = \Illuminate\Support\Facades\Artisan::call('files:backup');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('File backup successful:', $output);
        $this->assertStringContainsString($expectedSlot, $output);
        $this->assertStringContainsString('bytes, 0 files)', $output);

        $this->assertFileExists($expectedSlot);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, '0 files)'));
    }

    public function test_command_fails_with_nonzero_exit_and_logs_error_on_lock_contention(): void
    {
        Log::spy();

        $lockPath = $this->workDir . DIRECTORY_SEPARATOR . FileBackupService::LOCK_FILENAME;
        $lockHandle = fopen($lockPath, 'c+');
        $this->assertNotFalse($lockHandle);
        $this->assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        try {
            $this->artisan('files:backup')
                ->expectsOutputToContain('Another file backup process is already running')
                ->assertExitCode(1);

            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(fn (string $message) => str_contains($message, 'File backup failed:')
                    && str_contains($message, 'already running'));
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function test_command_fails_with_nonzero_exit_on_service_configuration_error_without_leaking_password(): void
    {
        Log::spy();

        $secretPassword = 'super-secret-recovery-password-999';
        config([
            'database-backup.working_dir' => $this->destDir, // Unsafe: same as destination_dir
            'database-backup.destination_dir' => $this->destDir,
            'database-backup.archive_password' => $secretPassword,
        ]);

        $this->artisan('files:backup')
            ->expectsOutputToContain('Working directory cannot be the same as or nested within the destination directory')
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'File backup failed:')
                && !str_contains($message, $secretPassword));
    }

    public function test_isolated_extraction_verification_and_restoration_round_trip(): void
    {
        // 1. Seed source fixture with nested media, conversions, payment proofs, retained imports, and excluded staging
        File::ensureDirectoryExists($this->storageApp . '/public/42/conversions');
        File::ensureDirectoryExists($this->storageApp . '/public/payment_proofs/2026');
        File::ensureDirectoryExists($this->storageApp . '/imports/sales/results');
        File::ensureDirectoryExists($this->storageApp . '/imports/sales/temp');
        File::ensureDirectoryExists($this->storageApp . '/imports/purchases');

        $expectedPayloads = [
            'public/42/invoice.pdf' => 'pdf-attachment-content-42',
            'public/42/conversions/invoice-preview.jpg' => 'jpg-preview-content-42',
            'public/payment_proofs/2026/receipt.png' => 'png-receipt-content-2026',
            'imports/sales/sales_batch_7.csv' => "reference,total\nSL-007,250000\n",
            'imports/sales/results/sales_batch_7_result.csv' => "row,status\n1,imported\n",
            'imports/purchases/purchase_batch_3.csv' => "reference,total\nPR-003,120000\n",
        ];

        foreach ($expectedPayloads as $relPath => $content) {
            file_put_contents($this->storageApp . DIRECTORY_SEPARATOR . $relPath, $content);
        }

        // Add excluded staging and placeholder files
        file_put_contents($this->storageApp . '/public/.gitignore', "*\n!.gitignore\n");
        file_put_contents($this->storageApp . '/imports/sales/temp/staging_chunk.tmp', 'staging-must-not-restore');

        // 2. Run files:backup via Artisan command
        $this->artisan('files:backup')->assertExitCode(0);

        $archivePath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        $this->assertFileExists($archivePath);

        // 3. Extract archive to an isolated temporary recovery staging directory first
        $isolatedExtractDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'isolated_restore_staging';
        File::ensureDirectoryExists($isolatedExtractDir);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archivePath) === true);
        $this->assertTrue($zip->extractTo($isolatedExtractDir));
        $zip->close();

        // 4. Verify extracted files against manifest.json in the isolated staging directory
        $manifestPath = $isolatedExtractDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->assertFileExists($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame(count($expectedPayloads), $manifest['total_files']);

        foreach ($manifest['files'] as $entry) {
            $extractedFile = $isolatedExtractDir . DIRECTORY_SEPARATOR . $entry['path'];
            $this->assertFileExists($extractedFile);
            $this->assertSame($entry['size'], filesize($extractedFile));
            $this->assertSame($entry['sha256'], hash_file('sha256', $extractedFile));
        }

        // 5. Simulate disaster recovery into a clean target storage/app directory
        $restoredStorageApp = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'restored_storage_app';
        File::ensureDirectoryExists($restoredStorageApp);

        File::copyDirectory($isolatedExtractDir . '/public', $restoredStorageApp . '/public');
        File::copyDirectory($isolatedExtractDir . '/imports', $restoredStorageApp . '/imports');

        // 6. Verify all attachment and import references resolve at their original relative paths under storage/app
        foreach ($expectedPayloads as $relPath => $expectedContent) {
            $restoredFile = $restoredStorageApp . DIRECTORY_SEPARATOR . $relPath;
            $this->assertFileExists($restoredFile);
            $this->assertSame($expectedContent, file_get_contents($restoredFile));
        }

        $this->assertFileDoesNotExist($restoredStorageApp . '/imports/sales/temp/staging_chunk.tmp');
    }
}

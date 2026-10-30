<?php

namespace Tests\Unit;

use App\Services\FileBackupService;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class FileBackupArchiveAndEncryptionTest extends TestCase
{
    private string $tempBaseDir;
    private string $workDir;
    private string $destDir;
    private string $storageApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBaseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ts_erp_file_backup_enc_' . uniqid();
        $this->workDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'work';
        $this->destDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'dest';
        $this->storageApp = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app';

        File::ensureDirectoryExists($this->workDir);
        File::ensureDirectoryExists($this->destDir);
        File::ensureDirectoryExists($this->storageApp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempBaseDir);
        parent::tearDown();
    }

    public function test_unencrypted_archive_captures_payloads_and_manifest_with_exact_hashes_and_metadata(): void
    {
        File::ensureDirectoryExists($this->storageApp . '/public/products/conversions');
        File::ensureDirectoryExists($this->storageApp . '/imports/sales');

        $productBytes = "product-image-binary-payload-\x00\x01\x02";
        $thumbBytes = "product-thumb-binary-payload";
        $csvBytes = "invoice,customer,total\nINV-001,Walk-in,150000\n";

        $productPath = $this->storageApp . '/public/products/item.png';
        $thumbPath = $this->storageApp . '/public/products/conversions/item-thumb.png';
        $csvPath = $this->storageApp . '/imports/sales/import_2026.csv';

        file_put_contents($productPath, $productBytes);
        file_put_contents($thumbPath, $thumbBytes);
        file_put_contents($csvPath, $csvBytes);

        touch($productPath, 1700000100);
        touch($thumbPath, 1700000200);
        touch($csvPath, 1700000300);

        $service = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'archive_password' => '',
            'storage_app_path' => $this->storageApp,
        ]);

        $result = $service->backup();

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(3, $result['file_count']);

        $slotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        $this->assertFileExists($slotAPath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($slotAPath) === true);
        $this->assertSame(4, $zip->numFiles); // 3 payload files + manifest.json

        $manifestRaw = $zip->getFromName('manifest.json');
        $this->assertNotFalse($manifestRaw);
        $manifest = json_decode($manifestRaw, true);

        $this->assertIsArray($manifest);
        $this->assertNotEmpty($manifest['started_at']);
        $this->assertNotEmpty($manifest['completed_at']);
        $this->assertSame(3, $manifest['total_files']);
        $this->assertSame(strlen($productBytes) + strlen($thumbBytes) + strlen($csvBytes), $manifest['total_bytes']);
        $this->assertTrue($manifest['sources']['public']['exists']);
        $this->assertSame(2, $manifest['sources']['public']['file_count']);
        $this->assertTrue($manifest['sources']['imports']['exists']);
        $this->assertSame(1, $manifest['sources']['imports']['file_count']);

        $filesByPath = [];
        foreach ($manifest['files'] as $entry) {
            $filesByPath[$entry['path']] = $entry;
        }

        $this->assertSame(hash('sha256', $productBytes), $filesByPath['public/products/item.png']['sha256']);
        $this->assertSame(strlen($productBytes), $filesByPath['public/products/item.png']['size']);
        $this->assertSame(1700000100, $filesByPath['public/products/item.png']['mtime']);

        $this->assertSame($productBytes, $zip->getFromName('public/products/item.png'));
        $this->assertSame($thumbBytes, $zip->getFromName('public/products/conversions/item-thumb.png'));
        $this->assertSame($csvBytes, $zip->getFromName('imports/sales/import_2026.csv'));

        $zip->close();
    }

    public function test_empty_installation_produces_valid_manifest_only_archive(): void
    {
        // Neither storage/app/public nor storage/app/imports exists
        $service = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'archive_password' => '',
            'storage_app_path' => $this->storageApp,
        ]);

        $result = $service->backup();

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(0, $result['file_count']);

        $slotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        $this->assertFileExists($slotAPath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($slotAPath) === true);
        $this->assertSame(1, $zip->numFiles);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertIsArray($manifest);
        $this->assertSame(0, $manifest['total_files']);
        $this->assertSame(0, $manifest['total_bytes']);
        $this->assertSame([], $manifest['files']);
        $this->assertFalse($manifest['sources']['public']['exists']);
        $this->assertFalse($manifest['sources']['imports']['exists']);
    }

    public function test_encrypted_archive_protects_all_entries_and_rejects_missing_or_wrong_password(): void
    {
        if (!$this->supportsAES256Encryption()) {
            $this->markTestSkipped('AES-256 encryption not supported by libzip');
        }

        File::ensureDirectoryExists($this->storageApp . '/public/avatars');
        File::ensureDirectoryExists($this->storageApp . '/imports/purchases');
        file_put_contents($this->storageApp . '/public/avatars/admin.png', 'avatar-bytes-content');
        file_put_contents($this->storageApp . '/imports/purchases/po.csv', "po_no,total\nPO-1,5000\n");

        $password = 'correct-horse-battery-staple';
        $service = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'archive_password' => $password,
            'storage_app_path' => $this->storageApp,
        ]);

        $result = $service->backup();
        $this->assertTrue($result['success'], $result['message'] ?? '');

        $slotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        $this->assertFileExists($slotAPath);

        // 1. Verify every entry has AES-256 encryption enabled and cannot be read without password
        $zipNoPw = new ZipArchive();
        $this->assertTrue($zipNoPw->open($slotAPath) === true);
        $this->assertSame(3, $zipNoPw->numFiles);

        for ($i = 0; $i < $zipNoPw->numFiles; $i++) {
            $stat = $zipNoPw->statIndex($i);
            $this->assertSame(ZipArchive::EM_AES_256, $stat['encryption_method'], "Entry {$stat['name']} must be AES-256 encrypted");
        }

        $this->assertFalse($zipNoPw->getFromName('manifest.json'));
        $this->assertFalse($zipNoPw->getFromName('public/avatars/admin.png'));
        $this->assertFalse($zipNoPw->getFromName('imports/purchases/po.csv'));
        $zipNoPw->close();

        // 2. Reading with wrong password must fail
        $zipWrongPw = new ZipArchive();
        $this->assertTrue($zipWrongPw->open($slotAPath) === true);
        $zipWrongPw->setPassword('wrong-password-123');
        $this->assertFalse(@$zipWrongPw->getFromName('manifest.json'));
        $this->assertFalse(@$zipWrongPw->getFromName('public/avatars/admin.png'));
        $this->assertFalse(@$zipWrongPw->getFromName('imports/purchases/po.csv'));
        $zipWrongPw->close();

        // 3. Reading with correct password must succeed for manifest and all payloads
        $zipRightPw = new ZipArchive();
        $this->assertTrue($zipRightPw->open($slotAPath) === true);
        $zipRightPw->setPassword($password);
        $manifestRaw = $zipRightPw->getFromName('manifest.json');
        $avatarRaw = $zipRightPw->getFromName('public/avatars/admin.png');
        $poRaw = $zipRightPw->getFromName('imports/purchases/po.csv');
        $zipRightPw->close();

        $this->assertNotFalse($manifestRaw);
        $this->assertSame('avatar-bytes-content', $avatarRaw);
        $this->assertSame("po_no,total\nPO-1,5000\n", $poRaw);
    }

    public function test_encryption_failure_preserves_existing_slots_and_cleans_workspace(): void
    {
        $slotAPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-a.zip';
        $slotBPath = $this->destDir . DIRECTORY_SEPARATOR . 'files-backup-b.zip';
        file_put_contents($slotAPath, 'prior-slot-a-bytes');
        file_put_contents($slotBPath, 'prior-slot-b-bytes');

        File::ensureDirectoryExists($this->storageApp . '/public');
        file_put_contents($this->storageApp . '/public/file.txt', 'content');

        $config = [
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'archive_password' => 'secret-password',
            'storage_app_path' => $this->storageApp,
        ];

        $service = new class($config) extends FileBackupService {
            protected function applyArchiveEncryption(ZipArchive $zip, array $entryNames): void
            {
                throw new RuntimeException('Failed to enable AES-256 encryption (unsupported by libzip)');
            }
        };

        $result = $service->backup();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('encryption', strtolower($result['message']));
        $this->assertSame('prior-slot-a-bytes', file_get_contents($slotAPath));
        $this->assertSame('prior-slot-b-bytes', file_get_contents($slotBPath));
        $this->assertEmpty(glob($this->workDir . DIRECTORY_SEPARATOR . 'temp_files_*'));
    }

    private function supportsAES256Encryption(): bool
    {
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'zip_aes_test_');
            $zip = new ZipArchive();
            $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('test.txt', 'test content');
            $result = $zip->setEncryptionName('test.txt', ZipArchive::EM_AES_256);
            $zip->setPassword('test');
            $zip->close();
            @unlink($tempFile);
            return $result !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}


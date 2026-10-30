<?php

namespace Tests\Unit;

use App\Services\FileBackupService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FileBackupSourceInventoryTest extends TestCase
{
    private string $tempBaseDir;
    private string $workDir;
    private string $destDir;
    private string $storageApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBaseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ts_erp_file_backup_inv_' . uniqid();
        $this->workDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'work';
        $this->destDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'dest';
        $this->storageApp = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app';

        File::ensureDirectoryExists($this->workDir);
        File::ensureDirectoryExists($this->destDir);
        File::ensureDirectoryExists($this->storageApp);
    }

    protected function tearDown(): void
    {
        // Restore permissions on any chmod-locked fixture paths before deletion
        if (is_dir($this->tempBaseDir)) {
            $this->restorePermissionsRecursively($this->tempBaseDir);
        }
        File::deleteDirectory($this->tempBaseDir);
        parent::tearDown();
    }

    private function restorePermissionsRecursively(string $dir): void
    {
        if (is_link($dir)) {
            return;
        }
        @chmod($dir, 0755);
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $this->restorePermissionsRecursively($path);
            } else {
                @chmod($path, 0644);
            }
        }
    }

    public function test_inventory_includes_nested_public_media_conversions_and_retained_imports_while_excluding_staging_and_placeholders(): void
    {
        // Public media & conversions
        File::ensureDirectoryExists($this->storageApp . '/public/14/conversions');
        File::ensureDirectoryExists($this->storageApp . '/public/14/responsive-images');
        File::ensureDirectoryExists($this->storageApp . '/public/payment_proofs/2026/10');
        file_put_contents($this->storageApp . '/public/.gitignore', "*\n!.gitignore\n");
        file_put_contents($this->storageApp . '/public/14/product.jpg', 'original-image-bytes');
        file_put_contents($this->storageApp . '/public/14/conversions/product-thumb.jpg', 'thumb-bytes');
        file_put_contents($this->storageApp . '/public/14/responsive-images/product___media_library_original.jpg', 'responsive-bytes');
        file_put_contents($this->storageApp . '/public/payment_proofs/2026/10/proof.png', 'proof-bytes');

        // Retained imports and excluded sales staging
        File::ensureDirectoryExists($this->storageApp . '/imports/sales/temp/chunks');
        File::ensureDirectoryExists($this->storageApp . '/imports/sales/results');
        File::ensureDirectoryExists($this->storageApp . '/imports/purchases');
        File::ensureDirectoryExists($this->storageApp . '/imports/expenses');
        file_put_contents($this->storageApp . '/imports/.gitignore', "*\n!.gitignore\n");
        file_put_contents($this->storageApp . '/imports/sales/2026_sales.csv', "id,total\n1,1000\n");
        file_put_contents($this->storageApp . '/imports/sales/results/batch_1_result.csv', "row,status\n1,ok\n");
        file_put_contents($this->storageApp . '/imports/purchases/2026_purchases.csv', "id,total\n2,500\n");
        file_put_contents($this->storageApp . '/imports/expenses/2026_expenses.csv', "id,amount\n3,250\n");

        // Excluded staging inside imports/sales/temp
        file_put_contents($this->storageApp . '/imports/sales/temp/chunk_01.part', 'temp-chunk');
        file_put_contents($this->storageApp . '/imports/sales/temp/chunks/nested_temp.csv', 'nested-temp');

        // Excluded sibling directories outside public/ and imports/
        File::ensureDirectoryExists($this->storageApp . '/temp');
        File::ensureDirectoryExists($this->storageApp . '/pos/temp_payment_images');
        file_put_contents($this->storageApp . '/.gitignore', "*\n!.gitignore\n");
        file_put_contents($this->storageApp . '/temp/upload_staging.tmp', 'upload-staging');
        file_put_contents($this->storageApp . '/pos/temp_payment_images/pos_temp.png', 'pos-staging');

        $service = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'storage_app_path' => $this->storageApp,
        ]);

        $inventory = $service->collectSourceInventory();

        $paths = array_column($inventory['files'], 'path');
        $expectedPaths = [
            'public/14/conversions/product-thumb.jpg',
            'public/14/product.jpg',
            'public/14/responsive-images/product___media_library_original.jpg',
            'public/payment_proofs/2026/10/proof.png',
            'imports/expenses/2026_expenses.csv',
            'imports/purchases/2026_purchases.csv',
            'imports/sales/2026_sales.csv',
            'imports/sales/results/batch_1_result.csv',
        ];

        $this->assertSame($expectedPaths, $paths);
        $this->assertSame(8, $inventory['total_files']);
        $this->assertTrue($inventory['sources']['public']['exists']);
        $this->assertSame(4, $inventory['sources']['public']['file_count']);
        $this->assertTrue($inventory['sources']['imports']['exists']);
        $this->assertSame(4, $inventory['sources']['imports']['file_count']);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('imports/sales/temp', $path);
            $this->assertStringNotContainsString('.gitignore', $path);
            $this->assertStringNotContainsString('temp_payment_images', $path);
        }
    }

    public function test_absent_and_empty_source_roots_are_recorded_gracefully(): void
    {
        $service = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'storage_app_path' => $this->storageApp,
        ]);

        // Both public and imports absent
        $inventoryAbsent = $service->collectSourceInventory();
        $this->assertSame(0, $inventoryAbsent['total_files']);
        $this->assertSame(0, $inventoryAbsent['total_bytes']);
        $this->assertFalse($inventoryAbsent['sources']['public']['exists']);
        $this->assertFalse($inventoryAbsent['sources']['imports']['exists']);

        // Public exists with only .gitignore placeholder, imports still absent
        File::ensureDirectoryExists($this->storageApp . '/public');
        file_put_contents($this->storageApp . '/public/.gitignore', "*\n!.gitignore\n");

        $inventoryEmptyPublic = $service->collectSourceInventory();
        $this->assertSame(0, $inventoryEmptyPublic['total_files']);
        $this->assertSame(0, $inventoryEmptyPublic['total_bytes']);
        $this->assertTrue($inventoryEmptyPublic['sources']['public']['exists']);
        $this->assertSame(0, $inventoryEmptyPublic['sources']['public']['file_count']);
        $this->assertFalse($inventoryEmptyPublic['sources']['imports']['exists']);
    }

    public function test_unreadable_source_file_or_directory_fails(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('POSIX non-root chmod test requires non-root POSIX environment');
        }

        File::ensureDirectoryExists($this->storageApp . '/public/locked_dir');
        $lockedFile = $this->storageApp . '/public/locked_file.jpg';
        file_put_contents($lockedFile, 'secret-bytes');
        chmod($lockedFile, 0000);

        $service = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'storage_app_path' => $this->storageApp,
        ]);

        $fileResult = $service->backup();
        $this->assertFalse($fileResult['success']);
        $this->assertStringContainsString('not readable', $fileResult['message']);

        chmod($lockedFile, 0644);
        $lockedDir = $this->storageApp . '/public/locked_dir';
        file_put_contents($lockedDir . '/inside.jpg', 'inside');
        chmod($lockedDir, 0000);

        $dirResult = $service->backup();
        $this->assertFalse($dirResult['success']);
        $this->assertStringContainsString('not readable', $dirResult['message']);
    }

    public function test_unsupported_media_disk_configurations_fail_clearly(): void
    {
        // Case 1: media-library.disk_name overridden to s3
        config(['media-library.disk_name' => 's3']);
        $serviceS3 = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'storage_app_path' => $this->storageApp,
        ]);
        $resultS3 = $serviceS3->backup();
        $this->assertFalse($resultS3['success']);
        $this->assertStringContainsString('Unsupported media disk', $resultS3['message']);

        // Case 2: public disk driver changed from local
        config([
            'media-library.disk_name' => 'public',
            'filesystems.disks.public.driver' => 's3',
        ]);
        $serviceDriver = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'storage_app_path' => $this->storageApp,
        ]);
        $resultDriver = $serviceDriver->backup();
        $this->assertFalse($resultDriver['success']);
        $this->assertStringContainsString('Unsupported public disk driver', $resultDriver['message']);

        // Case 3: default storage_app_path with public disk root pointing elsewhere
        config([
            'media-library.disk_name' => 'public',
            'filesystems.disks.public.driver' => 'local',
            'filesystems.disks.public.root' => $this->tempBaseDir . '/unexpected_public_root',
            'file-backup.storage_app_path' => $this->storageApp,
        ]);
        $serviceRoot = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
        ]);
        $resultRoot = $serviceRoot->backup();
        $this->assertFalse($resultRoot['success']);
        $this->assertStringContainsString('Unsupported public disk root', $resultRoot['message']);
    }

    public function test_descendant_and_root_symlinks_are_rejected(): void
    {
        $outsideDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'outside';
        File::ensureDirectoryExists($outsideDir);
        $outsideFile = $outsideDir . DIRECTORY_SEPARATOR . 'external.txt';
        file_put_contents($outsideFile, 'external');

        File::ensureDirectoryExists($this->storageApp . '/public');
        $symlinkFile = $this->storageApp . '/public/linked_file.txt';
        if (!@symlink($outsideFile, $symlinkFile)) {
            $this->markTestSkipped('Symlink creation not permitted in current environment');
        }

        $service = new FileBackupService([
            'working_dir' => $this->workDir,
            'destination_dir' => $this->destDir,
            'storage_app_path' => $this->storageApp,
        ]);

        $fileLinkResult = $service->backup();
        $this->assertFalse($fileLinkResult['success']);
        $this->assertStringContainsString('Symbolic links or junctions are not supported', $fileLinkResult['message']);

        @unlink($symlinkFile);

        // Descendant directory link inside imports/
        File::ensureDirectoryExists($this->storageApp . '/imports');
        $symlinkDir = $this->storageApp . '/imports/linked_dir';
        @symlink($outsideDir, $symlinkDir);

        $dirLinkResult = $service->backup();
        $this->assertFalse($dirLinkResult['success']);
        $this->assertStringContainsString('Symbolic links or junctions are not supported', $dirLinkResult['message']);

        @unlink($symlinkDir);

        // Root link at storage/app/public itself
        File::deleteDirectory($this->storageApp . '/public');
        @symlink($outsideDir, $this->storageApp . '/public');

        $rootLinkResult = $service->backup();
        $this->assertFalse($rootLinkResult['success']);
        $this->assertStringContainsString('Symbolic links or junctions are not supported', $rootLinkResult['message']);
    }
}


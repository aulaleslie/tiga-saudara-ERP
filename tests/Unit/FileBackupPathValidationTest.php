<?php

namespace Tests\Unit;

use App\Services\FileBackupService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FileBackupPathValidationTest extends TestCase
{
    private string $tempBaseDir;
    private string $storageApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBaseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ts_erp_file_backup_path_' . uniqid();
        $this->storageApp = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app';
        File::ensureDirectoryExists($this->storageApp . DIRECTORY_SEPARATOR . 'public');
        File::ensureDirectoryExists($this->storageApp . DIRECTORY_SEPARATOR . 'imports');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempBaseDir);
        parent::tearDown();
    }

    /**
     * @dataProvider invalidWindowsAndPosixPathProvider
     */
    public function test_unsafe_working_destination_and_volume_configurations_fail(
        string $workingDir,
        string $destinationDir,
        string $storageAppPath,
        string $expectedMessageFragment
    ): void {
        $service = new FileBackupService([
            'working_dir' => $workingDir,
            'destination_dir' => $destinationDir,
            'storage_app_path' => $storageAppPath,
        ]);

        $result = $service->backup();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString($expectedMessageFragment, $result['message']);
    }

    public static function invalidWindowsAndPosixPathProvider(): array
    {
        return [
            'identical Windows paths case-insensitive' => [
                'D:\\Backup_WK',
                'd:\\backup_wk',
                'D:\\App\\storage\\app',
                'cannot be the same as or nested within',
            ],
            'Windows dot-dot alias resolving to destination' => [
                'D:\\Backup_Work\\..\\backup_wk\\',
                'D:/Backup_WK',
                'D:\\App\\storage\\app',
                'cannot be the same as or nested within',
            ],
            'working nested inside destination on Windows' => [
                'D:\\Backup_WK\\temp_work',
                'd:\\backup_wk',
                'D:\\App\\storage\\app',
                'cannot be the same as or nested within',
            ],
            'destination nested inside working on Windows' => [
                'D:\\Backup_Work',
                'd:\\backup_work\\final_dest',
                'D:\\App\\storage\\app',
                'Destination directory cannot be nested within the working directory',
            ],
            'different Windows drive letters' => [
                'C:\\Backup_Work',
                'D:\\Backup_WK',
                'D:\\App\\storage\\app',
                'same volume',
            ],
            'different Windows UNC shares' => [
                '\\\\fileserver\\share_work\\temp',
                '\\\\fileserver\\share_dest\\final',
                'D:\\App\\storage\\app',
                'same volume',
            ],
            'UNC working vs drive letter destination' => [
                '\\\\fileserver\\share_work\\temp',
                'D:\\Backup_WK',
                'D:\\App\\storage\\app',
                'same volume',
            ],
            'Windows working dir inside public source root (case-insensitive)' => [
                'D:\\App\\storage\\app\\PUBLIC\\work',
                'D:\\Backup_WK',
                'd:\\app\\storage\\app',
                'Backup working directory cannot overlap with source directory',
            ],
            'Windows destination dir equals imports source root' => [
                'D:\\Backup_Work',
                'D:\\App\\storage\\app\\imports',
                'D:\\App\\storage\\app',
                'Backup destination directory cannot overlap with source directory',
            ],
            'Windows destination dir contains storage/app source trees' => [
                'D:\\Backup_Work',
                'D:\\App\\storage',
                'D:\\App\\storage\\app',
                'Backup destination directory cannot overlap with source directory',
            ],
        ];
    }

    public function test_local_dot_dot_and_symlink_aliases_and_source_overlaps_are_rejected(): void
    {
        $realWork = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'real_work';
        $realDest = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'real_dest';
        File::ensureDirectoryExists($realWork);
        File::ensureDirectoryExists($realDest);

        // 1. Dot-dot alias pointing working_dir to destination_dir
        $dotDotAlias = $realWork . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'real_dest';
        $serviceDotDot = new FileBackupService([
            'working_dir' => $dotDotAlias,
            'destination_dir' => $realDest,
            'storage_app_path' => $this->storageApp,
        ]);
        $resDotDot = $serviceDotDot->backup();
        $this->assertFalse($resDotDot['success']);
        $this->assertStringContainsString('cannot be the same as or nested within', $resDotDot['message']);

        // 2. Working directory overlapping storage/app/public directly or as parent (storage/app)
        $serviceSourceParent = new FileBackupService([
            'working_dir' => $this->storageApp,
            'destination_dir' => $realDest,
            'storage_app_path' => $this->storageApp,
        ]);
        $resSourceParent = $serviceSourceParent->backup();
        $this->assertFalse($resSourceParent['success']);
        $this->assertStringContainsString('Backup working directory cannot overlap with source directory', $resSourceParent['message']);

        // 3. Destination directory nested inside storage/app/imports
        $serviceDestInImports = new FileBackupService([
            'working_dir' => $realWork,
            'destination_dir' => $this->storageApp . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR . 'backups',
            'storage_app_path' => $this->storageApp,
        ]);
        $resDestInImports = $serviceDestInImports->backup();
        $this->assertFalse($resDestInImports['success']);
        $this->assertStringContainsString('Backup destination directory cannot overlap with source directory', $resDestInImports['message']);

        // 4. Symlink alias pointing working_dir inside destination_dir
        $symlinkWork = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'symlink_to_dest';
        if (@symlink($realDest, $symlinkWork)) {
            $serviceSymlink = new FileBackupService([
                'working_dir' => $symlinkWork . DIRECTORY_SEPARATOR . 'nested_child',
                'destination_dir' => $realDest,
                'storage_app_path' => $this->storageApp,
            ]);
            $resSymlink = $serviceSymlink->backup();
            $this->assertFalse($resSymlink['success']);
            $this->assertStringContainsString('cannot be the same as or nested within', $resSymlink['message']);
        }

        // 5. Symlink alias pointing destination_dir inside storage/app/public
        $symlinkDestToPublic = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'symlink_to_public';
        if (@symlink($this->storageApp . DIRECTORY_SEPARATOR . 'public', $symlinkDestToPublic)) {
            $serviceSymlinkSource = new FileBackupService([
                'working_dir' => $realWork,
                'destination_dir' => $symlinkDestToPublic . DIRECTORY_SEPARATOR . 'dest_sub',
                'storage_app_path' => $this->storageApp,
            ]);
            $resSymlinkSource = $serviceSymlinkSource->backup();
            $this->assertFalse($resSymlinkSource['success']);
            $this->assertStringContainsString('Backup destination directory cannot overlap with source directory', $resSymlinkSource['message']);
        }
    }

    public function test_symlink_combined_with_dot_dot_parent_segments_resolves_alias_before_collapsing_parent(): void
    {
        $realWork = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'real_work';
        $realDest = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'real_dest';
        $publicSub = $this->storageApp . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'sub';
        File::ensureDirectoryExists($realWork);
        File::ensureDirectoryExists($realDest . DIRECTORY_SEPARATOR . 'sub');
        File::ensureDirectoryExists($publicSub);

        $aliasToPublicSub = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'alias_to_public_sub';
        if (!@symlink($publicSub, $aliasToPublicSub)) {
            $this->markTestSkipped('Symlink creation not permitted in current environment');
        }

        // Destination: alias/../backups -> resolves on disk to storage/app/public/backups (inside source tree)
        $destViaAliasAndDotDot = $aliasToPublicSub . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'backups';
        $serviceDestOverlap = new FileBackupService([
            'working_dir' => $realWork,
            'destination_dir' => $destViaAliasAndDotDot,
            'storage_app_path' => $this->storageApp,
        ]);
        $resDest = $serviceDestOverlap->backup();
        $this->assertFalse($resDest['success']);
        $this->assertStringContainsString('Backup destination directory cannot overlap with source directory', $resDest['message']);
        $this->assertDirectoryDoesNotExist($this->storageApp . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'backups');

        // Working: alias/../work -> resolves on disk to storage/app/public/work (inside source tree)
        $workViaAliasAndDotDot = $aliasToPublicSub . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'work';
        $serviceWorkOverlap = new FileBackupService([
            'working_dir' => $workViaAliasAndDotDot,
            'destination_dir' => $realDest,
            'storage_app_path' => $this->storageApp,
        ]);
        $resWork = $serviceWorkOverlap->backup();
        $this->assertFalse($resWork['success']);
        $this->assertStringContainsString('Backup working directory cannot overlap with source directory', $resWork['message']);
        $this->assertDirectoryDoesNotExist($this->storageApp . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'work');

        // Working inside destination via alias/../nested_work where alias points to real_dest/sub
        $aliasToDestSub = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'alias_to_dest_sub';
        @symlink($realDest . DIRECTORY_SEPARATOR . 'sub', $aliasToDestSub);
        $workInsideDestViaAlias = $aliasToDestSub . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'nested_work';
        $serviceWorkInDest = new FileBackupService([
            'working_dir' => $workInsideDestViaAlias,
            'destination_dir' => $realDest,
            'storage_app_path' => $this->storageApp,
        ]);
        $resWorkInDest = $serviceWorkInDest->backup();
        $this->assertFalse($resWorkInDest['success']);
        $this->assertStringContainsString('cannot be the same as or nested within', $resWorkInDest['message']);
    }
}


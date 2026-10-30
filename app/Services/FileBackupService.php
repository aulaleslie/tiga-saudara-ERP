<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

class FileBackupService
{
    public const LOCK_FILENAME = 'file-backup.lock';
    public const MANIFEST_FILENAME = 'manifest.json';

    private string $workingDir;
    private string $destinationDir;
    private string $slotA;
    private string $slotB;
    private string $dbSlotA;
    private string $dbSlotB;
    private string $archivePassword;
    private string $storageAppPath;
    private bool $customStorageAppPath;
    private ?string $mediaDiskOverride;

    public function __construct(?array $config = null)
    {
        $dbConfig = config('database-backup', []);
        $fileConfig = config('file-backup', []);
        $overrides = $config ?? [];

        $this->workingDir = (string) ($overrides['working_dir'] ?? $dbConfig['working_dir'] ?? sys_get_temp_dir());
        $this->destinationDir = (string) ($overrides['destination_dir'] ?? $dbConfig['destination_dir'] ?? '');
        $this->archivePassword = (string) ($overrides['archive_password'] ?? $dbConfig['archive_password'] ?? '');
        $this->slotA = (string) ($overrides['slot_a'] ?? $fileConfig['slot_a'] ?? 'files-backup-a.zip');
        $this->slotB = (string) ($overrides['slot_b'] ?? $fileConfig['slot_b'] ?? 'files-backup-b.zip');
        $this->dbSlotA = (string) ($overrides['db_slot_a'] ?? $dbConfig['slot_a'] ?? 'database-backup-a.zip');
        $this->dbSlotB = (string) ($overrides['db_slot_b'] ?? $dbConfig['slot_b'] ?? 'database-backup-b.zip');
        $this->storageAppPath = (string) ($overrides['storage_app_path'] ?? $fileConfig['storage_app_path'] ?? storage_path('app'));
        $this->customStorageAppPath = array_key_exists('storage_app_path', $overrides);
        $this->mediaDiskOverride = array_key_exists('media_disk', $overrides) ? (string) $overrides['media_disk'] : null;
    }

    public function getResolvedConfiguration(): array
    {
        return [
            'working_dir' => $this->workingDir,
            'destination_dir' => $this->destinationDir,
            'archive_password' => $this->archivePassword,
            'slot_a' => $this->slotA,
            'slot_b' => $this->slotB,
            'db_slot_a' => $this->dbSlotA,
            'db_slot_b' => $this->dbSlotB,
            'storage_app_path' => $this->storageAppPath,
        ];
    }

    public function backup(): array
    {
        try {
            $this->validateConfiguration();

            $lockHandle = $this->acquireLock();
            $tempDir = null;

            try {
                $tempDir = $this->createTemporaryWorkspace();
                $startedAt = gmdate('Y-m-d\TH:i:s\Z');

                $inventory = $this->collectSourceInventory();
                $archiveResult = $this->createArchive($inventory, $tempDir, $startedAt);
                $archiveFile = $archiveResult['archive_file'];

                $this->onAfterArchiveCreated($archiveFile, $inventory);

                $verifiedManifest = $this->verifyArchive($archiveFile, $inventory);
                $finalSlot = $this->selectFinalSlot();
                $this->promoteArchive($archiveFile, $finalSlot);

                clearstatcache(true, $finalSlot);
                $size = filesize($finalSlot);
                if ($size === false) {
                    throw new RuntimeException("Failed to determine promoted archive size: {$finalSlot}");
                }

                $fileCount = (int) ($verifiedManifest['total_files'] ?? 0);
                $message = "File backup successful: {$finalSlot} ({$size} bytes, {$fileCount} files)";
                Log::info($message);

                return [
                    'success' => true,
                    'message' => $message,
                    'slot' => $finalSlot,
                    'size' => $size,
                    'file_count' => $fileCount,
                    'manifest' => $verifiedManifest,
                ];
            } finally {
                if ($tempDir !== null) {
                    $this->cleanupTemporaryWorkspace($tempDir);
                }
                $this->releaseLock($lockHandle);
            }
        } catch (RuntimeException $e) {
            Log::error("File backup failed: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function validateConfiguration(): void
    {
        $this->validateSlotConfiguration();
        $this->validateMediaDiskConfiguration();
        $this->validateDirectorySeparation();

        if (!is_dir($this->workingDir)) {
            if (!@mkdir($this->workingDir, 0755, true)) {
                throw new RuntimeException("Failed to create backup working directory: {$this->workingDir}");
            }
        }

        if (!is_writable($this->workingDir)) {
            throw new RuntimeException("Backup working directory is not writable: {$this->workingDir}");
        }

        if (!is_dir($this->destinationDir)) {
            if (!@mkdir($this->destinationDir, 0755, true)) {
                throw new RuntimeException("Failed to create backup destination directory: {$this->destinationDir}");
            }
        }

        if (!is_writable($this->destinationDir)) {
            throw new RuntimeException("Backup destination directory is not writable: {$this->destinationDir}");
        }
    }

    protected function validateSlotConfiguration(): void
    {
        foreach (['slot_a' => $this->slotA, 'slot_b' => $this->slotB] as $key => $slot) {
            if (trim($slot) === '' || $slot !== trim($slot)) {
                throw new RuntimeException("Invalid file backup {$key}: filename cannot be empty or have leading/trailing whitespace");
            }

            if (
                $slot === '.' ||
                $slot === '..' ||
                str_contains($slot, '..') ||
                str_contains($slot, '/') ||
                str_contains($slot, '\\') ||
                str_contains($slot, ':') ||
                str_contains($slot, "\0") ||
                basename($slot) !== $slot
            ) {
                throw new RuntimeException("Invalid file backup {$key} '{$slot}': path traversal and directory separators are not allowed");
            }
        }

        if (strtolower($this->slotA) === strtolower($this->slotB)) {
            throw new RuntimeException("File backup slot_a and slot_b must be distinct filenames: {$this->slotA}");
        }

        $dbSlots = array_map('strtolower', array_filter([$this->dbSlotA, $this->dbSlotB], fn ($s) => $s !== ''));
        if (in_array(strtolower($this->slotA), $dbSlots, true) || in_array(strtolower($this->slotB), $dbSlots, true)) {
            throw new RuntimeException("File backup slot filenames must not collide with database backup slot filenames");
        }
    }

    protected function validateMediaDiskConfiguration(): void
    {
        $mediaDisk = $this->mediaDiskOverride ?? config('media-library.disk_name') ?? env('MEDIA_DISK', 'public');

        if ($mediaDisk !== 'public') {
            throw new RuntimeException("Unsupported media disk '{$mediaDisk}': file backup requires the 'public' local disk");
        }

        $publicDiskConfig = config('filesystems.disks.public');
        if (!is_array($publicDiskConfig) || ($publicDiskConfig['driver'] ?? null) !== 'local') {
            $driver = is_array($publicDiskConfig) ? ($publicDiskConfig['driver'] ?? 'none') : 'unconfigured';
            throw new RuntimeException("Unsupported public disk driver '{$driver}': file backup requires the 'local' driver");
        }

        if (!$this->customStorageAppPath && !empty($publicDiskConfig['root'])) {
            $expectedPublicRoot = $this->storageAppPath . DIRECTORY_SEPARATOR . 'public';
            if (!$this->pathsAreEqual($publicDiskConfig['root'], $expectedPublicRoot)) {
                throw new RuntimeException("Unsupported public disk root '{$publicDiskConfig['root']}': expected '{$expectedPublicRoot}'");
            }
        }
    }

    protected function validateDirectorySeparation(): void
    {
        if (trim($this->workingDir) === '' || trim($this->destinationDir) === '') {
            throw new RuntimeException("Backup working directory and destination directory must be configured");
        }

        $normWorking = $this->resolveNormalizedPath($this->workingDir);
        $normDest = $this->resolveNormalizedPath($this->destinationDir);

        $caseInsensitive = PHP_OS_FAMILY === 'Windows'
            || $this->looksLikeWindowsPath($this->workingDir)
            || $this->looksLikeWindowsPath($this->destinationDir)
            || $this->looksLikeWindowsPath($normWorking)
            || $this->looksLikeWindowsPath($normDest);

        $cmpWorking = $this->toComparablePath($normWorking, $caseInsensitive);
        $cmpDest = $this->toComparablePath($normDest, $caseInsensitive);

        if ($cmpWorking === $cmpDest || $this->isPathPrefix($cmpWorking, $cmpDest)) {
            throw new RuntimeException("Working directory cannot be the same as or nested within the destination directory: {$this->workingDir}");
        }

        if ($this->isPathPrefix($cmpDest, $cmpWorking)) {
            throw new RuntimeException("Destination directory cannot be nested within the working directory: {$this->destinationDir}");
        }

        $this->validateSameVolume($normWorking, $normDest);

        $sourceRoots = [
            $this->storageAppPath . DIRECTORY_SEPARATOR . 'public',
            $this->storageAppPath . DIRECTORY_SEPARATOR . 'imports',
        ];

        foreach ($sourceRoots as $sourceRoot) {
            $normSource = $this->resolveNormalizedPath($sourceRoot);
            $sourceCaseInsensitive = $caseInsensitive
                || $this->looksLikeWindowsPath($sourceRoot)
                || $this->looksLikeWindowsPath($normSource);

            $cmpSource = $this->toComparablePath($normSource, $sourceCaseInsensitive);
            $cmpWorkForSource = $this->toComparablePath($normWorking, $sourceCaseInsensitive);
            $cmpDestForSource = $this->toComparablePath($normDest, $sourceCaseInsensitive);

            if (
                $cmpWorkForSource === $cmpSource ||
                $this->isPathPrefix($cmpWorkForSource, $cmpSource) ||
                $this->isPathPrefix($cmpSource, $cmpWorkForSource)
            ) {
                throw new RuntimeException("Backup working directory cannot overlap with source directory: {$this->workingDir}");
            }

            if (
                $cmpDestForSource === $cmpSource ||
                $this->isPathPrefix($cmpDestForSource, $cmpSource) ||
                $this->isPathPrefix($cmpSource, $cmpDestForSource)
            ) {
                throw new RuntimeException("Backup destination directory cannot overlap with source directory: {$this->destinationDir}");
            }
        }
    }

    protected function validateSameVolume(string $normWorking, string $normDest): void
    {
        $workWinRoot = $this->extractWindowsVolumeRoot($normWorking);
        $destWinRoot = $this->extractWindowsVolumeRoot($normDest);

        if ($workWinRoot !== null || $destWinRoot !== null) {
            if ($workWinRoot === null || $destWinRoot === null || strtolower($workWinRoot) !== strtolower($destWinRoot)) {
                throw new RuntimeException("Working directory and destination directory must be on the same volume");
            }
            return;
        }

        $workDevice = $this->getExistingAncestorDevice($normWorking);
        $destDevice = $this->getExistingAncestorDevice($normDest);

        if ($workDevice !== null && $destDevice !== null && $workDevice !== $destDevice) {
            throw new RuntimeException("Working directory and destination directory must be on the same volume");
        }
    }

    public function collectSourceInventory(): array
    {
        if (is_link($this->storageAppPath)) {
            throw new RuntimeException("Symbolic links or junctions are not supported for storage/app path: {$this->storageAppPath}");
        }

        $sources = [];
        $files = [];
        $totalFiles = 0;
        $totalBytes = 0;

        foreach (['public', 'imports'] as $sourceKey) {
            $rootPath = $this->storageAppPath . DIRECTORY_SEPARATOR . $sourceKey;

            if ($this->isLinkOrJunction($rootPath)) {
                throw new RuntimeException("Symbolic links or junctions are not supported in backup sources: {$sourceKey}");
            }

            if (!file_exists($rootPath)) {
                $sources[$sourceKey] = [
                    'root' => $sourceKey,
                    'exists' => false,
                    'file_count' => 0,
                    'total_bytes' => 0,
                ];
                continue;
            }

            if (!is_dir($rootPath)) {
                throw new RuntimeException("Backup source path is not a directory: {$sourceKey}");
            }

            if (!is_readable($rootPath)) {
                throw new RuntimeException("Backup source directory is not readable: {$sourceKey}");
            }

            $realRootPath = @realpath($rootPath);
            if ($realRootPath === false) {
                throw new RuntimeException("Failed to resolve backup source directory: {$sourceKey}");
            }

            $sourceFileCount = 0;
            $sourceBytes = 0;

            $this->scanSourceDirectory(
                $rootPath,
                $realRootPath,
                $sourceKey,
                $sourceKey,
                $files,
                $sourceFileCount,
                $sourceBytes
            );

            $sources[$sourceKey] = [
                'root' => $sourceKey,
                'exists' => true,
                'file_count' => $sourceFileCount,
                'total_bytes' => $sourceBytes,
            ];

            $totalFiles += $sourceFileCount;
            $totalBytes += $sourceBytes;
        }

        return [
            'sources' => $sources,
            'files' => $files,
            'total_files' => $totalFiles,
            'total_bytes' => $totalBytes,
        ];
    }

    protected function scanSourceDirectory(
        string $currentDir,
        string $realRootPath,
        string $sourceKey,
        string $relativePrefix,
        array &$files,
        int &$sourceFileCount,
        int &$sourceBytes
    ): void {
        if (!is_readable($currentDir)) {
            throw new RuntimeException("Backup source directory is not readable: {$relativePrefix}");
        }

        $entries = @scandir($currentDir);
        if ($entries === false) {
            throw new RuntimeException("Failed to read backup source directory: {$relativePrefix}");
        }

        sort($entries, SORT_STRING);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relPath = $relativePrefix . '/' . $entry;
            $fullPath = $currentDir . DIRECTORY_SEPARATOR . $entry;

            if ($this->shouldExcludeRelativePath($relPath, $entry)) {
                continue;
            }

            if ($this->isLinkOrJunction($fullPath, $realRootPath)) {
                throw new RuntimeException("Symbolic links or junctions are not supported in backup sources: {$relPath}");
            }

            if (is_dir($fullPath)) {
                $this->scanSourceDirectory(
                    $fullPath,
                    $realRootPath,
                    $sourceKey,
                    $relPath,
                    $files,
                    $sourceFileCount,
                    $sourceBytes
                );
                continue;
            }

            if (is_file($fullPath)) {
                if (!is_readable($fullPath)) {
                    throw new RuntimeException("Backup source file is not readable: {$relPath}");
                }

                clearstatcache(true, $fullPath);
                $stat = @stat($fullPath);
                if ($stat === false) {
                    throw new RuntimeException("Failed to stat backup source file: {$relPath}");
                }

                $size = (int) $stat['size'];
                $mtime = (int) $stat['mtime'];

                $files[] = [
                    'path' => $relPath,
                    'full_path' => $fullPath,
                    'source' => $sourceKey,
                    'size' => $size,
                    'mtime' => $mtime,
                ];

                $sourceFileCount++;
                $sourceBytes += $size;
                continue;
            }

            throw new RuntimeException("Unsupported or unreadable filesystem entry in backup source: {$relPath}");
        }
    }

    protected function shouldExcludeRelativePath(string $relativePath, string $entryName): bool
    {
        if ($entryName === '.gitignore') {
            return true;
        }

        $lowerRel = strtolower($relativePath);
        if ($lowerRel === 'imports/sales/temp' || str_starts_with($lowerRel, 'imports/sales/temp/')) {
            return true;
        }

        return false;
    }

    protected function isLinkOrJunction(string $path, ?string $realRootPath = null): bool
    {
        if (is_link($path)) {
            return true;
        }

        $lstat = @lstat($path);
        if (is_array($lstat) && (($lstat['mode'] & 0170000) === 0120000)) {
            return true;
        }

        if ($realRootPath !== null && file_exists($path)) {
            $realPath = @realpath($path);
            if ($realPath === false) {
                return true;
            }

            $normReal = $this->normalizePath($realPath);
            $normRoot = $this->normalizePath($realRootPath);
            $caseInsensitive = PHP_OS_FAMILY === 'Windows'
                || $this->looksLikeWindowsPath($normReal)
                || $this->looksLikeWindowsPath($normRoot);

            $cmpReal = $this->toComparablePath($normReal, $caseInsensitive);
            $cmpRoot = $this->toComparablePath($normRoot, $caseInsensitive);

            if ($cmpReal !== $cmpRoot && !$this->isPathPrefix($cmpReal, $cmpRoot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return resource
     */
    protected function acquireLock()
    {
        $lockPath = $this->workingDir . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Failed to open file backup lock file: {$lockPath}");
        }

        $wouldBlock = 0;
        if (!@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            @fclose($handle);
            throw new RuntimeException("Another file backup process is already running (lock busy: {$lockPath})");
        }

        return $handle;
    }

    /**
     * @param resource|null $lockHandle
     */
    protected function releaseLock($lockHandle): void
    {
        if (is_resource($lockHandle)) {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    protected function createTemporaryWorkspace(): string
    {
        $uniqueSuffix = str_replace('.', '_', uniqid('temp_files_', true)) . '_' . time();
        $tempDir = $this->workingDir . DIRECTORY_SEPARATOR . $uniqueSuffix;

        if (!@mkdir($tempDir, 0755, true)) {
            throw new RuntimeException("Failed to create temporary workspace: {$tempDir}");
        }

        return $tempDir;
    }

    protected function createArchive(array $inventory, string $tempDir, string $startedAt): array
    {
        $archiveFile = $tempDir . DIRECTORY_SEPARATOR . 'temp_files_backup.zip';
        $manifestFile = $tempDir . DIRECTORY_SEPARATOR . self::MANIFEST_FILENAME;

        $zip = new ZipArchive();
        if ($zip->open($archiveFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Failed to create ZIP archive: {$archiveFile}");
        }

        $manifestFiles = [];
        $entryNames = [];

        try {
            foreach ($inventory['files'] as $item) {
                $relPath = $item['path'];
                $fullPath = $item['full_path'];
                $expectedSize = $item['size'];
                $expectedMtime = $item['mtime'];

                $sha256 = $this->streamHashAndVerifyUnchanged($fullPath, $relPath, $expectedSize, $expectedMtime);

                if (!$zip->addFile($fullPath, $relPath)) {
                    throw new RuntimeException("Failed to add file to ZIP archive: {$relPath}");
                }

                $entryNames[] = $relPath;
                $manifestFiles[] = [
                    'path' => $relPath,
                    'size' => $expectedSize,
                    'mtime' => $expectedMtime,
                    'modified_at' => gmdate('Y-m-d\TH:i:s\Z', $expectedMtime),
                    'sha256' => $sha256,
                ];
            }

            $completedAt = gmdate('Y-m-d\TH:i:s\Z');
            $manifest = [
                'version' => 1,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'sources' => $inventory['sources'],
                'excluded' => [
                    'imports/sales/temp',
                    '.gitignore',
                ],
                'total_files' => $inventory['total_files'],
                'total_bytes' => $inventory['total_bytes'],
                'files' => $manifestFiles,
            ];

            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($manifestJson === false || @file_put_contents($manifestFile, $manifestJson) === false) {
                throw new RuntimeException("Failed to write backup manifest file");
            }

            if (!$zip->addFile($manifestFile, self::MANIFEST_FILENAME)) {
                throw new RuntimeException("Failed to add manifest.json to ZIP archive");
            }

            $entryNames[] = self::MANIFEST_FILENAME;

            if ($this->archivePassword !== '') {
                $this->applyArchiveEncryption($zip, $entryNames);
            }

            $this->onBeforeArchiveFinalize($zip, $inventory);
        } catch (RuntimeException $e) {
            @$zip->close();
            throw $e;
        }

        if (!@$zip->close()) {
            throw new RuntimeException("Failed to finalize ZIP archive");
        }

        $this->assertSourceInventoryUnchanged($inventory);

        clearstatcache(true, $archiveFile);
        $archiveSize = @filesize($archiveFile);
        if ($archiveSize === false || $archiveSize === 0) {
            throw new RuntimeException("Archive file is empty or does not exist");
        }

        return [
            'archive_file' => $archiveFile,
            'manifest' => $manifest,
        ];
    }

    protected function streamHashAndVerifyUnchanged(
        string $fullPath,
        string $relPath,
        int $expectedSize,
        int $expectedMtime
    ): string {
        clearstatcache(true, $fullPath);
        if (!file_exists($fullPath) || !is_file($fullPath) || is_link($fullPath) || !is_readable($fullPath)) {
            throw new RuntimeException("Source file disappeared or became unreadable during capture: {$relPath}");
        }

        $preStat = @stat($fullPath);
        if ($preStat === false) {
            throw new RuntimeException("Failed to stat source file during capture: {$relPath}");
        }

        if ((int) $preStat['size'] !== $expectedSize || (int) $preStat['mtime'] !== $expectedMtime) {
            throw new RuntimeException("Source file changed during backup capture: {$relPath}");
        }

        $stream = @fopen($fullPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Failed to open source file for reading: {$relPath}");
        }

        try {
            $streamResult = $this->streamAndHash($stream, "Failed while streaming source file: {$relPath}");
            $bytesRead = $streamResult['bytes'];
            $sha256 = $streamResult['sha256'];
        } finally {
            @fclose($stream);
        }

        clearstatcache(true, $fullPath);
        $postStat = @stat($fullPath);
        if ($postStat === false) {
            throw new RuntimeException("Source file disappeared during backup capture: {$relPath}");
        }

        if (
            $bytesRead !== $expectedSize ||
            (int) $postStat['size'] !== $expectedSize ||
            (int) $postStat['mtime'] !== $expectedMtime
        ) {
            throw new RuntimeException("Source file changed during backup capture: {$relPath}");
        }

        return $sha256;
    }

    /**
     * @param resource $stream
     * @return array{bytes: int, sha256: string}
     */
    protected function streamAndHash($stream, string $errorMessage): array
    {
        $hashCtx = hash_init('sha256');
        $bytesRead = 0;

        while (!feof($stream)) {
            $chunk = @fread($stream, 65536);
            if ($chunk === false) {
                throw new RuntimeException($errorMessage);
            }
            $chunkLen = strlen($chunk);
            if ($chunkLen > 0) {
                hash_update($hashCtx, $chunk);
                $bytesRead += $chunkLen;
            }
        }

        return [
            'bytes' => $bytesRead,
            'sha256' => hash_final($hashCtx),
        ];
    }

    protected function assertSourceInventoryUnchanged(array $inventory): void
    {
        foreach ($inventory['files'] as $item) {
            $fullPath = $item['full_path'];
            $relPath = $item['path'];

            clearstatcache(true, $fullPath);
            if (!file_exists($fullPath) || !is_file($fullPath) || is_link($fullPath)) {
                throw new RuntimeException("Source file disappeared during backup capture: {$relPath}");
            }

            $stat = @stat($fullPath);
            if ($stat === false) {
                throw new RuntimeException("Failed to verify source file after capture: {$relPath}");
            }

            if ((int) $stat['size'] !== (int) $item['size'] || (int) $stat['mtime'] !== (int) $item['mtime']) {
                throw new RuntimeException("Source file changed during backup capture: {$relPath}");
            }
        }
    }

    protected function applyArchiveEncryption(ZipArchive $zip, array $entryNames): void
    {
        foreach ($entryNames as $entryName) {
            if (!$zip->setEncryptionName($entryName, ZipArchive::EM_AES_256)) {
                throw new RuntimeException("Failed to enable AES-256 encryption for '{$entryName}' (unsupported by libzip)");
            }
        }

        if (!$zip->setPassword($this->archivePassword)) {
            throw new RuntimeException("Failed to set archive encryption password");
        }
    }

    protected function onBeforeArchiveFinalize(ZipArchive $zip, array $inventory): void
    {
        // Hook for test subclasses to simulate concurrent source modifications before ZipArchive::close().
    }

    protected function onAfterArchiveCreated(string $archiveFile, array $inventory): void
    {
        // Hook for test subclasses to simulate archive corruption or source changes prior to verification.
    }

    protected function verifyArchive(string $archiveFile, array $inventory): array
    {
        $this->assertSourceInventoryUnchanged($inventory);

        $zip = new ZipArchive();
        if ($zip->open($archiveFile) !== true) {
            throw new RuntimeException("Archive verification failed: unable to open completed ZIP archive");
        }

        try {
            if ($this->archivePassword !== '') {
                if (!$zip->setPassword($this->archivePassword)) {
                    throw new RuntimeException("Archive verification failed: unable to set password on ZIP archive");
                }

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if ($stat === false || ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_AES_256) {
                        $entryName = is_array($stat) ? ($stat['name'] ?? "#{$i}") : "#{$i}";
                        throw new RuntimeException("Archive verification failed: entry '{$entryName}' is not encrypted with AES-256");
                    }
                }
            }

            $manifestStat = $zip->statName(self::MANIFEST_FILENAME);
            if ($manifestStat === false) {
                throw new RuntimeException("Archive verification failed: missing manifest.json");
            }

            $manifestStream = @$zip->getStream(self::MANIFEST_FILENAME);
            if ($manifestStream === false) {
                throw new RuntimeException("Archive verification failed: unable to read manifest.json stream");
            }

            $manifestRaw = '';
            try {
                while (!feof($manifestStream)) {
                    $chunk = @fread($manifestStream, 65536);
                    if ($chunk === false) {
                        throw new RuntimeException("Archive verification failed: error reading manifest.json stream");
                    }
                    $manifestRaw .= $chunk;
                }
            } finally {
                @fclose($manifestStream);
            }

            if (strlen($manifestRaw) !== (int) $manifestStat['size']) {
                throw new RuntimeException("Archive verification failed: manifest.json size mismatch");
            }

            $manifest = json_decode($manifestRaw, true);
            if (!is_array($manifest)) {
                throw new RuntimeException("Archive verification failed: manifest.json contains invalid JSON");
            }

            if (
                empty($manifest['started_at']) ||
                empty($manifest['completed_at']) ||
                !isset($manifest['sources']) ||
                !is_array($manifest['sources']) ||
                !isset($manifest['total_files']) ||
                !isset($manifest['total_bytes']) ||
                !isset($manifest['files']) ||
                !is_array($manifest['files'])
            ) {
                throw new RuntimeException("Archive verification failed: manifest.json is missing required fields");
            }

            $expectedFiles = $manifest['files'];
            $expectedCount = (int) $manifest['total_files'];
            $expectedTotalBytes = (int) $manifest['total_bytes'];

            if ($expectedCount !== count($expectedFiles)) {
                throw new RuntimeException("Archive verification failed: manifest total_files does not match file list count");
            }

            if ($expectedCount !== count($inventory['files'])) {
                throw new RuntimeException("Archive verification failed: manifest file count does not match source inventory");
            }

            // Archive must contain exactly the payload files plus manifest.json
            if ($zip->numFiles !== ($expectedCount + 1)) {
                throw new RuntimeException("Archive verification failed: ZIP entry count ({$zip->numFiles}) does not match expected count (" . ($expectedCount + 1) . ")");
            }

            $verifiedTotalBytes = 0;

            foreach ($expectedFiles as $entry) {
                if (
                    !is_array($entry) ||
                    !isset($entry['path'], $entry['size'], $entry['mtime'], $entry['sha256'])
                ) {
                    throw new RuntimeException("Archive verification failed: malformed file entry in manifest.json");
                }

                $path = (string) $entry['path'];
                $expectedSize = (int) $entry['size'];
                $expectedSha256 = (string) $entry['sha256'];

                $entryStat = $zip->statName($path);
                if ($entryStat === false) {
                    throw new RuntimeException("Archive verification failed: missing payload entry '{$path}'");
                }

                if ((int) $entryStat['size'] !== $expectedSize) {
                    throw new RuntimeException("Archive verification failed: recorded uncompressed size mismatch for '{$path}'");
                }

                $stream = @$zip->getStream($path);
                if ($stream === false) {
                    throw new RuntimeException("Archive verification failed: unable to open stream for payload entry '{$path}'");
                }

                try {
                    $streamResult = $this->streamAndHash($stream, "Archive verification failed: stream read error for '{$path}'");
                    $bytesRead = $streamResult['bytes'];
                    $actualSha256 = $streamResult['sha256'];
                } finally {
                    @fclose($stream);
                }

                if ($bytesRead !== $expectedSize) {
                    throw new RuntimeException("Archive verification failed: extracted byte count ({$bytesRead}) does not match manifest size ({$expectedSize}) for '{$path}'");
                }

                if (!hash_equals($expectedSha256, $actualSha256)) {
                    throw new RuntimeException("Archive verification failed: SHA-256 checksum mismatch for '{$path}'");
                }

                $verifiedTotalBytes += $bytesRead;
            }

            if ($verifiedTotalBytes !== $expectedTotalBytes) {
                throw new RuntimeException("Archive verification failed: total verified bytes ({$verifiedTotalBytes}) does not match manifest total_bytes ({$expectedTotalBytes})");
            }

            return $manifest;
        } finally {
            @$zip->close();
        }
    }

    protected function selectFinalSlot(): string
    {
        $slotAPath = $this->destinationDir . DIRECTORY_SEPARATOR . $this->slotA;
        $slotBPath = $this->destinationDir . DIRECTORY_SEPARATOR . $this->slotB;

        clearstatcache(true, $slotAPath);
        clearstatcache(true, $slotBPath);

        $slotAExists = file_exists($slotAPath);
        $slotBExists = file_exists($slotBPath);

        if (!$slotAExists) {
            return $slotAPath;
        }

        if (!$slotBExists) {
            return $slotBPath;
        }

        $slotATime = filemtime($slotAPath);
        $slotBTime = filemtime($slotBPath);

        return $slotATime <= $slotBTime ? $slotAPath : $slotBPath;
    }

    protected function promoteArchive(string $archiveFile, string $finalSlot): void
    {
        if (!@rename($archiveFile, $finalSlot)) {
            throw new RuntimeException("Failed to promote archive to final slot: {$finalSlot}");
        }
    }

    protected function cleanupTemporaryWorkspace(string $tempDir): void
    {
        if (!is_dir($tempDir) || is_link($tempDir)) {
            return;
        }

        $entries = @scandir($tempDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $tempDir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($entryPath) && !is_link($entryPath)) {
                $this->cleanupTemporaryWorkspace($entryPath);
            } else {
                @unlink($entryPath);
            }
        }

        @rmdir($tempDir);
    }

    protected function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        // Handle Windows UNC paths: \\server\share\...
        if (preg_match('/^[\\\\\/]{2}([^\\\\\/]+)[\\\\\/]([^\\\\\/]+)(.*)$/', $trimmed, $uncMatch)) {
            $prefix = '\\\\' . $uncMatch[1] . '\\' . $uncMatch[2];
            $rest = str_replace(['/', '\\'], '\\', $uncMatch[3]);
            $parts = explode('\\', $rest);
            $stack = [];
            foreach ($parts as $part) {
                if ($part === '' || $part === '.') {
                    continue;
                }
                if ($part === '..') {
                    array_pop($stack);
                } else {
                    $stack[] = $part;
                }
            }
            return $stack === [] ? $prefix : $prefix . '\\' . implode('\\', $stack);
        }

        // Handle Windows drive letter paths: C:\... or C:/...
        if (preg_match('/^([a-zA-Z]:)(.*)$/', $trimmed, $driveMatch)) {
            $drive = $driveMatch[1];
            $rest = str_replace(['/', '\\'], '\\', $driveMatch[2]);
            $parts = explode('\\', $rest);
            $stack = [];
            foreach ($parts as $part) {
                if ($part === '' || $part === '.') {
                    continue;
                }
                if ($part === '..') {
                    array_pop($stack);
                } else {
                    $stack[] = $part;
                }
            }
            return $stack === [] ? $drive . '\\' : $drive . '\\' . implode('\\', $stack);
        }

        // Standard POSIX or relative path
        $unify = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
        $isAbsolute = str_starts_with($unify, DIRECTORY_SEPARATOR);
        $parts = explode(DIRECTORY_SEPARATOR, $unify);
        $stack = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
            } else {
                $stack[] = $part;
            }
        }

        $joined = implode(DIRECTORY_SEPARATOR, $stack);
        return $isAbsolute ? DIRECTORY_SEPARATOR . $joined : $joined;
    }

    protected function resolveNormalizedPath(string $path, int $depth = 0): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        if (PHP_OS_FAMILY !== 'Windows' && $this->looksLikeWindowsPath($trimmed)) {
            return $this->normalizePath($trimmed);
        }

        if ($depth > 40) {
            return $this->normalizePath($trimmed);
        }

        // Resolve filesystem aliases/symlinks segment-by-segment before collapsing '..'
        $sep = $this->looksLikeWindowsPath($trimmed) ? '\\' : DIRECTORY_SEPARATOR;
        $unify = str_replace(['/', '\\'], $sep, $trimmed);

        if (preg_match('/^[\\\\\/]{2}([^\\\\\/]+)[\\\\\/]([^\\\\\/]+)(.*)$/', $trimmed, $uncMatch)) {
            $current = '\\\\' . $uncMatch[1] . '\\' . $uncMatch[2];
            $rest = str_replace(['/', '\\'], $sep, $uncMatch[3]);
        } elseif (preg_match('/^([a-zA-Z]:)(.*)$/', $trimmed, $driveMatch)) {
            $current = $driveMatch[1] . '\\';
            $rest = str_replace(['/', '\\'], $sep, $driveMatch[2]);
        } elseif (str_starts_with($unify, $sep)) {
            $current = $sep;
            $rest = substr($unify, strlen($sep));
        } else {
            $cwd = getcwd() ?: '';
            $current = $cwd !== '' ? (@realpath($cwd) ?: $cwd) : '';
            $rest = $unify;
        }

        $parts = explode($sep, $rest);

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($current !== '' && @file_exists($current)) {
                    $realCurrent = @realpath($current);
                    if ($realCurrent !== false) {
                        $current = $realCurrent;
                    }
                }

                $candidateParent = ($current === '' || $current === $sep || str_ends_with($current, $sep))
                    ? ($current . '..')
                    : ($current . $sep . '..');
                $current = $this->normalizePath($candidateParent);

                if ($current !== '' && @file_exists($current)) {
                    $realParent = @realpath($current);
                    if ($realParent !== false) {
                        $current = $realParent;
                    }
                }
                continue;
            }

            $candidate = ($current === '' || $current === $sep || str_ends_with($current, $sep))
                ? ($current . $part)
                : ($current . $sep . $part);

            if (@file_exists($candidate)) {
                $realCandidate = @realpath($candidate);
                if ($realCandidate !== false) {
                    $current = $realCandidate;
                    continue;
                }
            }

            if (@is_link($candidate)) {
                $linkTarget = @readlink($candidate);
                if ($linkTarget !== false) {
                    $parentDir = dirname($candidate);
                    $combinedTarget = ($this->looksLikeWindowsPath($linkTarget) || str_starts_with($linkTarget, '/') || str_starts_with($linkTarget, '\\'))
                        ? $linkTarget
                        : ($parentDir . $sep . $linkTarget);
                    $current = $this->resolveNormalizedPath($combinedTarget, $depth + 1);
                    continue;
                }
            }

            $current = $candidate;
        }

        return $this->normalizePath($current);
    }

    protected function toComparablePath(string $normalizedPath, bool $caseInsensitive): string
    {
        $canonical = str_replace('\\', '/', $normalizedPath);
        if ($canonical !== '/' && !preg_match('/^[a-zA-Z]:\/$/', $canonical)) {
            $canonical = rtrim($canonical, '/');
        }

        return $caseInsensitive ? strtolower($canonical) : $canonical;
    }

    protected function isPathPrefix(string $childComparable, string $parentComparable): bool
    {
        $prefix = rtrim($parentComparable, '/') . '/';
        return str_starts_with($childComparable, $prefix);
    }

    protected function pathsAreEqual(string $pathA, string $pathB): bool
    {
        $normA = $this->resolveNormalizedPath($pathA);
        $normB = $this->resolveNormalizedPath($pathB);
        $caseInsensitive = PHP_OS_FAMILY === 'Windows'
            || $this->looksLikeWindowsPath($normA)
            || $this->looksLikeWindowsPath($normB);

        return $this->toComparablePath($normA, $caseInsensitive) === $this->toComparablePath($normB, $caseInsensitive);
    }

    protected function looksLikeWindowsPath(string $path): bool
    {
        return (bool) (
            preg_match('/^[a-zA-Z]:([\\\\\/]|$)/', $path) ||
            preg_match('/^[\\\\\/]{2}[^\\\\\/]+[\\\\\/][^\\\\\/]+/', $path)
        );
    }

    protected function extractWindowsVolumeRoot(string $normalizedPath): ?string
    {
        if (preg_match('/^([a-zA-Z]:)/', $normalizedPath, $m)) {
            return strtolower($m[1]);
        }

        if (preg_match('/^[\\\\\/]{2}([^\\\\\/]+)[\\\\\/]([^\\\\\/]+)/', $normalizedPath, $m)) {
            return '\\\\' . strtolower($m[1]) . '\\' . strtolower($m[2]);
        }

        return null;
    }

    protected function getExistingAncestorDevice(string $normalizedPath): ?int
    {
        $current = $normalizedPath;
        while ($current !== '' && $current !== DIRECTORY_SEPARATOR) {
            if (@file_exists($current)) {
                $stat = @stat($current);
                if (is_array($stat) && isset($stat['dev'])) {
                    return (int) $stat['dev'];
                }
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        if (@file_exists(DIRECTORY_SEPARATOR)) {
            $stat = @stat(DIRECTORY_SEPARATOR);
            if (is_array($stat) && isset($stat['dev'])) {
                return (int) $stat['dev'];
            }
        }

        return null;
    }
}


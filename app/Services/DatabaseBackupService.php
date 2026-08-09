<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DatabaseBackupService
{
    private string $mysqldumpPath;
    private string $destinationDir;
    private string $slotA;
    private string $slotB;
    private string $archivePassword;
    private bool $testMode = false;
    private ProcessRunnerInterface $processRunner;

    public function __construct(
        ?array $config = null,
        bool $testMode = false,
        ?ProcessRunnerInterface $processRunner = null
    ) {
        $config = $config ?? config('database-backup');
        $this->mysqldumpPath = $config['mysqldump_path'];
        $this->destinationDir = $config['destination_dir'];
        $this->slotA = $config['slot_a'];
        $this->slotB = $config['slot_b'];
        $this->archivePassword = $config['archive_password'];
        $this->testMode = $testMode;
        $this->processRunner = $processRunner ?? new SymfonyProcessRunner();
    }

    public function backup(): array
    {
        try {
            $this->validateConfiguration();
            $tempDir = $this->createTemporaryWorkspace();
            $dumpFile = $tempDir . DIRECTORY_SEPARATOR . 'dump.sql';

            try {
                $this->createDump($dumpFile);
                $this->validateDump($dumpFile);
                $archiveFile = $this->createArchive($dumpFile, $tempDir);
                $finalSlot = $this->selectFinalSlot();
                $this->promoteArchive($archiveFile, $finalSlot);

                $size = filesize($finalSlot);
                Log::info("Database backup successful: {$finalSlot} ({$size} bytes)");

                return [
                    'success' => true,
                    'message' => "Backup successful: {$finalSlot} ({$size} bytes)",
                    'slot' => $finalSlot,
                    'size' => $size,
                ];
            } finally {
                $this->cleanupTemporaryWorkspace($tempDir);
            }
        } catch (RuntimeException $e) {
            Log::error("Database backup failed: {$e->getMessage()}");
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function validateConfiguration(): void
    {
        if (!$this->isExecutableAvailable($this->mysqldumpPath)) {
            throw new RuntimeException("mysqldump executable not found or not executable: {$this->mysqldumpPath}");
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

    private function isExecutableAvailable(string $path): bool
    {
        // For absolute paths, validate directly
        if (file_exists($path) && is_executable($path)) {
            return true;
        }

        // If path contains directory separators, it's not a PATH lookup candidate
        if (strpos($path, DIRECTORY_SEPARATOR) !== false || strpos($path, '/') !== false) {
            return false;
        }

        // Only for bare command names, try PATH lookup using Process
        try {
            $process = new Process(['which', $path], null, null);
            if (PHP_OS_FAMILY === 'Windows') {
                $process = new Process(['where.exe', $path], null, null);
            }
            $process->run();
            return $process->isSuccessful();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function createTemporaryWorkspace(): string
    {
        $tempDir = $this->destinationDir . DIRECTORY_SEPARATOR . 'temp_' . uniqid() . '_' . time();
        if (!@mkdir($tempDir, 0755, true)) {
            throw new RuntimeException("Failed to create temporary workspace: {$tempDir}");
        }
        return $tempDir;
    }

    private function createDump(string $dumpFile): void
    {
        if ($this->testMode) {
            $this->createTestDump($dumpFile);
            return;
        }

        $config = config('database.connections.mysql');
        if (empty($config)) {
            throw new RuntimeException("MySQL connection not configured");
        }

        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $username = $config['username'] ?? 'root';
        $database = $config['database'] ?? '';
        $password = $config['password'] ?? '';

        $command = [
            $this->mysqldumpPath,
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $username,
            '--single-transaction',
            '--routines',
            '--events',
            '--triggers',
            $database,
        ];

        $env = getenv();
        $env['MYSQL_PWD'] = $password ?: '';

        $handle = fopen($dumpFile, 'w');
        if (!$handle) {
            throw new RuntimeException("Failed to open dump file for writing: {$dumpFile}");
        }

        try {
            $result = $this->processRunner->run($command, $handle, $env);

            if (!$result['success']) {
                throw new RuntimeException("mysqldump failed: " . trim($result['stderr']));
            }
        } finally {
            fclose($handle);
        }

        if (!file_exists($dumpFile)) {
            throw new RuntimeException("Dump file was not created");
        }
    }

    private function createTestDump(string $dumpFile): void
    {
        $database = config('database.connections.mysql.database', 'test');

        $content = "-- Test MySQL Dump\n";
        $content .= "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;\n";
        $content .= "SET @saved_cs_client = @@character_set_client;\n";
        $content .= "USE `" . $database . "`;\n";
        $content .= "CREATE TABLE test_table (id INT PRIMARY KEY, name VARCHAR(255));\n";
        $content .= "INSERT INTO test_table VALUES (1, 'test');\n";
        $content .= "DELIMITER ;;\n";
        $content .= "CREATE PROCEDURE test_proc() READS SQL DATA SELECT 1;;\n";
        $content .= "DELIMITER ;\n";
        $content .= "CREATE EVENT test_event ON SCHEDULE EVERY 1 DAY DO SELECT 1;\n";
        $content .= "CREATE TRIGGER test_trigger BEFORE INSERT ON test_table FOR EACH ROW SELECT NEW.id;\n";

        file_put_contents($dumpFile, $content);
    }

    private function validateDump(string $dumpFile): void
    {
        $size = filesize($dumpFile);
        if ($size === false || $size === 0) {
            throw new RuntimeException("Dump file is empty or does not exist");
        }
    }

    private function createArchive(string $dumpFile, string $tempDir): string
    {
        $archiveFile = $tempDir . DIRECTORY_SEPARATOR . 'temp_backup.zip';

        $zip = new \ZipArchive();
        if ($zip->open($archiveFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Failed to create ZIP archive");
        }

        if (!$zip->addFile($dumpFile, 'dump.sql')) {
            $zip->close();
            throw new RuntimeException("Failed to add dump file to archive");
        }

        if (!empty($this->archivePassword)) {
            try {
                $this->applyArchiveEncryption($zip);
            } catch (RuntimeException $e) {
                $zip->close();
                throw $e;
            }
        }

        if (!$zip->close()) {
            throw new RuntimeException("Failed to finalize ZIP archive");
        }

        $archiveSize = filesize($archiveFile);
        if ($archiveSize === false || $archiveSize === 0) {
            throw new RuntimeException("Archive file is empty or does not exist");
        }

        return $archiveFile;
    }

    protected function applyArchiveEncryption(\ZipArchive $zip): void
    {
        // Set encryption method before setting password
        if (!$zip->setEncryptionName('dump.sql', \ZipArchive::EM_AES_256)) {
            throw new RuntimeException("Failed to enable AES-256 encryption (unsupported by libzip)");
        }
        // Set password for the encrypted entry
        if (!$zip->setPassword($this->archivePassword)) {
            throw new RuntimeException("Failed to set archive encryption password");
        }
    }

    private function selectFinalSlot(): string
    {
        $slotAPath = $this->destinationDir . DIRECTORY_SEPARATOR . $this->slotA;
        $slotBPath = $this->destinationDir . DIRECTORY_SEPARATOR . $this->slotB;

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

    private function promoteArchive(string $archiveFile, string $finalSlot): void
    {
        if (!@rename($archiveFile, $finalSlot)) {
            throw new RuntimeException("Failed to promote archive to final slot: {$finalSlot}");
        }
    }

    private function cleanupTemporaryWorkspace(string $tempDir): void
    {
        if (!is_dir($tempDir)) {
            return;
        }

        $files = @scandir($tempDir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $tempDir . DIRECTORY_SEPARATOR . $file;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        @rmdir($tempDir);
    }
}

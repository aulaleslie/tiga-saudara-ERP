<?php

namespace Tests\Unit;

use App\Services\DatabaseBackupService;
use App\Services\ProcessRunnerInterface;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Tests that verify the actual mysqldump process construction and execution.
 * These tests use a mock process runner to inspect the command without executing mysqldump.
 */
class DatabaseBackupServiceProcessTest extends TestCase
{
    private string $testWorkingDir;
    private string $testBackupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testWorkingDir = storage_path('test_backups_working_unit');
        $this->testBackupDir = storage_path('test_backups_unit');
        File::ensureDirectoryExists($this->testWorkingDir);
        File::ensureDirectoryExists($this->testBackupDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testWorkingDir);
        File::deleteDirectory($this->testBackupDir);
        parent::tearDown();
    }

    public function test_mysqldump_command_includes_required_flags()
    {
        $mockRunner = new MockProcessRunner(success: true);

        $config = [
            'mysqldump_path' => '/usr/bin/mysqldump',
            'working_dir' => $this->testWorkingDir,
            'destination_dir' => $this->testBackupDir,
            'slot_a' => 'backup-a.zip',
            'slot_b' => 'backup-b.zip',
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: false, processRunner: $mockRunner);

        // This will fail at archive creation (mock doesn't write SQL), but we can inspect the command
        try {
            $service->backup();
        } catch (\RuntimeException $e) {
            // Expected to fail, but command was constructed
        }

        $command = $mockRunner->getLastCommand();
        $this->assertNotNull($command, 'Command should have been constructed');
        $this->assertContains('--single-transaction', $command);
        $this->assertContains('--routines', $command);
        $this->assertContains('--events', $command);
        $this->assertContains('--triggers', $command);
    }

    public function test_mysqldump_uses_configured_connection_values()
    {
        $mockRunner = new MockProcessRunner(success: true);

        $config = [
            'mysqldump_path' => '/usr/bin/mysqldump',
            'working_dir' => $this->testWorkingDir,
            'destination_dir' => $this->testBackupDir,
            'slot_a' => 'backup-a.zip',
            'slot_b' => 'backup-b.zip',
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: false, processRunner: $mockRunner);

        try {
            $service->backup();
        } catch (\RuntimeException $e) {
            // Expected to fail
        }

        $command = $mockRunner->getLastCommand();
        $dbConfig = config('database.connections.mysql');
        $commandStr = implode(' ', $command);

        $this->assertStringContainsString('--host=' . $dbConfig['host'], $commandStr);
        $this->assertStringContainsString('--port=' . $dbConfig['port'], $commandStr);
        $this->assertStringContainsString('--user=' . $dbConfig['username'], $commandStr);
        $this->assertStringContainsString($dbConfig['database'], $commandStr);
    }

    public function test_password_not_exposed_in_command_arguments()
    {
        $mockRunner = new MockProcessRunner(success: true);

        $config = [
            'mysqldump_path' => '/usr/bin/mysqldump',
            'working_dir' => $this->testWorkingDir,
            'destination_dir' => $this->testBackupDir,
            'slot_a' => 'backup-a.zip',
            'slot_b' => 'backup-b.zip',
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: false, processRunner: $mockRunner);

        try {
            $service->backup();
        } catch (\RuntimeException $e) {
            // Expected to fail at archive creation
        }

        $command = $mockRunner->getLastCommand();
        $password = config('database.connections.mysql.password');

        // Password should NOT appear in command arguments
        $commandStr = implode(' ', $command);
        if (!empty($password)) {
            $this->assertStringNotContainsString($password, $commandStr,
                'Database password must not appear in command arguments');
        }

        // Password SHOULD be in the environment (even if empty)
        $env = $mockRunner->getLastEnv();
        $this->assertIsArray($env, 'Environment should have been passed');
        $this->assertArrayHasKey('MYSQL_PWD', $env, 'MYSQL_PWD should be set in environment');
        $this->assertEquals($password, $env['MYSQL_PWD'],
            'MYSQL_PWD in environment should equal the configured database password');
    }

    public function test_failed_dump_process_preserves_existing_backups()
    {
        // First, create existing backups
        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . 'backup-a.zip';
        $slotBPath = $this->testBackupDir . DIRECTORY_SEPARATOR . 'backup-b.zip';

        file_put_contents($slotAPath, 'existing backup a');
        file_put_contents($slotBPath, 'existing backup b');

        $aOriginalContent = file_get_contents($slotAPath);
        $bOriginalContent = file_get_contents($slotBPath);

        // Now use a mock runner that simulates process failure
        $mockRunner = new MockProcessRunner(success: false, stderr: 'Connection refused');

        $config = [
            'mysqldump_path' => '/usr/bin/mysqldump',
            'working_dir' => $this->testWorkingDir,
            'destination_dir' => $this->testBackupDir,
            'slot_a' => 'backup-a.zip',
            'slot_b' => 'backup-b.zip',
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: false, processRunner: $mockRunner);
        $result = $service->backup();

        // Backup should fail
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('mysqldump failed', $result['message']);

        // Existing backups should be untouched
        $this->assertEquals($aOriginalContent, file_get_contents($slotAPath));
        $this->assertEquals($bOriginalContent, file_get_contents($slotBPath));
    }

    public function test_stderr_captured_separately_from_sql_output()
    {
        // Create a mock runner that writes to both stdout and stderr
        $mockRunner = new class implements ProcessRunnerInterface {
            public function run(array $command, string $outputPath, array $env): array
            {
                // Simulate a successful dump with warnings in stderr
                file_put_contents($outputPath, "-- Dump content\nCREATE TABLE test (id INT);");
                return [
                    'success' => true,
                    'stderr' => 'Warning: some non-critical warning from mysqldump',
                ];
            }

            public function getLastCommand(): ?array
            {
                return null;
            }
        };

        $config = [
            'mysqldump_path' => '/usr/bin/mysqldump',
            'working_dir' => $this->testWorkingDir,
            'destination_dir' => $this->testBackupDir,
            'slot_a' => 'backup-a.zip',
            'slot_b' => 'backup-b.zip',
            'archive_password' => '',
        ];

        $service = new DatabaseBackupService($config, testMode: false, processRunner: $mockRunner);
        $result = $service->backup();

        // Backup should succeed
        $this->assertTrue($result['success']);

        // SQL file should contain only stdout, not stderr
        $slotAPath = $this->testBackupDir . DIRECTORY_SEPARATOR . 'backup-a.zip';
        $this->assertFileExists($slotAPath);

        $zip = new \ZipArchive();
        $zip->open($slotAPath);
        $sql = $zip->getFromName('dump.sql');
        $zip->close();

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('Warning', $sql);
    }
}

/**
 * Mock process runner for testing command construction.
 */
class MockProcessRunner implements ProcessRunnerInterface
{
    private ?array $lastCommand = null;
    private ?array $lastEnv = null;
    private bool $success;
    private string $stderr;

    public function __construct(bool $success = true, string $stderr = '')
    {
        $this->success = $success;
        $this->stderr = $stderr;
    }

    public function run(array $command, string $outputPath, array $env): array
    {
        $this->lastCommand = $command;
        $this->lastEnv = $env;

        if ($this->success) {
            // Write minimal valid SQL to the output file
            $sql = "-- Test SQL Dump\n";
            $sql .= "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;\n";
            $sql .= "USE `test`;\n";
            $sql .= "CREATE TABLE test_table (id INT PRIMARY KEY);\n";
            $sql .= "INSERT INTO test_table VALUES (1);\n";
            file_put_contents($outputPath, $sql);
        }

        return [
            'success' => $this->success,
            'stderr' => $this->stderr,
        ];
    }

    public function getLastCommand(): ?array
    {
        return $this->lastCommand;
    }

    public function getLastEnv(): ?array
    {
        return $this->lastEnv;
    }
}

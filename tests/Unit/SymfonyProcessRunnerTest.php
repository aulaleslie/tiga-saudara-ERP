<?php

namespace Tests\Unit;

use App\Services\SymfonyProcessRunner;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Tests for SymfonyProcessRunner proc_open redirect and memory efficiency.
 */
class SymfonyProcessRunnerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = storage_path('test_process_runner');
        File::ensureDirectoryExists($this->testDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testDir);
        parent::tearDown();
    }

    public function test_redirects_stdout_to_file_without_buffering()
    {
        $runner = new SymfonyProcessRunner();
        $outputFile = $this->testDir . DIRECTORY_SEPARATOR . 'output.txt';

        // Use a simple command that produces output
        $command = ['php', '-r', 'echo "test output\n";'];
        $result = $runner->run($command, $outputFile, getenv());

        // Process should succeed
        $this->assertTrue($result['success']);

        // Output should have been written to file via proc_open redirect, not buffered
        $this->assertFileExists($outputFile);
        $content = file_get_contents($outputFile);
        $this->assertStringContainsString('test output', $content);

        // stderr should be empty for successful command
        $this->assertEquals('', $result['stderr']);
    }

    public function test_stderr_separated_from_stdout()
    {
        $runner = new SymfonyProcessRunner();
        $outputFile = $this->testDir . DIRECTORY_SEPARATOR . 'output.txt';

        // Use a command that writes to both stdout and stderr
        $command = ['php', '-r', 'echo "stdout content\n"; fwrite(STDERR, "stderr content\n");'];

        $result = $runner->run($command, $outputFile, getenv());

        // Process should succeed (exit code 0)
        $this->assertTrue($result['success']);

        // stdout should be in file
        $fileContent = file_get_contents($outputFile);
        $this->assertStringContainsString('stdout content', $fileContent);

        // stderr should NOT be in file (should be separated)
        $this->assertStringNotContainsString('stderr content', $fileContent);

        // stderr should be captured separately
        $this->assertStringContainsString('stderr content', $result['stderr']);
    }

    public function test_large_stdout_redirected_to_file_without_buffering()
    {
        $runner = new SymfonyProcessRunner();
        $outputFile = $this->testDir . DIRECTORY_SEPARATOR . 'large_output.txt';

        // Generate 10 MB of output via PHP (simulates large mysqldump output)
        // This would exhaust memory if buffered by Symfony/PHP
        // With proc_open file redirect, it bypasses PHP memory entirely
        $sizeInMB = 10;
        $command = [
            'php',
            '-r',
            'for ($i = 0; $i < ' . ($sizeInMB * 100) . '; $i++) { echo str_repeat("x", 100000); }',
        ];

        $result = $runner->run($command, $outputFile, getenv());

        // Process should succeed without memory exhaustion
        $this->assertTrue($result['success']);

        // File should contain the data (redirected directly via proc_open, not buffered in PHP)
        $this->assertFileExists($outputFile);
        $fileSize = filesize($outputFile);
        // Generated size is exactly $sizeInMB * 100 * 100000 bytes
        $expectedSize = $sizeInMB * 100 * 100000;
        $this->assertEquals($expectedSize, $fileSize,
            'Large output should be redirected to file via proc_open without buffering');
    }

    public function test_failed_process_captures_stderr_without_buffering_stdout()
    {
        $runner = new SymfonyProcessRunner();
        $outputFile = $this->testDir . DIRECTORY_SEPARATOR . 'output.txt';

        // Use a command that fails and produces stderr
        $command = ['php', '-r', 'fwrite(STDERR, "error message\n"); exit(1);'];
        $result = $runner->run($command, $outputFile, getenv());

        // Process should have failed
        $this->assertFalse($result['success']);

        // stderr should be captured
        $this->assertStringContainsString('error message', $result['stderr']);

        // stdout file should exist but be empty
        $fileContent = file_get_contents($outputFile);
        $this->assertEquals('', $fileContent);
    }

    public function test_environment_variables_passed_through()
    {
        $runner = new SymfonyProcessRunner();
        $outputFile = $this->testDir . DIRECTORY_SEPARATOR . 'output.txt';

        $env = getenv();
        $env['TEST_VAR'] = 'test_value_12345';

        $command = ['php', '-r', 'echo getenv("TEST_VAR");'];
        $result = $runner->run($command, $outputFile, $env);

        $this->assertTrue($result['success']);

        $fileContent = file_get_contents($outputFile);
        $this->assertStringContainsString('test_value_12345', $fileContent);
    }
}

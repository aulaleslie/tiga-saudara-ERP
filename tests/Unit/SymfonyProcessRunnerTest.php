<?php

namespace Tests\Unit;

use App\Services\SymfonyProcessRunner;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Tests for SymfonyProcessRunner output streaming and memory efficiency.
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

    public function test_disables_symfony_output_buffering()
    {
        $runner = new SymfonyProcessRunner();
        $outputFile = $this->testDir . DIRECTORY_SEPARATOR . 'output.txt';
        $handle = fopen($outputFile, 'w');

        // Use a simple command that produces output
        $command = ['php', '-r', 'echo "test output\n";'];
        $result = $runner->run($command, $handle, getenv());

        fclose($handle);

        // Process should succeed
        $this->assertTrue($result['success']);

        // Output should have been written to file, not buffered by Symfony
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
        $handle = fopen($outputFile, 'w');

        // Use a command that writes to both stdout and stderr
        // On Windows: write stdout then redirect stderr to stdout
        // On Unix: write to stderr separately
        $isWindows = PHP_OS_FAMILY === 'Windows';
        if ($isWindows) {
            $command = ['php', '-r', 'echo "stdout content\n"; fwrite(STDERR, "stderr content\n");'];
        } else {
            $command = ['sh', '-c', 'echo "stdout content"; echo "stderr content" >&2'];
        }

        $result = $runner->run($command, $handle, getenv());

        fclose($handle);

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

    public function test_large_stdout_stream_not_buffered()
    {
        $runner = new SymfonyProcessRunner();
        $outputFile = $this->testDir . DIRECTORY_SEPARATOR . 'large_output.txt';
        $handle = fopen($outputFile, 'w');

        // Generate 10 MB of output via PHP (simulates large mysqldump output)
        // This would exhaust memory if Symfony buffered it
        $sizeInMB = 10;
        $command = [
            'php',
            '-r',
            'for ($i = 0; $i < ' . ($sizeInMB * 100) . '; $i++) { echo str_repeat("x", 100000); }',
        ];

        $result = $runner->run($command, $handle, getenv());

        fclose($handle);

        // Process should succeed without memory exhaustion
        $this->assertTrue($result['success']);

        // File should contain the data (streamed, not buffered)
        $this->assertFileExists($outputFile);
        $fileSize = filesize($outputFile);
        // Generated size is exactly $sizeInMB * 100 * 100000 bytes
        $expectedSize = $sizeInMB * 100 * 100000;
        $this->assertEquals($expectedSize, $fileSize,
            'Large output should be streamed to file without buffering');
    }

    public function test_failed_process_captures_stderr_without_buffering_stdout()
    {
        $runner = new SymfonyProcessRunner();
        $outputFile = $this->testDir . DIRECTORY_SEPARATOR . 'output.txt';
        $handle = fopen($outputFile, 'w');

        // Use a command that fails and produces stderr
        $command = ['php', '-r', 'fwrite(STDERR, "error message\n"); exit(1);'];
        $result = $runner->run($command, $handle, getenv());

        fclose($handle);

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
        $handle = fopen($outputFile, 'w');

        $env = getenv();
        $env['TEST_VAR'] = 'test_value_12345';

        $command = ['php', '-r', 'echo getenv("TEST_VAR");'];
        $result = $runner->run($command, $handle, $env);

        fclose($handle);

        $this->assertTrue($result['success']);

        $fileContent = file_get_contents($outputFile);
        $this->assertStringContainsString('test_value_12345', $fileContent);
    }
}

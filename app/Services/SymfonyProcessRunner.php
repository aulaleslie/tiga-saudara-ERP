<?php

namespace App\Services;

class SymfonyProcessRunner implements ProcessRunnerInterface
{
    private ?array $lastCommand = null;

    public function run(array $command, string $outputPath, array $env): array
    {
        $this->lastCommand = $command;

        $errorFile = tempnam(sys_get_temp_dir(), 'mysqldump_err_');
        if ($errorFile === false) {
            return [
                'success' => false,
                'stderr' => 'Failed to create temporary error file',
            ];
        }

        try {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['file', $outputPath, 'w'],
                2 => ['file', $errorFile, 'w'],
            ];

            $process = proc_open($command, $descriptorSpec, $pipes, null, $env);

            if ($process === false) {
                return [
                    'success' => false,
                    'stderr' => 'Failed to open process',
                ];
            }

            // Close stdin immediately (mysqldump doesn't read from it)
            if (is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            // Wait for process to complete (3600 second timeout = 1 hour)
            $startTime = time();
            $timeout = 3600;
            $status = [];

            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (time() - $startTime > $timeout) {
                    proc_terminate($process, 15);
                    sleep(1);
                    proc_terminate($process, 9);
                    proc_close($process);
                    return [
                        'success' => false,
                        'stderr' => 'Process timeout after ' . $timeout . ' seconds',
                    ];
                }
                usleep(100000); // 100ms polling interval
            }

            $exitCode = $status['exitcode'];
            proc_close($process);

            // Read stderr from temporary file
            $stderr = '';
            if (file_exists($errorFile)) {
                $stderr = file_get_contents($errorFile);
                if ($stderr === false) {
                    $stderr = 'Failed to read error output';
                }
            }

            return [
                'success' => $exitCode === 0,
                'stderr' => $stderr ?: '',
            ];
        } finally {
            // Clean up error file
            if (file_exists($errorFile)) {
                @unlink($errorFile);
            }
        }
    }

    public function getLastCommand(): ?array
    {
        return $this->lastCommand;
    }
}

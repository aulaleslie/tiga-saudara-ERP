<?php

namespace App\Services;

interface ProcessRunnerInterface
{
    /**
     * Run a mysqldump process and write output to the given file handle.
     *
     * @param array $command Command arguments (first element is executable path)
     * @param resource $fileHandle Open file handle for stdout
     * @param array $env Environment variables including MYSQL_PWD
     * @return array ['success' => bool, 'stderr' => string]
     */
    public function run(array $command, $fileHandle, array $env): array;

    /**
     * Get the last constructed command for testing/inspection.
     */
    public function getLastCommand(): ?array;
}

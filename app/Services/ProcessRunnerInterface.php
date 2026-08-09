<?php

namespace App\Services;

interface ProcessRunnerInterface
{
    /**
     * Run a mysqldump process and redirect stdout directly to a file.
     *
     * @param array $command Command arguments (first element is executable path)
     * @param string $outputPath Path where stdout will be redirected (file will be created/overwritten)
     * @param array $env Environment variables including MYSQL_PWD
     * @return array ['success' => bool, 'stderr' => string]
     */
    public function run(array $command, string $outputPath, array $env): array;

    /**
     * Get the last constructed command for testing/inspection.
     */
    public function getLastCommand(): ?array;
}

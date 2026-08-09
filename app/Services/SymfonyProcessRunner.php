<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class SymfonyProcessRunner implements ProcessRunnerInterface
{
    private ?array $lastCommand = null;

    public function run(array $command, $fileHandle, array $env): array
    {
        $this->lastCommand = $command;

        $process = new Process($command);
        $process->setTimeout(3600);
        $process->setEnv($env);
        $process->disableOutput();

        $errorOutput = '';

        $process->run(function ($type, $buffer) use ($fileHandle, &$errorOutput) {
            if ($type === Process::OUT) {
                fwrite($fileHandle, $buffer);
            } else {
                $errorOutput .= $buffer;
            }
        });

        return [
            'success' => $process->isSuccessful(),
            'stderr' => $errorOutput,
        ];
    }

    public function getLastCommand(): ?array
    {
        return $this->lastCommand;
    }
}

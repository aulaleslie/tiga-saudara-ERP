<?php

namespace App\Console\Commands;

use App\Services\FileBackupService;
use Illuminate\Console\Command;

class BackupFilesCommand extends Command
{
    protected $signature = 'files:backup';
    protected $description = 'Create a verified, rotating backup archive of local permanent application files';

    public function handle(): int
    {
        /** @var FileBackupService $service */
        $service = app(FileBackupService::class);
        $result = $service->backup();

        if ($result['success']) {
            $this->info($result['message']);
            return self::SUCCESS;
        }

        $this->error($result['message']);
        return self::FAILURE;
    }
}


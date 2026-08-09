<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup';
    protected $description = 'Create a compressed backup of the configured MySQL database';

    public function handle()
    {
        $service = new DatabaseBackupService();
        $result = $service->backup();

        if ($result['success']) {
            $this->info($result['message']);
            return 0;
        }

        $this->error($result['message']);
        return 1;
    }
}

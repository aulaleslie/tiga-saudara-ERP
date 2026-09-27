<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\User\Database\Seeders\PermissionsTableSeeder;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Sync permissions from app/Config/Permissions.php into the database';

    public function handle(): int
    {
        $this->call('db:seed', [
            '--class' => PermissionsTableSeeder::class,
            '--force' => true,
        ]);

        return self::SUCCESS;
    }
}

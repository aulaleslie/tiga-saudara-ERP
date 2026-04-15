<?php

namespace Modules\PurchasesReturn\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Permissions for this module are now managed centrally in:
         * 1. app/Config/Permissions.php (Definition)
         * 2. Modules/User/Database/Seeders/PermissionsTableSeeder.php (Syncing)
         *
         * This seeder is neutralized to maintain a single source of truth.
         */
    }
}

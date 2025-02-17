<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class UserDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call(PermissionsTableSeeder::class);
        $this->call(PermissionSeeder_B3::class);
        $this->call(PermissionsSeeder_B2::class);
        $this->call(PermissionsSeeder_B3::class);
        $this->call(PermissionsSeeder_B4::class);
        $this->call(PermissionsSeeder_PaymentTerm::class);
        $this->call(PurchaseApprovalTableSeeder::class);
    }
}

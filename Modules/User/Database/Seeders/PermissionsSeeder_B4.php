<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Carbon\Carbon;


class PermissionsSeeder_B4 extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [

            'sale.access',
            'access_adjustments',
            'create_adjustments',
            'adjustments.index',
            'show_adjustments',
            'edit_adjustments',
            'delete_adjustments',

        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => Carbon::parse('2024-07-16 21:58:17'), 'updated_at' => Carbon::parse('2024-07-16 21:58:17')]
            );
        }
    }
}

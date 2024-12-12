<?php

namespace Modules\Setting\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class ChartOfAccountPermissionsSeederTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            'access_account',
            'create_account',
            'edit_account',
            'delete_account',
            'show_account'
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }
        
        $adminRole = \Spatie\Permission\Models\Role::findByName('Admin');
        
        foreach ($permissions as $permission) {
            $adminRole->givePermissionTo($permission);
        }
        
    }
}

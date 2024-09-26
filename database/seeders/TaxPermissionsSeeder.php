<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TaxPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        // Define the tax-related permissions
        $permissions = [
            'tax.create',
            'tax.access',
            'tax.edit',
            'tax.delete'
        ];

        // Loop through each permission and create it if it doesn't exist
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Optionally, assign these permissions to a role
        $role = Role::where('name', 'Admin')->first();

        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
}

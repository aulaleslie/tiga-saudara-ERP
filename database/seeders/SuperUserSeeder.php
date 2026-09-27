<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SuperUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Check if user already exists
        $user = User::where('email', 'super.admin@tiga-computer.com')->first();

        if (!$user) {
            $user = User::create([
                'name' => 'Administrator',
                'email' => 'super.admin@tiga-computer.com',
                'password' => Hash::make('Bima@1234'),
                'is_active' => 1
            ]);
        }

        // Check if role already exists (idempotent)
        $superAdmin = Role::where('name', 'Super Admin')->firstOrCreate([
            'name' => 'Super Admin'
        ]);

        // Fetch all available permissions and sync to Super Admin role
        $allPermissions = Permission::pluck('name')->toArray();
        $superAdmin->syncPermissions($allPermissions);

        // Assign role if not already assigned
        if (!$user->hasRole($superAdmin)) {
            $user->assignRole($superAdmin);
        }

        // Clear cached permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

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
                'password' => Hash::make(12345678),
                'is_active' => 1
            ]);
        }

        // Check if role already exists
        $superAdmin = Role::where('name', 'Super Admin')->first();

        if (!$superAdmin) {
            $superAdmin = Role::create([
                'name' => 'Super Admin'
            ]);
        }

        // Assign role if not already assigned
        if (!$user->hasRole($superAdmin)) {
            $user->assignRole($superAdmin);
        }
    }
}

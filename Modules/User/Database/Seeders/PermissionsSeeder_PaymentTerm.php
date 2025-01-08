<?php

namespace Modules\User\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionsSeeder_PaymentTerm extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            'payment_term.access',
            'payment_term.show',
            'payment_term.create',
            'payment_term.update',
            'payment_term.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => Carbon::parse('2024-07-16 21:58:17'), 'updated_at' => Carbon::parse('2024-07-16 21:58:17')]
            );
        }
    }
}

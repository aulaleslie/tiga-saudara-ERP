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
        Model::unguard();

        $permissions = [
            'purchaseReturnSettlements.access',
            'purchaseReturnSettlements.submit',
            'purchaseReturnSettlements.approve',
            'purchaseReturnSettlements.reject',
            'purchaseReturnSettlements.execute',
            'purchaseReturnSettlements.dispatch',
            'purchaseReturnSettlements.receive',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Assign to Super Admin (assuming ID 1 or role name 'Super Admin')
        $role = Role::where('name', 'Super Admin')->first();
        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
}

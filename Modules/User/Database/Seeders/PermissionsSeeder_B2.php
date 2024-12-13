<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Carbon\Carbon;


class PermissionsSeeder_B2 extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            'show_total_income',
            'show_total_purchase',
            'show_total_sales',
            'bussines_setting',
            'view_bussiness',
            'crud_bussiness',
            'access_user_permissions',
            //Costumer
            'customer.access',
            'customer.create',
            'customer.view',
            'customer.edit',
            'customer.delete',
            //Suplier
            'supplier.access',
            'supplier.create',
            'supplier.view',
            'supplier.edit',
            'supplier.delete',
            //Product
            'view_access_table_product',
            //Brand
            'access_brand',
            'brand.access',
            'brand.create',
            'brand.edit',
            'brand.delete',
            'brand.view',
            //Location
            'location.access',
            'location.create',
            'location.view',
            'location.edit',
            'location.delete',
            //User
            'users.access',
            'users.create',
            'users.view',
            'users.edit',
            'users.delete',
            //Roles
            'role.access',
            'role.create',
            'role.view',
            'role.edit',
            'role.delete',
            //Adjustment
            'adjustment.access',
            'adjustment.create',
            'adjustment.view',
            'adjustment.edit',
            'adjustment.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => Carbon::parse('2024-07-16 21:58:17'), 'updated_at' => Carbon::parse('2024-07-16 21:58:17')]
            );
        }
    }
}

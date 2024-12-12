<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Carbon\Carbon;


class PermissionsSeeder_B3 extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            //Sale
            'sale.access',
            'sale.create',
            'sale.edit',
            'sale.delete',
            'sale.view',
            //Return Sale
            'rsale.access',
            'rsale.create',
            'rsale.edit',
            'rsale.delete',
            'rsale.view',
            //purchase
            'purchase.access',
            'purchase.create',
            'purchase.edit',
            'purchase.delete',
            'purchase.view',
            //Return Purchase
            'rpurchase.access',
            'rpurchase.create',
            'rpurchase.edit',
            'rpurchase.delete',
            'rpurchase.view',
            //Cost
            'cost.access',
            'cost.create',
            'cost.edit',
            'cost.delete',
            'cost.view',
            //Product Category
            'cproduct.access',
            'cproduct.create',
            'cproduct.edit',
            'cproduct.delete',
            'cproduct.view',
            //Product
            'product.access',
            'product.create',
            'product.edit',
            'product.delete',
            'product.view',
            //Transfer Stock
            'tfstock.access',
            'tfstock.create',
            'tfstock.edit',
            'tfstock.delete',
            'tfstock.view',
            //Breakage
            'break.access',
            'break.create',
            'break.edit',
            'break.delete',
            'break.view',

        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => Carbon::parse('2024-07-16 21:58:17'), 'updated_at' => Carbon::parse('2024-07-16 21:58:17')]
            );
        }
    }
}

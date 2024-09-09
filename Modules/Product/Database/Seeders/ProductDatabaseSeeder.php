<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Unit;
use App\Models\User;
use Modules\Setting\Entities\Setting;

class ProductDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();

        $superAdmin = User::where('email', 'super.admin@test.com')->firstOrFail();
        $setting = Setting::where('company_name', 'Tiga Saudara ERP')->firstOrFail();

        Category::create([
            'category_code' => 'CA_01',
            'category_name' => 'Random',
            'created_by' => $superAdmin->id,
            'setting_id' => $setting->id
        ]);

        Unit::create([
            'name' => 'Piece',
            'short_name' => 'PC',
            'operator' => '*',
            'operation_value' => 1
        ]);
    }
}

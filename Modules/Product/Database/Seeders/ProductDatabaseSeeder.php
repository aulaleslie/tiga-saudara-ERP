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

        $superAdmin = User::where('email', 'super.admin@tiga-computer.com')->firstOrFail();
        $setting = Setting::where('company_name', 'CV Tiga Computer')->firstOrFail();

        // Check if category already exists
        $category = Category::where('category_code', 'CA_01')->first();

        if (!$category) {
            $category = Category::create([
                'category_code' => 'CA_01',
                'category_name' => 'Stationery',
                'created_by' => $superAdmin->id,
                'setting_id' => $setting->id
            ]);
        }

        // Check if unit already exists
        $unit = Unit::where('name', 'Piece')->first();

        if (!$unit) {
            $unit = Unit::create([
                'name' => 'Piece',
                'short_name' => 'PC(s)',
                'operator' => '*',
                'operation_value' => 1
            ]);
        }
    }
}

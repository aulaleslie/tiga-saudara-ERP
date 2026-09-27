<?php

namespace Modules\Setting\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Setting;

class ChartOfAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Get the first setting (company) ID
        $setting = Setting::first();

        if ($setting) {
            ChartOfAccount::create([
                'setting_id' => $setting->id,
                'name' => 'Kas',
                'account_number' => '1-10001',
                'category' => 'Kas & Bank',
                'parent_account_id' => null,
                'tax_id' => null,
                'description' => 'Akun Kas untuk transaksi tunai',
            ]);
        }
    }
}

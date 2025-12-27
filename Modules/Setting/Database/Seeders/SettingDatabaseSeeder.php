<?php

namespace Modules\Setting\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;

class SettingDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $companies = [
            'CV Tiga Nusa Computer',
            'CV Top IT Internusa',
            'Tiga Computer',
            'White Knight Computer',
            'Dunia Computer',
            'Perdana',
        ];

        foreach ($companies as $company) {
            $setting = Setting::create([
                'company_name' => $company,
                'company_email' => 'contactus@tiga-computer.com',
                'company_phone' => '012345678901',
                'notification_email' => 'notification@tiga-computer.com',
                'default_currency_id' => 1,
                'default_currency_position' => 'prefix',
                'footer_text' => 'CV Tiga Computer © 2021',
                'company_address' => 'Bima, NTB',
                'document_prefix' => 'TNC',
                'purchase_prefix_document' => 'BL',
                'sale_prefix_document' => 'JL',
                'pos_document_prefix' => 'POS', // Will use sale_prefix_document if null
                'pos_idle_threshold_minutes' => 30,
                'pos_default_cash_threshold' => 0,
            ]);

            Location::create([
                'setting_id' => $setting->id,
                'name' => 'Gudang Barang',
            ]);
        }

        // Call additional seeders
        $this->call([
            TaxSeeder::class,
            ChartOfAccountSeeder::class,
            PaymentMethodSeeder::class,
        ]);
    }
}

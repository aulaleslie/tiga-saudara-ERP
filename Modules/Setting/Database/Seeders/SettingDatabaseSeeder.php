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
            ['name' => 'CV Tiga Nusa Computer', 'document_prefix' => 'TNC'],
            ['name' => 'CV Top IT Internusa', 'document_prefix' => 'TPI'],
            ['name' => 'Tiga Computer', 'document_prefix' => 'TC'],
            ['name' => 'White Knight Computer', 'document_prefix' => 'WKC'],
            ['name' => 'Dunia Computer', 'document_prefix' => 'DC'],
            ['name' => 'Perdana', 'document_prefix' => 'PD'],
            ['name' => 'Daizu Kedelai', 'document_prefix' => 'DK'],
        ];

        foreach ($companies as $company) {
            $setting = Setting::create([
                'company_name' => $company['name'],
                'company_email' => 'contactus@tiga-computer.com',
                'company_phone' => '012345678901',
                'notification_email' => 'notification@tiga-computer.com',
                'default_currency_id' => 1,
                'default_currency_position' => 'prefix',
                'footer_text' => 'CV Tiga Computer © 2021',
                'company_address' => 'Bima, NTB',
                'document_prefix' => $company['document_prefix'],
                'is_pkp' => $company['name'] === 'CV Tiga Nusa Computer',
                'purchase_prefix_document' => 'BL',
                'purchase_return_prefix_document' => 'PRRN',
                'sale_prefix_document' => 'JL',
                'sale_return_prefix_document' => 'SLRN',
                'pos_enabled' => true,
                'pos_transactions_enabled' => true,
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

        // Enable all POS payment methods for every setting
        Setting::with('posPaymentMethods')->get()->each(function ($setting) {
            $ids = $setting->posPaymentMethods->pluck('id')->toArray();
            if (! empty($ids)) {
                $setting->posPaymentMethods()->updateExistingPivot($ids, ['is_enabled' => true]);
            }
        });
    }
}

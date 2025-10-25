<?php

namespace Modules\Setting\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Setting\Entities\Setting;

class SettingDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Setting::create([
            'company_name' => 'CV Tiga Computer',
            'company_email' => 'contactus@tiga-computer.com',
            'company_phone' => '012345678901',
            'notification_email' => 'notification@tiga-computer.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'CV Tiga Computer © 2021',
            'company_address' => 'Bima, NTB',
            'document_prefix' => 'TS',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL'
        ]);
    }
}

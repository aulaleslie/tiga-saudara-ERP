<?php

namespace Tests\Feature\SalesReturn;

use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

/**
 * Real related records for standard Sales Return tests, with SQLite foreign keys ENABLED.
 * Tests must create what they reference instead of switching constraints off.
 */
trait SeedsReferenceData
{
    protected function seedSetting(int $id = 1): Setting
    {
        return Setting::query()->forceCreate([
            'id' => $id,
            'company_name' => 'Test Company ' . $id,
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
    }

    protected function seedCustomer(int $id = 1, int $settingId = 1): Customer
    {
        return Customer::query()->forceCreate([
            'id' => $id,
            'setting_id' => $settingId,
            'customer_name' => 'Test Customer',
            'customer_email' => "customer{$id}@test.com",
            'customer_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);
    }

    protected function seedLocation(int $id = 1, int $settingId = 1): Location
    {
        return Location::query()->forceCreate([
            'id' => $id,
            'name' => 'Main Warehouse',
            'setting_id' => $settingId,
        ]);
    }
}

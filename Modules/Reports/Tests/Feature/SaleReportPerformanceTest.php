<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\People\Entities\Customer;
use App\Models\User;
use Spatie\Tags\Tag;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class SaleReportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_search_customers_performantly()
    {
        Permission::firstOrCreate(['name' => 'saleReports.access']);
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);
        
        $customers = [];
        for ($i = 0; $i < 1000; $i++) {
            $customers[] = [
                'setting_id' => $setting->id,
                'customer_name' => 'Customer ' . $i,
                'customer_email' => 'cus' . $i . '@example.com',
                'customer_phone' => '123' . $i,
                'address' => 'Addr ' . $i,
                'city' => 'City',
                'country' => 'Country',
            ];
        }
        Customer::insert($customers);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $user->givePermissionTo('saleReports.access');

        session(['setting_id' => $setting->id]);

        $start = microtime(true);
        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Reports\SaleReport::class)
            ->set('settingId', $setting->id)
            ->set('customerSearch', 'Customer 99')
            ->assertCount('customerOptions', 10);
        $time = microtime(true) - $start;

        $this->assertLessThan(1.0, $time, "Customer search took too long: {$time}s");
    }
}

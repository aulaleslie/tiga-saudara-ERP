<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\People\Entities\Supplier;
use App\Models\User;
use Spatie\Tags\Tag;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PurchaseReportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_search_suppliers_performantly()
    {
        Permission::firstOrCreate(['name' => 'purchaseReports.access']);
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
        
        $suppliers = [];
        for ($i = 0; $i < 1000; $i++) {
            $suppliers[] = [
                'setting_id' => $setting->id,
                'supplier_name' => 'Supplier ' . $i,
                'supplier_email' => 'sup' . $i . '@example.com',
                'supplier_phone' => '123' . $i,
                'address' => 'Addr ' . $i,
                'city' => 'City',
                'country' => 'Country',
            ];
        }
        Supplier::insert($suppliers);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $user->givePermissionTo('purchaseReports.access');

        session(['setting_id' => $setting->id]);

        $start = microtime(true);
        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Reports\PurchaseReport::class)
            ->set('settingId', $setting->id)
            ->set('supplierSearch', 'Supplier 99')
            ->assertCount('supplierOptions', 10);
        $time = microtime(true) - $start;

        $this->assertLessThan(1.0, $time, "Supplier search took too long: {$time}s");
    }
}

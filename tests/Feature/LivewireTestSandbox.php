<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Currency\Entities\Currency;
use Livewire\Livewire;

class LivewireTestSandbox extends TestCase
{
    use RefreshDatabase;

    public function test_html()
    {
        Permission::firstOrCreate(['name' => 'purchaseReports.access']);
        Role::firstOrCreate(['name' => 'Staff']);

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

        $user = User::factory()->create();
        $user->assignRole('Staff');
        $user->givePermissionTo('purchaseReports.access');

        $staffRole = Role::where('name', 'Staff')->first();
        $user->settings()->attach($setting->id, ['role_id' => $staffRole->id]);

        $component = Livewire::actingAs($user)
            ->test(\App\Livewire\Reports\PurchaseBySupplierReport::class)
            ->set('periodPreset', 'today');

        file_put_contents('livewire_html_output.txt', $component->html());
    }
}

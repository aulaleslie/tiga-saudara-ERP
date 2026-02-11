<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Product\ProductSerialNumbersTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductSerialNumbersTableTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'Test Setting',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->location = Location::create([
            'name' => 'Main Location',
            'setting_id' => $setting->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Serial Product',
            'product_code' => 'SRL-001',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 1500,
            'serial_number_required' => true,
        ]);

        session(['setting_id' => $setting->id]);
    }

    public function test_clear_search_resets_query_server_filter_tab_and_pagination(): void
    {
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'ACTIVE-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'ACTIVE-002',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
            'is_in_return_process' => false,
        ]);

        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'BROKEN-001',
            'status' => ProductSerialNumber::STATUS_BROKEN,
            'is_broken' => true,
            'is_in_return_process' => false,
        ]);

        Livewire::test(ProductSerialNumbersTable::class, ['productId' => $this->product->id])
            ->set('perPage', 1)
            ->call('setTab', 'broken')
            ->set('searchQuery', 'BROKEN')
            ->assertSee('BROKEN-001')
            ->set('page', 2)
            ->call('clearSearch')
            ->assertSet('searchQuery', '')
            ->assertSet('currentTab', 'sellable')
            ->assertSee('ACTIVE-001')
            ->assertDontSee('ACTIVE-002')
            ->assertDontSee('BROKEN-001');
    }
}

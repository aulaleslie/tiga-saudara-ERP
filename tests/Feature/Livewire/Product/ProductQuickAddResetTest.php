<?php

namespace Tests\Feature\Livewire\Product;

use App\Livewire\Modules\Product\Modals\ProductQuickAddModal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ProductQuickAddResetTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Setting',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    public function test_form_is_reset_after_successful_save(): void
    {
        $component = Livewire::test(ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'purchase'])
            ->set('product_name', 'Test Product')
            ->set('base_unit_id', $this->unit->id)
            ->set('purchase_price', 500)
            ->set('is_sold', true)
            ->set('sale_price', 1000)
            ->set('tier_1_price', 900)
            ->set('tier_2_price', 800)
            ->set('serial_number_required', true)
            ->set('product_stock_alert', 10)
            ->set('barcode', '123456789')
            ->call('save');

        $component->assertHasNoErrors();
        
        // Even if closeModal is called, we want to check if the internal state was reset
        // because the modal might be reopened.
        $component->assertSet('product_name', null)
            ->assertSet('is_sold', false)
            ->assertSet('sale_price', null)
            ->assertSet('serial_number_required', false)
            ->assertSet('product_stock_alert', null)
            ->assertSet('barcode', null);
            
        $this->assertGreaterThan(1, $component->get('formResetVersion'));
    }
}

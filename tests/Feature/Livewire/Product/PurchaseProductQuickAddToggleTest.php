<?php

namespace Tests\Feature\Livewire\Product;

use App\Livewire\Modules\Product\Modals\ProductQuickAddModal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseProductQuickAddToggleTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;

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
            'company_name' => 'Purchase Toggle',
            'company_email' => 'purchase-toggle@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    public function test_purchase_quick_add_starts_with_sale_pricing_hidden_and_inactive(): void
    {
        Livewire::test(ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'purchase'])
            ->assertSet('context', 'purchase')
            ->assertSet('is_sold', false)
            ->assertSee('Pajak Beli')
            ->assertSee('Pilih pajak...')
            ->assertSeeHtml('class="mt-3 d-none"');
    }

    public function test_purchase_quick_add_reveals_sale_pricing_when_toggled_on(): void
    {
        Livewire::test(ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'purchase'])
            ->set('is_sold', true)
            ->assertSet('is_sold', true)
            ->assertSee('Harga Jual')
            ->assertSee('Pajak Jual')
            ->assertDontSeeHtml('class="mt-3 d-none"');
    }

    public function test_purchase_quick_add_clears_sale_pricing_and_hides_when_toggled_off(): void
    {
        Livewire::test(ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'purchase'])
            ->set('is_sold', true)
            ->set('sale_price', 100000)
            ->set('tier_1_price', 90000)
            ->set('tier_2_price', 85000)
            ->set('sale_tax_id', 1)
            ->set('is_sold', false)
            ->assertSet('is_sold', false)
            ->assertSet('sale_price', null)
            ->assertSet('tier_1_price', null)
            ->assertSet('tier_2_price', null)
            ->assertSet('sale_tax_id', null)
            ->assertSeeHtml('class="mt-3 d-none"');
    }

    public function test_sales_quick_add_preserves_always_on_sale_pricing(): void
    {
        Livewire::test(ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'sale'])
            ->assertSet('context', 'sale')
            ->assertSet('is_sold', true)
            ->assertSee('Harga Jual')
            ->assertSee('Pajak Jual')
            ->assertDontSeeHtml('class="mt-3 d-none"')
            // Try to toggle it off, should be forced back to true
            ->set('is_sold', false)
            ->assertSet('is_sold', true)
            ->assertDontSeeHtml('class="mt-3 d-none"');
    }

    public function test_purchase_quick_add_creates_identical_prices_for_all_businesses(): void
    {
        $secondSetting = Setting::create([
            'company_name' => 'Second Purchase Business',
            'company_email' => 'second-purchase@example.com',
            'company_phone' => '654321',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'second-purchase@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        Livewire::test(ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'purchase'])
            ->set('product_name', 'Purchase Quick Add Product')
            ->set('base_unit_id', $unit->id)
            ->set('purchase_price', 50000)
            ->set('is_sold', true)
            ->set('sale_price', 75000)
            ->call('save')
            ->assertHasNoErrors();

        $product = \Modules\Product\Entities\Product::query()->latest('id')->firstOrFail();
        $prices = \Modules\Product\Entities\ProductPrice::query()->where('product_id', $product->id)->get();

        $this->assertCount(2, $prices);

        foreach ([$this->setting->id, $secondSetting->id] as $settingId) {
            $price = $prices->firstWhere('setting_id', $settingId);
            $this->assertNotNull($price);
            $this->assertSame('50000.00', $price->last_purchase_price);
            $this->assertSame('75000.00', $price->sale_price);
        }
    }
}

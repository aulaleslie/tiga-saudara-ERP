<?php

namespace Tests\Feature\Livewire\Product;

use App\Livewire\Modules\Product\Modals\ProductQuickAddModal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Livewire\TaxSearchDropdown;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class ProductQuickAddTaxSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;

    protected Unit $unit;

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

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    public function test_product_quick_add_modal_persists_manually_selected_purchase_tax_and_emits_matching_payload(): void
    {
        $purchaseTax = Tax::create([
            'name' => 'PPN Purchase',
            'value' => 11,
        ]);

        Livewire::test(ProductQuickAddModal::class)
            ->set('product_name', 'Manual Tax Product')
            ->set('base_unit_id', $this->unit->id)
            ->set('purchase_price', 125000)
            ->call('handleTaxSelected', 'purchase_tax_id', $purchaseTax->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('productCreated', function ($event, $params) use ($purchaseTax) {
                $payload = $params[0] ?? [];

                return ($payload['purchase_tax_id'] ?? null) === $purchaseTax->id;
            });

        $price = ProductPrice::query()->where('setting_id', $this->setting->id)->firstOrFail();

        $this->assertSame($purchaseTax->id, $price->purchase_tax_id);
        $this->assertNull($price->sale_tax_id);
    }

    public function test_new_purchase_tax_auto_selection_dispatches_parent_sync_and_persists_into_saved_product_payload(): void
    {
        $purchaseTax = Tax::create([
            'name' => 'PPN Auto Purchase',
            'value' => 12,
        ]);

        Livewire::test(TaxSearchDropdown::class, [
            'name' => 'purchase_tax_id',
            'inputId' => 'quick_purchase_tax_id',
            'allowCreate' => true,
            'dispatchTo' => 'modules.product.modals.product-quick-add-modal',
        ])
            ->call('handleTaxCreated', $purchaseTax->id, $purchaseTax->name, $purchaseTax->value, null, 'purchase_tax_id')
            ->assertSet('selected', $purchaseTax->id)
            ->assertSet('selectedLabel', 'PPN AUTO PURCHASE (12%)')
            ->assertDispatched('taxDropdownSelected', function ($event, $params) use ($purchaseTax) {
                return ($params['name'] ?? null) === 'purchase_tax_id'
                    && ($params['value'] ?? null) === $purchaseTax->id;
            });

        Livewire::test(ProductQuickAddModal::class)
            ->dispatch('taxDropdownSelected', name: 'purchase_tax_id', value: $purchaseTax->id)
            ->set('product_name', 'Auto Purchase Tax Product')
            ->set('base_unit_id', $this->unit->id)
            ->set('purchase_price', 130000)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('productCreated', function ($event, $params) use ($purchaseTax) {
                $payload = $params[0] ?? [];

                return ($payload['purchase_tax_id'] ?? null) === $purchaseTax->id;
            });

        $price = ProductPrice::query()->where('setting_id', $this->setting->id)->latest('id')->firstOrFail();

        $this->assertSame($purchaseTax->id, $price->purchase_tax_id);
    }

    public function test_new_sale_tax_auto_selection_dispatches_parent_sync_and_persists_into_saved_product_payload(): void
    {
        $saleTax = Tax::create([
            'name' => 'PPN Auto Sale',
            'value' => 13,
        ]);

        Livewire::test(TaxSearchDropdown::class, [
            'name' => 'sale_tax_id',
            'inputId' => 'quick_sale_tax_id',
            'allowCreate' => true,
            'dispatchTo' => 'modules.product.modals.product-quick-add-modal',
        ])
            ->call('handleTaxCreated', $saleTax->id, $saleTax->name, $saleTax->value, null, 'sale_tax_id')
            ->assertSet('selected', $saleTax->id)
            ->assertSet('selectedLabel', 'PPN AUTO SALE (13%)')
            ->assertDispatched('taxDropdownSelected', function ($event, $params) use ($saleTax) {
                return ($params['name'] ?? null) === 'sale_tax_id'
                    && ($params['value'] ?? null) === $saleTax->id;
            });

        Livewire::test(ProductQuickAddModal::class)
            ->set('is_sold', true)
            ->dispatch('taxDropdownSelected', name: 'sale_tax_id', value: $saleTax->id)
            ->set('product_name', 'Auto Sale Tax Product')
            ->set('base_unit_id', $this->unit->id)
            ->set('purchase_price', 150000)
            ->set('sale_price', 180000)
            ->set('tier_1_price', 175000)
            ->set('tier_2_price', 170000)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('productCreated', function ($event, $params) use ($saleTax) {
                $payload = $params[0] ?? [];

                return ($payload['purchase_tax_id'] ?? null) === null;
            });

        $price = ProductPrice::query()->where('setting_id', $this->setting->id)->latest('id')->firstOrFail();

        $this->assertSame($saleTax->id, $price->sale_tax_id);
    }

    public function test_tax_created_auto_selection_is_scoped_to_the_requesting_dropdown(): void
    {
        $purchaseTax = Tax::create([
            'name' => 'Scoped Purchase Tax',
            'value' => 10,
        ]);

        Livewire::test(TaxSearchDropdown::class, [
            'name' => 'purchase_tax_id',
            'inputId' => 'quick_purchase_tax_id',
            'allowCreate' => true,
            'dispatchTo' => 'modules.product.modals.product-quick-add-modal',
        ])
            ->call('handleTaxCreated', $purchaseTax->id, $purchaseTax->name, $purchaseTax->value, null, 'sale_tax_id')
            ->assertSet('selected', null)
            ->assertSet('selectedLabel', null);

        Livewire::test(TaxSearchDropdown::class, [
            'name' => 'sale_tax_id',
            'inputId' => 'quick_sale_tax_id',
            'allowCreate' => true,
            'dispatchTo' => 'modules.product.modals.product-quick-add-modal',
        ])
            ->call('handleTaxCreated', $purchaseTax->id, $purchaseTax->name, $purchaseTax->value, null, 'purchase_tax_id')
            ->assertSet('selected', null)
            ->assertSet('selectedLabel', null);
    }
}

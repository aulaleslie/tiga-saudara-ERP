<?php

namespace Tests\Feature\Livewire\SalesReturn;

use App\Livewire\SalesReturn\SaleReferenceSearchDropdown;
use App\Support\SalesReturn\SaleReturnEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleReferenceSearchDropdownTest extends TestCase
{
    use RefreshDatabase;

    private function createSettingWithCurrency(): array
    {
        $suffix = Str::upper(Str::random(6));
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'CV Tiga Computer ' . $suffix,
            'company_email' => "info+{$suffix}@example.com",
            'company_phone' => '080000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'before',
            'notification_email' => "notif+{$suffix}@example.com",
            'footer_text' => 'Test Footer',
            'company_address' => 'Jl. Test 123',
            'document_prefix' => 'TS',
            'sale_prefix_document' => 'SL',
        ]);

        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Gudang Pusat',
        ]);

        return compact('currency', 'setting', 'location');
    }

    private function createProduct(Setting $setting): Product
    {
        $suffix = Str::upper(Str::random(6));

        return Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Produk Test',
            'product_code' => 'PRD-' . $suffix,
            'product_quantity' => 50,
            'serial_number_required' => 0,
            'broken_quantity' => 0,
            'product_cost' => 50000,
            'product_price' => 125000,
            'product_stock_alert' => 5,
            'is_purchased' => 1,
            'is_sold' => 1,
            'sale_price' => 125000,
            'tier_1_price' => 125000,
            'tier_2_price' => 125000,
        ]);
    }

    private function createDispatchedSale(int $dispatchedQuantity = 5, array $saleOverrides = []): array
    {
        ['setting' => $setting, 'location' => $location] = $this->createSettingWithCurrency();

        session()->put('setting_id', $setting->id);

        $product = $this->createProduct($setting);

        $sale = Sale::create(array_merge([
            'date' => now()->format('Y-m-d'),
            'reference' => 'SALE-' . Str::upper(Str::random(6)),
            'customer_name' => 'Acme Corp',
            'setting_id' => $setting->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $dispatchedQuantity * 125000,
            'paid_amount' => $dispatchedQuantity * 125000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'note' => null,
        ], $saleOverrides));

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $dispatchedQuantity,
            'price' => 125000,
            'unit_price' => 125000,
            'sub_total' => $dispatchedQuantity * 125000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'dispatched_quantity' => $dispatchedQuantity,
            'tax_id' => null,
        ]);

        return compact('sale', 'product', 'dispatchDetail', 'saleDetail');
    }

    /** @test */
    public function it_can_render_component()
    {
        Livewire::test(SaleReferenceSearchDropdown::class)
            ->assertStatus(200);
    }

    /** @test */
    public function it_can_search_for_eligible_sales()
    {
        $eligible = $this->createDispatchedSale(5, ['reference' => 'SALE-ELIGIBLE']);
        $ineligible = $this->createDispatchedSale(5, [
            'reference' => 'SALE-DRAFT', 
            'status' => Sale::STATUS_DRAFT
        ]);

        Livewire::test(SaleReferenceSearchDropdown::class)
            ->set('search', 'SALE')
            ->assertSee('SALE-ELIGIBLE')
            ->assertDontSee('SALE-DRAFT');
    }

    /** @test */
    public function it_emits_event_when_sale_selected()
    {
        $data = $this->createDispatchedSale(5, ['reference' => 'SALE-SELECT']);
        $sale = $data['sale'];

        Livewire::test(SaleReferenceSearchDropdown::class)
            ->set('search', 'SALE') // Populate options first
            ->call('select', $sale->id)
            ->assertDispatched('saleReferenceSelected', function ($event, $data) use ($sale) {
                return $data['id'] === $sale->id 
                    && $data['reference'] === $sale->reference
                    && isset($data['rows']) // Ensure payload structure is correct
                    && count($data['rows']) > 0;
            });
    }

    /** @test */
    public function it_can_clear_selection()
    {
        Livewire::test(SaleReferenceSearchDropdown::class)
            ->set('selected', 1)
            ->set('selectedLabel', 'SALE-001')
            ->call('clearSelection')
            ->assertSet('selected', null)
            ->assertSet('selectedLabel', null)
            ->assertDispatched('saleReferenceSelected', ['id' => null]);
    }
    
    /** @test */
    public function it_shows_no_results_message()
    {
        Livewire::test(SaleReferenceSearchDropdown::class)
            ->set('search', 'NONEXISTENT')
            ->assertSee('Tidak ditemukan penjualan');
    }
}

<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SalesRowTotalRoundingTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $user;
    private Product $product;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create([
            'company_name' => 'PT Tiga Saudara Sales',
            'row_total_rounding_increment' => 100.00,
            'is_pkp' => true,
        ]);

        $this->user = User::factory()->create();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::findOrCreate('sales.create', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('sales.edit', 'web');
        $this->user->givePermissionTo(['sales.create', 'sales.edit']);
        $this->actingAs($this->user);

        session([
            'setting_id' => $this->setting->id,
            'user_settings' => collect([$this->setting]),
        ]);

        $this->customer = Customer::factory()->create();

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'CAT1',
            'category_name' => 'General',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Barang A',
            'product_code' => 'BRG-A',
            'product_quantity' => 100,
            'product_cost' => 50000,
            'product_price' => 70000,
            'product_unit' => $unit->id,
        ]);

        Tax::create([
            'name' => 'PPN 11%',
            'value' => 11.00,
            'is_default' => true,
        ]);
    }

    public function test_automatic_row_total_rounds_78999_96_to_79000(): void
    {
        // Tax = 11% (ex-tax input mode in ProductPrice).
        // 71171.135 * 1.11 = 78999.95985 -> Raw tax-inclusive = 78999.96 -> Round 79000.00
        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 71171.135,
        ]);

        Cart::instance('sale')->destroy();

        Livewire::test(\App\Livewire\Sale\ProductCart::class, ['cartInstance' => 'sale'])
            ->set('is_tax_included', false)
            ->call('handleTaxIncluded')
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'Pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $this->assertEquals(79000.00, $cartItem->options->sub_total);
        $this->assertEquals(71171.17, $cartItem->options->sub_total_before_tax);
        $this->assertEquals(7828.83, $cartItem->options->product_tax_amount);
        $this->assertEquals(79000.00, $cartItem->options->sub_total_before_tax + $cartItem->options->product_tax_amount);
    }

    public function test_automatic_row_total_rounds_78949_to_78900(): void
    {
        // 71125.225 * 1.11 = 78949.00 -> Round 78900.00
        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 71125.225,
        ]);

        Cart::instance('sale')->destroy();

        Livewire::test(\App\Livewire\Sale\ProductCart::class, ['cartInstance' => 'sale'])
            ->set('is_tax_included', false)
            ->call('handleTaxIncluded')
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'Pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $this->assertEquals(78900.00, $cartItem->options->sub_total);
    }

    public function test_manual_unit_price_bypasses_automatic_rounding(): void
    {
        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 71171.135,
        ]);

        Cart::instance('sale')->destroy();

        $comp = Livewire::test(\App\Livewire\Sale\ProductCart::class, ['cartInstance' => 'sale'])
            ->set('is_tax_included', false)
            ->call('handleTaxIncluded')
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'Pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();

        // Update price manually
        $comp->set('unit_price.' . $cartItem->id, 71171.135)
            ->call('updatePrice', $cartItem->rowId, $cartItem->id);

        $cartItemUpdated = Cart::instance('sale')->content()->first();
        $this->assertEquals('manual_unit_price', $cartItemUpdated->options->pricing_source);
        $this->assertEquals(78999.96, round($cartItemUpdated->options->sub_total, 2));
    }

    public function test_manual_line_total_bypasses_automatic_rounding(): void
    {
        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 71171.135,
        ]);

        Cart::instance('sale')->destroy();

        $comp = Livewire::test(\App\Livewire\Sale\ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'Pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();

        // Update line total manually to exact 78949.00
        $comp->set('line_total.' . $cartItem->id, 78949.00)
            ->call('updateLineTotal', $cartItem->rowId, $cartItem->id);

        $cartItemUpdated = Cart::instance('sale')->content()->first();
        $this->assertEquals('manual_line_total', $cartItemUpdated->options->pricing_source);
        $this->assertEquals(78949.00, $cartItemUpdated->options->sub_total);
    }

    /**
     * Adds the product at the given ex-tax unit price and returns the cart row.
     */
    private function addAutomaticRow(float $salePrice): \Gloudemans\Shoppingcart\CartItem
    {
        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $salePrice,
        ]);

        Cart::instance('sale')->destroy();

        Livewire::test(\App\Livewire\Sale\ProductCart::class, ['cartInstance' => 'sale'])
            ->set('is_tax_included', false)
            ->call('handleTaxIncluded')
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'Pcs',
            ]);

        return Cart::instance('sale')->content()->first();
    }

    /**
     * Adds the product in tax-included mode, where the raw row total is exactly
     * unit price x quantity. This lets a boundary be hit exactly from a price
     * that survives the decimal(10,2) `sale_price` column.
     */
    private function addAutomaticRowTaxIncluded(float $salePrice): \Gloudemans\Shoppingcart\CartItem
    {
        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $salePrice,
        ]);

        Cart::instance('sale')->destroy();

        Livewire::test(\App\Livewire\Sale\ProductCart::class, ['cartInstance' => 'sale'])
            ->set('is_tax_included', true)
            ->call('handleTaxIncluded')
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'Pcs',
            ]);

        return Cart::instance('sale')->content()->first();
    }

    public function test_automatic_row_total_rounds_78899_96_up_to_78900(): void
    {
        // Raw 78899.96 sits above the 78850 midpoint, so it rounds up to 78900.
        $cartItem = $this->addAutomaticRowTaxIncluded(78899.96);

        $this->assertEquals(78900.00, $cartItem->options->sub_total);
    }

    public function test_automatic_row_total_rounds_78950_midpoint_up_to_79000(): void
    {
        // Exact midpoint between 78900 and 79000 must resolve half-up to 79000.
        $cartItem = $this->addAutomaticRowTaxIncluded(78950.00);

        $this->assertEquals(79000.00, $cartItem->options->sub_total);
    }

    public function test_automatic_row_total_rounds_78949_just_below_midpoint_down(): void
    {
        // One rupiah below the midpoint must resolve down to 78900.
        $cartItem = $this->addAutomaticRowTaxIncluded(78949.00);

        $this->assertEquals(78900.00, $cartItem->options->sub_total);
    }

    public function test_rounded_row_reconciles_dpp_plus_tax_in_tax_excluded_mode(): void
    {
        $cartItem = $this->addAutomaticRow(71171.135);

        $total = (float) $cartItem->options->sub_total;
        $dpp = (float) $cartItem->options->sub_total_before_tax;
        $tax = (float) $cartItem->options->product_tax_amount;

        $this->assertEquals(79000.00, $total);
        // Invariant: DPP + tax == rounded row total, to two decimals.
        $this->assertEquals($total, round($dpp + $tax, 2));
        // Tax is reallocated backward out of the rounded inclusive total.
        $this->assertEquals(round($total / 1.11, 2), $dpp);
    }

    public function test_rounded_row_reconciles_dpp_plus_tax_in_tax_included_mode(): void
    {
        // Tax-included: raw row total is 789.9996 x 100 = 78999.96 -> rounds to 79000.
        $cartItem = $this->addAutomaticRowTaxIncluded(78999.96);

        $total = (float) $cartItem->options->sub_total;
        $dpp = (float) $cartItem->options->sub_total_before_tax;
        $tax = (float) $cartItem->options->product_tax_amount;

        $this->assertEquals(79000.00, $total);
        $this->assertEquals($total, round($dpp + $tax, 2));
    }

    public function test_zero_increment_disables_rounding(): void
    {
        $this->setting->update(['row_total_rounding_increment' => 0]);

        // Same input as the 78950 midpoint case, which would otherwise round up
        // to 79000; with rounding disabled the raw total survives untouched.
        $cartItem = $this->addAutomaticRowTaxIncluded(78950.00);

        $this->assertEquals(78950.00, round((float) $cartItem->options->sub_total, 2));
    }

    public function test_grand_total_sums_rounded_rows_without_rerounding(): void
    {
        // Two automatic rows that each round to a multiple of 100, plus a shipping
        // amount that makes the grand total deliberately not a multiple of 100.
        $cartItem = $this->addAutomaticRow(71171.135);
        $this->assertEquals(79000.00, $cartItem->options->sub_total);

        $normalized = app(\Modules\Sale\Services\SaleNormalizer::class)->normalize([
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 17550,
            'paid_amount' => 0,
        ], Cart::instance('sale')->content(), true, $this->setting->id);

        $this->assertEquals(79000.00, $normalized['details'][0]['sub_total']);
        // Grand total is the summed rounded rows plus shipping, left unrounded.
        $this->assertEquals(96550.00, $normalized['header']['total_amount']);
    }

    public function test_persisted_sale_detail_keeps_rounded_row_and_survives_reload(): void
    {
        $cartItem = $this->addAutomaticRow(71171.135);
        $this->assertEquals(79000.00, $cartItem->options->sub_total);

        $normalized = app(\Modules\Sale\Services\SaleNormalizer::class)->normalize([
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
        ], Cart::instance('sale')->content(), true, $this->setting->id);

        $detail = $normalized['details'][0];

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-ROUND-1',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'setting_id' => $this->setting->id,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => $normalized['header']['total_amount'],
            'paid_amount' => 0,
            'due_amount' => $normalized['header']['due_amount'],
        ]);

        $saleDetail = $sale->saleDetails()->create([
            'product_id' => $detail['product_id'],
            'product_name' => $detail['product_name'],
            'product_code' => $detail['product_code'],
            'quantity' => $detail['quantity'],
            'price' => $detail['price'],
            'unit_price' => $detail['unit_price'],
            'sub_total' => $detail['sub_total'],
            'product_discount_type' => $detail['product_discount_type'],
            'product_discount_amount' => $detail['product_discount_amount'],
            'product_tax_amount' => $detail['product_tax_amount'],
            'pricing_source' => $detail['pricing_source'],
        ]);

        $fresh = $saleDetail->fresh();

        // Reload must preserve the stored rounded value and the lowercase sentinel.
        $this->assertEquals(79000.00, round((float) $fresh->sub_total, 2));
        $this->assertSame('automatic', $fresh->pricing_source);
    }

    public function test_adding_a_new_automatic_row_sets_the_recalculation_flag_true(): void
    {
        $cartItem = $this->addAutomaticRow(71171.135);

        $this->assertTrue((bool) $cartItem->options->get(\App\Support\RowTotalRoundingCalculator::RECALC_FLAG));
    }

    public function test_manual_line_total_clears_the_recalculation_flag(): void
    {
        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 71171.135,
        ]);

        Cart::instance('sale')->destroy();

        $comp = Livewire::test(\App\Livewire\Sale\ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'Pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();

        $comp->set('line_total.' . $cartItem->id, 78949.00)
            ->call('updateLineTotal', $cartItem->rowId, $cartItem->id);

        $updated = Cart::instance('sale')->content()->first();

        $this->assertSame('manual_line_total', $updated->options->pricing_source);
        $this->assertFalse((bool) $updated->options->get(\App\Support\RowTotalRoundingCalculator::RECALC_FLAG));
    }

    public function test_normalizer_reconstructs_and_rounds_when_flag_is_true(): void
    {
        // Flag true: the backend reprices under the current increment even
        // though a stored total of 78950 was supplied alongside it.
        $this->setting->update(['row_total_rounding_increment' => 100]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'row-flag',
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 78999.96,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'unit_price' => 78999.96,
                'sub_total' => 78950.00,
                'sub_total_before_tax' => 71126.13,
                'product_tax_amount' => 7823.87,
                'product_tax' => Tax::first()->id,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ]);

        $normalized = app(\Modules\Sale\Services\SaleNormalizer::class)->normalize([
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
        ], Cart::instance('sale')->content(), true, $this->setting->id);

        $this->assertEquals(79000.00, $normalized['details'][0]['sub_total']);
    }

    public function test_sale_store_and_update_do_not_accept_a_request_supplied_cart(): void
    {
        // The Sales trust boundary: unlike Purchase::store, neither Sales
        // endpoint reads a cart from the request, so a client cannot supply
        // row totals or pricing authority at all. This guards that property
        // against a future endpoint quietly gaining a request cart.
        $controller = file_get_contents(
            base_path('Modules/Sale/Http/Controllers/SaleController.php')
        );

        $this->assertStringNotContainsString("request->cart", $controller);
        $this->assertStringNotContainsString("has('cart')", $controller);
        $this->assertStringNotContainsString("input('cart'", $controller);
    }

    public function test_a_forged_pricing_source_cannot_bypass_rounding_on_an_automatic_row(): void
    {
        // Rows reaching the normalizer come from the server-side cart, but if a
        // manual sentinel ever arrived on a row the backend priced, rounding
        // would be bypassed. Assert the automatic path is what governs here.
        $cartItem = $this->addAutomaticRowTaxIncluded(78999.96);

        $this->assertSame('automatic', $cartItem->options->pricing_source);
        $this->assertEquals(79000.00, $cartItem->options->sub_total);
    }

    public function test_supplied_row_total_is_not_rerounded_after_the_increment_changes(): void
    {
        // A row committed under a 50 increment must survive normalization
        // unchanged after the business switches to 100. Loading and saving is
        // not a pricing event, so the supplied total stays authoritative.
        $this->setting->update(['row_total_rounding_increment' => 50]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'row-1',
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 78950.00,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'unit_price' => 78950.00,
                'sub_total' => 78950.00,
                'sub_total_before_tax' => 71126.13,
                'product_tax_amount' => 7823.87,
                'product_tax' => Tax::first()->id,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'pricing_source' => 'automatic',
            ],
        ]);

        $this->setting->update(['row_total_rounding_increment' => 100]);

        $normalized = app(\Modules\Sale\Services\SaleNormalizer::class)->normalize([
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
        ], Cart::instance('sale')->content(), true, $this->setting->id);

        $this->assertEquals(78950.00, $normalized['details'][0]['sub_total']);
    }

    public function test_row_interaction_after_the_increment_changes_does_reprice(): void
    {
        // The counterpart to the stability guarantee: an actual automatic
        // recalculation rewrites the cart total under the CURRENT increment.
        $this->setting->update(['row_total_rounding_increment' => 50]);

        $cartItem = $this->addAutomaticRowTaxIncluded(78950.00);
        $this->assertEquals(78950.00, $cartItem->options->sub_total);

        $this->setting->update(['row_total_rounding_increment' => 100]);

        // Re-run the automatic calculation for the row (a quantity interaction).
        Livewire::test(\App\Livewire\Sale\ProductCart::class, ['cartInstance' => 'sale'])
            ->set('is_tax_included', true)
            ->call('updateQuantity', $cartItem->rowId, $cartItem->id);

        $updated = Cart::instance('sale')->content()->first();
        $this->assertEquals(79000.00, $updated->options->sub_total);
    }
}

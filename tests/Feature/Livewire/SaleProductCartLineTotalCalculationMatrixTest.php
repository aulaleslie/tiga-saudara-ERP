<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\EditForm;
use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SaleService;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SaleProductCartLineTotalCalculationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Setting $pkpSetting;
    protected Currency $currency;
    protected PaymentTerm $paymentTerm;
    protected Tax $tax11;
    protected SaleService $saleService;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->saleService = app(SaleService::class);

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Non PKP Business',
            'company_email' => 'non-pkp@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $this->pkpSetting = Setting::create([
            'company_name' => 'PKP Business',
            'company_email' => 'pkp@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        $this->paymentTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Net 30'],
            ['longevity' => 30]
        );

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
            'is_default' => true,
        ]);

        session(['setting_id' => $this->setting->id]);
        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();
        parent::tearDown();
    }

    // ==================== LINE TOTAL REVERSE CALCULATION MATRIX ====================

    /**
     * Test case: non-PKP, no discount, qty=2, requested_total=2500, expected_unit_price=1250
     */
    public function test_line_total_non_pkp_no_discount_reverses_correctly()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Non-PKP Test Product',
            'product_code' => 'NONPKP-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Set quantity to 2
        $component->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        // Set line total to 2500, should reverse to unit_price=1250
        $component->set('line_total.' . $id, 2500)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertSame(2500.0, (float) $updatedItem->options->sub_total, 'sub_total should be 2500');
        $this->assertSame(1250.0, (float) $updatedItem->options->unit_price, 'unit_price should be 1250');
        $this->assertSame(1250.0, (float) $updatedItem->price, 'price should be 1250');
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);
    }

    /**
     * Test case: non-PKP, tax-exclusive mode, qty=2, requested_total=2500, expected_unit_price=1250
     *
     * A non-PKP Sales line can never carry tax: calculateSubtotalAndTax() force-nulls
     * the tax id when cart_instance is 'sale' and the business is not PKP. So even
     * with an 11% tax selected, the line stays untaxed and the total is pure subtotal.
     * (Previously named "..._with_tax_..." while never assigning a tax at all.)
     */
    public function test_line_total_non_pkp_ignores_tax_and_reverses_correctly()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Tax Context Product',
            'product_code' => 'TAXCTX-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Set quantity to 2
        $component->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        // Tax-exclusive mode, and genuinely attempt to assign 11% tax
        $component->set('is_tax_included', false)
            ->set('product_tax.' . $id, $this->tax11->id)
            ->call('updateTax', $rowId, $id, $this->tax11->id);

        // Set line total to 2500
        // With qty=2 and no effective tax: unit_price = 2500 / 2 = 1250
        $component->set('line_total.' . $id, 2500)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertSame(2500.0, (float) $updatedItem->options->sub_total, 'sub_total should be 2500');
        $this->assertSame(1250.0, (float) $updatedItem->options->unit_price, 'unit_price should be 1250');
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);

        // Non-PKP Sales lines are untaxed regardless of the selected tax
        $this->assertSame(
            0.0,
            (float) $updatedItem->options->product_tax_amount,
            'a non-PKP Sales line must carry no tax'
        );
        $this->assertSame(
            2500.0,
            (float) $updatedItem->options->sub_total_before_tax,
            'pre-tax subtotal equals the full total when untaxed'
        );
        $this->assertSame(
            (float) $updatedItem->options->sub_total,
            (float) $updatedItem->options->sub_total_before_tax + (float) $updatedItem->options->product_tax_amount,
            'sub_total should equal pre-tax subtotal plus tax'
        );
    }

    /**
     * Test case: PKP tax-included, qty=2, requested_total=3000, expected_unit_price=1500
     * With 11% tax included: total = 1500 * 2 = 3000 (tax already in price)
     */
    public function test_line_total_pkp_tax_included_reverses_correctly()
    {
        session(['setting_id' => $this->pkpSetting->id]);

        $product = Product::create([
            'setting_id' => $this->pkpSetting->id,
            'product_name' => 'PKP Tax Included Product',
            'product_code' => 'PKPTAXIN-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->pkpSetting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Set quantity to 2
        $component->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        // Tax-inclusive mode, with 11% assigned. updateTax resolves the tax from
        // its third argument, so it must be passed explicitly.
        $component->set('is_tax_included', true)
            ->set('product_tax.' . $id, $this->tax11->id)
            ->call('updateTax', $rowId, $id, $this->tax11->id);

        // Set line total to 3000 (tax included in this total)
        // unit_price = 3000 / 2 = 1500
        $component->set('line_total.' . $id, 3000)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertSame(3000.0, (float) $updatedItem->options->sub_total, 'sub_total should be 3000');
        $this->assertSame(1500.0, (float) $updatedItem->options->unit_price, 'unit_price should be 1500');
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);

        // Prove the tax was actually applied to the line
        $this->assertSame(
            $this->tax11->id,
            (int) $updatedItem->options->product_tax,
            'the 11% tax id should be stored on the cart line'
        );

        // Tax-inclusive with 11%: ex-tax unit = 1500 / 1.11 = 1351.3513...
        // sub_total_before_tax = 2702.7027..., tax = 297.2972..., sum = 3000
        $this->assertEqualsWithDelta(
            2702.70,
            (float) $updatedItem->options->sub_total_before_tax,
            0.01,
            'pre-tax subtotal should be 3000 / 1.11'
        );
        $this->assertEqualsWithDelta(
            297.30,
            (float) $updatedItem->options->product_tax_amount,
            0.01,
            'tax should be the 11% portion extracted from 3000'
        );
        $this->assertEqualsWithDelta(
            (float) $updatedItem->options->sub_total,
            (float) $updatedItem->options->sub_total_before_tax + (float) $updatedItem->options->product_tax_amount,
            0.01,
            'sub_total should equal pre-tax subtotal plus tax'
        );
    }

    /**
     * Test case: PKP (tax-inclusive) with 11% tax, qty=2, line_total=3330, expected_unit_price=1665
     * This tests line-total reversal in PKP context where tax is included in the price.
     * With is_tax_included=true (PKP default): unit_price = line_total / qty = 3330 / 2 = 1665
     * When calculateSubtotalAndTax runs with tax-inclusive and 11% tax:
     * - price_ex_tax = 1665 / 1.11 ≈ 1500
     * - tax_per_unit ≈ 165
     * - sub_total_before_tax = 1500 * 2 = 3000
     * - tax_amount = 165 * 2 = 330
     * - sub_total (with tax) = 3000 + 330 = 3330
     */
    public function test_line_total_pkp_tax_inclusive_reverses_correctly()
    {
        session(['setting_id' => $this->pkpSetting->id]);

        $product = Product::create([
            'setting_id' => $this->pkpSetting->id,
            'product_name' => 'PKP Tax Inclusive Line Total Product',
            'product_code' => 'PKPLINEINC-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->pkpSetting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Set quantity to 2
        $component->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        // Tax-inclusive mode (the PKP default, set explicitly here), with 11%
        // assigned. updateTax resolves the tax from its third argument.
        $component->set('is_tax_included', true)
            ->set('product_tax.' . $id, $this->tax11->id)
            ->call('updateTax', $rowId, $id, $this->tax11->id);

        // Set line total to 3330 (total with tax included)
        $component->set('line_total.' . $id, 3330)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        // With tax-inclusive mode: line_total of 3330 / qty of 2 = unit_price 1665 (with tax)
        $this->assertSame(3330.0, (float) $updatedItem->options->sub_total, 'sub_total should be 3330 (total with tax)');
        $this->assertSame(1665.0, (float) $updatedItem->options->unit_price, 'unit_price should be 1665 (total / qty, with tax included)');
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);

        // Verify line total calculation is consistent with qty and unit_price
        $calculated_sub_total = (float) $updatedItem->options->unit_price * (int) $updatedItem->qty;
        $this->assertSame(3330.0, (float) $calculated_sub_total, 'sub_total should equal unit_price * qty');

        // Prove the tax was actually applied to the line
        $this->assertSame(
            $this->tax11->id,
            (int) $updatedItem->options->product_tax,
            'the 11% tax id should be stored on the cart line'
        );

        // Tax-inclusive with 11%: ex-tax unit = 1665 / 1.11 = 1500 exactly
        $this->assertEqualsWithDelta(
            3000.0,
            (float) $updatedItem->options->sub_total_before_tax,
            0.01,
            'pre-tax subtotal should be 3000'
        );
        $this->assertEqualsWithDelta(
            330.0,
            (float) $updatedItem->options->product_tax_amount,
            0.01,
            'tax should be 330'
        );
        $this->assertEqualsWithDelta(
            (float) $updatedItem->options->sub_total,
            (float) $updatedItem->options->sub_total_before_tax + (float) $updatedItem->options->product_tax_amount,
            0.01,
            'sub_total should equal pre-tax subtotal plus tax'
        );
    }

    /**
     * Test case: non-PKP in tax-included mode, qty=2, requested_total=3330, expected_unit_price=1665
     *
     * Tax-included mode alone does not make a non-PKP Sales line taxable: the tax id
     * is force-nulled for non-PKP sale carts, so no tax is extracted and the entire
     * 3330 remains pre-tax subtotal.
     * (Previously named "..._tax_included_..." while never assigning a tax at all.)
     */
    public function test_line_total_non_pkp_tax_included_mode_extracts_no_tax()
    {
        // Use non-PKP setting (non-PKP defaults to tax-included = false, but we can override)
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Tax Included Line Total Product',
            'product_code' => 'TAXINCLUDE-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);

        $component->call('productSelected', [
            'id' => $product->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_quantity' => 100,
            'product_unit' => 'pcs',
        ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Set quantity to 2
        $component->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        // Tax-included mode, and genuinely attempt to assign 11% tax
        $component->set('is_tax_included', true)
            ->set('product_tax.' . $id, $this->tax11->id)
            ->call('updateTax', $rowId, $id, $this->tax11->id);

        // Set line total to 3330 with tax-included mode
        // effective_price = 3330 / 2 = 1665
        $component->set('line_total.' . $id, 3330)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        // With tax-included mode: sub_total = unit_price * qty
        $this->assertSame(3330.0, (float) $updatedItem->options->sub_total, 'sub_total should be 3330');
        $this->assertSame(1665.0, (float) $updatedItem->options->unit_price, 'unit_price should be 1665');
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);

        // No tax is extracted for a non-PKP Sales line, even in tax-included mode
        $this->assertSame(
            0.0,
            (float) $updatedItem->options->product_tax_amount,
            'a non-PKP Sales line must carry no tax'
        );
        $this->assertSame(
            3330.0,
            (float) $updatedItem->options->sub_total_before_tax,
            'the whole total stays pre-tax when no tax is extracted'
        );
        $this->assertSame(
            (float) $updatedItem->options->sub_total,
            (float) $updatedItem->options->sub_total_before_tax + (float) $updatedItem->options->product_tax_amount,
            'sub_total should equal pre-tax subtotal plus tax'
        );
    }

    /**
     * Test case: Fixed line discount, qty=2, fixed discount=200 per unit, requested_total=2000
     * With fixed discount: 2000 = unit_price * 2 - 200*2 => unit_price = 1200
     * Verify that updateLineTotal correctly reverses the discount when calculating base price
     */
    public function test_line_total_with_fixed_discount_reverses_correctly()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Fixed Discount Product',
            'product_code' => 'FIXDISC-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Set quantity to 2
        $component->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        // Set fixed discount of 200 per unit
        $component->set('discount_type.' . $id, 'fixed')
            ->set('item_discount.' . $id, 200)
            ->call('setDiscountType', $rowId, $id, 'fixed');

        // Set line total to 2000
        // With qty=2 and 200 per unit discount:
        // effective_price = 2000 / 2 = 1000
        // base_price = 1000 + 200 = 1200
        $component->set('line_total.' . $id, 2000)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertSame(2000.0, (float) $updatedItem->options->sub_total, 'sub_total should be 2000');
        $this->assertSame(1200.0, (float) $updatedItem->options->unit_price, 'unit_price should be 1200');
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);
    }

    /**
     * Test case: line total reverses calculation with quantity=3 and no discounts, qty=3, requested_total=4500, expected_unit_price=1500
     * Verify reverse calculation works with different quantities
     */
    public function test_line_total_with_quantity_3_reverses_correctly()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Quantity Test Product',
            'product_code' => 'QTRYTEST-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Set quantity to 3
        $component->set('quantity.' . $id, 3)
            ->call('updateQuantity', $rowId, $id);

        // Set line total to 4500
        // Expected: unit_price = 4500 / 3 = 1500
        $component->set('line_total.' . $id, 4500)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertSame(4500.0, (float) $updatedItem->options->sub_total, 'sub_total should be 4500');
        $this->assertSame(1500.0, (float) $updatedItem->options->unit_price, 'unit_price should be 1500');
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);
    }

    /**
     * Test case: 20% percentage discount, qty=2, requested_total=2000, expected_unit_price=1250
     * With 20% discount: 2000 = unit_price * 2 * (1 - 0.20) => unit_price = 1250
     */
    public function test_line_total_with_percentage_discount_reverses_correctly()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Percentage Discount Product',
            'product_code' => 'PERCDISC-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Set quantity to 2
        $component->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        // Set 20% percentage discount
        $component->set('discount_type.' . $id, 'percentage')
            ->set('item_discount.' . $id, 20);

        // Set line total to 2000
        $component->set('line_total.' . $id, 2000)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertSame(2000.0, (float) $updatedItem->options->sub_total, 'sub_total should be 2000');
        $this->assertSame(1250.0, (float) $updatedItem->options->unit_price, 'unit_price should be 1250');
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);
    }

    // ==================== INVALID INPUT HANDLING TESTS ====================

    /**
     * Test that blank line total is rejected
     */
    public function test_line_total_blank_input_rejected_and_restored()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Blank Input Test',
            'product_code' => 'BLANK-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;
        $originalSubTotal = (float) $cartItem->options->sub_total;
        $originalUnitPrice = (float) $cartItem->price;

        // Try to set blank line total
        $component->set('line_total.' . $id, '')
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();
        // Blank value should cause rejection and restoration
        $this->assertSame($originalSubTotal, (float) $updatedItem->options->sub_total, 'sub_total should be restored to original');
        $this->assertSame($originalUnitPrice, (float) $updatedItem->price, 'unit_price should be restored to original');
    }

    /**
     * Test that nonnumeric line total is rejected
     */
    public function test_line_total_nonnumeric_input_rejected_and_restored()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Nonnumeric Test',
            'product_code' => 'NONNUMERIC-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;
        $originalSubTotal = (float) $cartItem->options->sub_total;
        $originalUnitPrice = (float) $cartItem->price;

        // Try to set nonnumeric value
        $component->set('line_total.' . $id, 'abc')
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();
        // Nonnumeric value should cause rejection and restoration
        $this->assertSame($originalSubTotal, (float) $updatedItem->options->sub_total, 'sub_total should be restored to original');
        $this->assertSame($originalUnitPrice, (float) $updatedItem->price, 'unit_price should be restored to original');
    }

    /**
     * Test that negative line total is rejected
     */
    public function test_line_total_negative_input_rejected_and_restored()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Negative Test',
            'product_code' => 'NEGATIVE-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;
        $originalSubTotal = (float) $cartItem->options->sub_total;
        $originalUnitPrice = (float) $cartItem->price;

        // Try to set negative value
        $component->set('line_total.' . $id, -1000)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();
        // Negative value should cause rejection and restoration
        $this->assertSame($originalSubTotal, (float) $updatedItem->options->sub_total, 'sub_total should be restored to original');
        $this->assertSame($originalUnitPrice, (float) $updatedItem->price, 'unit_price should be restored to original');
    }

    /**
     * Test that 100% discount with non-zero total is rejected
     */
    public function test_line_total_100_percent_discount_with_nonzero_total_rejected()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => '100% Discount Test',
            'product_code' => 'FULL-DISC-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Set quantity to 2
        $component->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        // Set 100% discount
        $component->set('discount_type.' . $id, 'percentage')
            ->set('item_discount.' . $id, 100);

        $cartItem = Cart::instance('sale')->content()->first();
        $originalSubTotal = (float) $cartItem->options->sub_total;
        $originalUnitPrice = (float) $cartItem->price;

        // Try to set non-zero total with 100% discount
        $component->set('line_total.' . $id, 1000)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();
        // 100% discount with non-zero total should be rejected and restored
        $this->assertSame($originalSubTotal, (float) $updatedItem->options->sub_total, 'sub_total should be restored');
        $this->assertSame($originalUnitPrice, (float) $updatedItem->price, 'unit_price should be restored');
    }

    // ==================== MISSING PRICE NOTIFICATION TESTS ====================

    /**
     * Test: One missing target-business ProductPrice row
     * - Assert zero automatic price
     * - Verify that cart is updated when ProductPrice is missing
     */
    public function test_business_switch_missing_single_product_price_sets_zero()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Missing Price Product',
            'product_code' => 'MISSINGONE-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        // Add product to cart in current business
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $this->assertSame(2000.0, (float) $cartItem->price, 'Initial price from current business');

        // Switch to business with NO product price
        $component->instance()->handleBusinessContextChanged($this->pkpSetting->id);

        $updatedItem = Cart::instance('sale')->content()->first();

        // Price should be zero for automatic line when ProductPrice missing
        $this->assertSame(0.0, (float) $updatedItem->price, 'Price should be zero when ProductPrice missing');
        $this->assertSame('automatic', $updatedItem->options->pricing_source, 'Should still be automatic');

        // The flashed notification must name the missing product and the target business
        $message = session('message');
        $this->assertIsString($message, 'A missing-price notification should be flashed');
        $this->assertStringContainsString('MISSING PRICE PRODUCT', $message);
        $this->assertStringContainsString('PKP BUSINESS', $message);
        $this->assertStringContainsString('Unit price diatur ke 0.', $message);
    }

    /**
     * Test: Two missing ProductPrice rows in one business switch
     * - Verify both products get zero price when switching to business with no prices
     */
    public function test_business_switch_missing_multiple_products_all_set_to_zero()
    {
        $product1 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Missing Price Product A',
            'product_code' => 'MISSINGMULTI-A',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        $product2 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Missing Price Product B',
            'product_code' => 'MISSINGMULTI-B',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product1->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        ProductPrice::create([
            'product_id' => $product2->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        // Add both products to cart
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);
        $component->call('productSelected', [
            'id' => $product1->id,
            'product_id' => $product1->id,
            'product_name' => $product1->product_name,
            'product_code' => $product1->product_code,
            'product_quantity' => 100,
            'product_unit' => 'pcs',
        ]);

        $component->call('productSelected', [
            'id' => $product2->id,
            'product_id' => $product2->id,
            'product_name' => $product2->product_name,
            'product_code' => $product2->product_code,
            'product_quantity' => 100,
            'product_unit' => 'pcs',
        ]);

        // Switch to business with NO prices for either product
        $component->instance()->handleBusinessContextChanged($this->pkpSetting->id);

        $cartItems = Cart::instance('sale')->content();
        $this->assertCount(2, $cartItems, 'Should have 2 items in cart');

        // Both should have zero price when ProductPrice is missing
        foreach ($cartItems as $item) {
            $this->assertSame(0.0, (float) $item->price, "Price should be zero when ProductPrice missing for {$item->name}");
        }

        // One consolidated notification names both products and the target business
        $message = session('message');
        $this->assertIsString($message, 'A consolidated missing-price notification should be flashed');
        $this->assertStringContainsString('MISSING PRICE PRODUCT A', $message);
        $this->assertStringContainsString('MISSING PRICE PRODUCT B', $message);
        $this->assertStringContainsString('PKP BUSINESS', $message);
        $this->assertStringContainsString('Unit price diatur ke 0.', $message);
    }

    /**
     * Test: Existing target-business ProductPrice row with sale_price = 0
     * - Assert automatic zero price is used (not treated as missing)
     */
    public function test_business_switch_zero_sale_price_not_treated_as_missing()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Zero Price Product',
            'product_code' => 'ZEROPRICE-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        // Create PKP ProductPrice with zero sale_price (explicit zero, not missing)
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->pkpSetting->id,
            'sale_price' => 0,  // Explicitly zero, not missing
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        // Add product to cart in current business
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        // Switch to PKP business that has a zero-priced ProductPrice
        $component->instance()->handleBusinessContextChanged($this->pkpSetting->id);

        $updatedItem = Cart::instance('sale')->content()->first();

        // Price should be zero from the explicit ProductPrice row (not treated as missing)
        $this->assertSame(0.0, (float) $updatedItem->price, 'Price should be zero from explicit ProductPrice');
        $this->assertSame('automatic', $updatedItem->options->pricing_source, 'Should remain automatic');

        // An explicit zero-priced ProductPrice is not "missing" — no notification at all
        $this->assertNull(
            session('message'),
            'No missing-price notification should be flashed when an explicit ProductPrice row exists'
        );
    }

    // ==================== PKP TAX-EXCLUSIVE LINE TOTAL TEST ====================

    /**
     * Test case: PKP business, 11% tax, is_tax_included explicitly false, qty=2, Total Baris=3330.
     * In tax-exclusive mode the entered line total is the pre-tax subtotal basis:
     * - unit_price = 3330 / 2 ... reversed against the tax-exclusive rules to 1500
     * - pre-tax subtotal = 1500 * 2 = 3000
     * - tax = 3000 * 11% = 330
     * - final total = 3000 + 330 = 3330
     */
    public function test_line_total_pkp_tax_exclusive_reverses_correctly()
    {
        session(['setting_id' => $this->pkpSetting->id]);

        $product = Product::create([
            'setting_id' => $this->pkpSetting->id,
            'product_name' => 'PKP Tax Exclusive Line Total Product',
            'product_code' => 'PKPLINEEXC-001',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->pkpSetting->id,
            'sale_price' => 2000,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        $component->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        // Explicitly tax-exclusive, then assign 11% tax in the same component state.
        // updateTax reads the tax from its third argument, so pass it explicitly.
        $component->set('is_tax_included', false)
            ->set('product_tax.' . $id, $this->tax11->id)
            ->call('updateTax', $rowId, $id, $this->tax11->id);

        // Total Baris = 3330
        $component->set('line_total.' . $id, 3330)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);
        $this->assertSame(1500.0, (float) $updatedItem->options->unit_price, 'unit_price should be 1500');

        // Prove the tax was actually applied to the line
        $this->assertSame(
            $this->tax11->id,
            (int) $updatedItem->options->product_tax,
            'the 11% tax id should be stored on the cart line'
        );

        // Tax and totals must be internally consistent
        $this->assertSame(3000.0, (float) $updatedItem->options->sub_total_before_tax, 'pre-tax subtotal should be 3000');
        $this->assertSame(330.0, (float) $updatedItem->options->product_tax_amount, 'tax should be 330');
        $this->assertSame(3330.0, (float) $updatedItem->options->sub_total, 'final total should be 3330');
        $this->assertSame(
            (float) $updatedItem->options->sub_total,
            (float) $updatedItem->options->sub_total_before_tax + (float) $updatedItem->options->product_tax_amount,
            'final total should equal pre-tax subtotal plus tax'
        );
    }
}

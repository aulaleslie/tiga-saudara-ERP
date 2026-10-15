<?php

namespace Tests\Feature;

use App\Livewire\Purchase\EditForm;
use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Services\PurchaseNormalizer;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseRowDiscountConsistencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private PaymentTerm $paymentTerm;
    private Unit $pcsUnit;
    private Unit $boxUnit;
    private Product $product;
    private ProductUnitConversion $conversion;

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
            'company_name' => 'Test Setting',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        session(['setting_id' => $this->setting->id]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Supplier Test',
            'supplier_email' => 'supplier@test.local',
            'supplier_phone' => '08123456789',
            'address' => 'Jl. Test No. 1',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'is_active' => true,
        ]);

        $this->paymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'is_active' => true,
        ]);

        $this->pcsUnit = Unit::create(['name' => 'PCS', 'short_name' => 'PCS', 'is_active' => true]);
        $this->boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'BOX', 'is_active' => true]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Product Box',
            'product_code' => 'PB-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 100,
            'product_price' => 1200,
            'product_cost' => 1000,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 1000,
        ]);

        $this->conversion = ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_normalizer_persists_fixed_discount_per_entered_unit_converted_once(): void
    {
        $normalizer = app(PurchaseNormalizer::class);

        // Input coming from cart row with 1 BOX, entered price 12,000, fixed discount 1,200 per BOX
        $detailInput = [
            'id' => $this->product->id,
            'product_id' => $this->product->id,
            'qty' => 1.0,
            'price' => 12000.0,
            'options' => [
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $this->conversion->id,
                'conversion_factor' => 12.0,
                'entered_quantity' => 1.0,
                'entered_unit_price' => 12000.0,
                'entered_product_discount_amount' => 1200.0,
                'product_discount_input' => 1200.0,
                'product_discount_type' => 'fixed',
                'product_discount' => 1200.0,
                'sub_total' => 10800.0,
                'sub_total_before_tax' => 10800.0,
                'product_tax_amount' => 0.0,
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ];

        $result = $normalizer->normalize([], [$detailInput], false, $this->setting->id);
        $normalized = $result['details'][0];

        $this->assertEquals(12.0, (float) $normalized['quantity']);
        $this->assertEquals(1.0, (float) $normalized['entered_quantity']);
        $this->assertEquals(1000.0, (float) $normalized['unit_price']);
        $this->assertEquals(12000.0, (float) $normalized['entered_unit_price']);
        // Canonical discount must be Rp100 per PCS (1200 / 12)
        $this->assertEquals(100.0, (float) $normalized['product_discount_amount']);
        // Entered discount must remain Rp1,200 per BOX
        $this->assertEquals(1200.0, (float) $normalized['entered_product_discount_amount']);
        $this->assertEquals(10800.0, (float) $normalized['sub_total']);
    }

    public function test_normalizer_persists_percentage_discount_and_retains_entered_percentage(): void
    {
        $normalizer = app(PurchaseNormalizer::class);

        $detailInput = [
            'id' => $this->product->id,
            'product_id' => $this->product->id,
            'qty' => 1.0,
            'price' => 12000.0,
            'options' => [
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $this->conversion->id,
                'conversion_factor' => 12.0,
                'entered_quantity' => 1.0,
                'entered_unit_price' => 12000.0,
                'entered_product_discount_amount' => 10.0,
                'product_discount_input' => 10.0,
                'product_discount_type' => 'percentage',
                'product_discount' => 1200.0, // 10% of 12,000
                'sub_total' => 10800.0,
                'sub_total_before_tax' => 10800.0,
                'product_tax_amount' => 0.0,
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ];

        $result = $normalizer->normalize([], [$detailInput], false, $this->setting->id);
        $normalized = $result['details'][0];

        $this->assertEquals(100.0, (float) $normalized['product_discount_amount']); // 10% of 1,000 canonical price
        $this->assertEquals(10.0, (float) $normalized['entered_product_discount_amount']); // 10%
        $this->assertEquals('percentage', $normalized['product_discount_type']);
        $this->assertEquals(10800.0, (float) $normalized['sub_total']);
    }

    public function test_normalizer_persists_explicit_zero_discount(): void
    {
        $normalizer = app(PurchaseNormalizer::class);

        $detailInput = [
            'id' => $this->product->id,
            'product_id' => $this->product->id,
            'qty' => 1.0,
            'price' => 12000.0,
            'options' => [
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $this->conversion->id,
                'conversion_factor' => 12.0,
                'entered_quantity' => 1.0,
                'entered_unit_price' => 12000.0,
                'entered_product_discount_amount' => 0.0,
                'product_discount_input' => 0.0,
                'product_discount_type' => 'fixed',
                'product_discount' => 0.0,
                'sub_total' => 12000.0,
                'sub_total_before_tax' => 12000.0,
                'product_tax_amount' => 0.0,
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ];

        $result = $normalizer->normalize([], [$detailInput], false, $this->setting->id);
        $normalized = $result['details'][0];

        $this->assertEquals(0.0, (float) $normalized['product_discount_amount']);
        $this->assertEquals(0.0, (float) $normalized['entered_product_discount_amount']);
        $this->assertEquals(12000.0, (float) $normalized['sub_total']);
    }

    public function test_normalizer_derives_entered_discount_for_legacy_fixed_discount_lacking_snapshot(): void
    {
        $normalizer = app(PurchaseNormalizer::class);

        // Legacy input where entered snapshot is absent and only canonical product_discount_amount exists
        $detailInput = [
            'id' => $this->product->id,
            'product_id' => $this->product->id,
            'qty' => 1.0,
            'price' => 12000.0,
            'product_discount_amount' => 100.0, // Canonical Rp 100 / PCS
            'product_discount_type' => 'fixed',
            'options' => [
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $this->conversion->id,
                'conversion_factor' => 12.0,
                'entered_quantity' => 1.0,
                'entered_unit_price' => 12000.0,
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ];

        $result = $normalizer->normalize([], [$detailInput], false, $this->setting->id);
        $normalized = $result['details'][0];

        $this->assertEquals(100.0, (float) $normalized['product_discount_amount']);
        $this->assertEquals(1200.0, (float) $normalized['entered_product_discount_amount']); // Derived: 100 * 12
        $this->assertEquals(10800.0, (float) $normalized['sub_total']);
    }

    public function test_normalizer_derives_entered_percentage_for_legacy_percentage_discount_lacking_snapshot(): void
    {
        $normalizer = app(PurchaseNormalizer::class);

        $detailInput = [
            'id' => $this->product->id,
            'product_id' => $this->product->id,
            'qty' => 1.0,
            'price' => 12000.0,
            'product_discount_amount' => 100.0, // Canonical Rp 100 / PCS on Rp 1,000 / PCS canonical price
            'product_discount_type' => 'percentage',
            'options' => [
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $this->conversion->id,
                'conversion_factor' => 12.0,
                'entered_quantity' => 1.0,
                'entered_unit_price' => 12000.0,
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ];

        $result = $normalizer->normalize([], [$detailInput], false, $this->setting->id);
        $normalized = $result['details'][0];

        $this->assertEquals(100.0, (float) $normalized['product_discount_amount']);
        $this->assertEquals(10.0, (float) $normalized['entered_product_discount_amount']); // Derived: (100 / 1000) * 100 = 10%
        $this->assertEquals('percentage', $normalized['product_discount_type']);
        $this->assertEquals(10800.0, (float) $normalized['sub_total']);
    }

    public function test_edit_form_hydrates_converted_unit_with_fixed_discount_in_entered_units(): void
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-TEST-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'total_amount' => 10800.0,
            'paid_amount' => 0,
            'due_amount' => 10800.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $this->conversion->id,
            'quantity' => 12.0,
            'entered_quantity' => 1.0,
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 100.0, // Rp 100 per PCS
            'entered_product_discount_amount' => 1200.0, // Rp 1,200 per BOX
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12.0,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
            'sub_total' => 10800.0,
            'product_tax_amount' => 0.0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'pricing_source' => 'manual',
        ]);

        // Mount EditForm which calls restoreCart()
        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertNotNull($cartItem);

        // Cart options must hold entered-unit discount (Rp 1,200), not canonical (Rp 100)
        $this->assertEquals(1200.0, (float) $cartItem->options->entered_product_discount_amount);
        $this->assertEquals(1200.0, (float) $cartItem->options->product_discount);
        $this->assertEquals(1200.0, (float) $cartItem->options->product_discount_input);

        // ProductCart component mount must have item_discount = 1200.0
        $productCart = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase', 'data' => $purchase]);
        $productCart->assertSet('item_discount.' . $cartItem->rowId, 1200.0);
        $productCart->assertSet('discount_type.' . $cartItem->rowId, 'fixed');
    }

    public function test_edit_form_hydrates_legacy_fixed_discount_lacking_entered_snapshot(): void
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-TEST-002',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'total_amount' => 10800.0,
            'paid_amount' => 0,
            'due_amount' => 10800.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $this->conversion->id,
            'quantity' => 12.0,
            'entered_quantity' => 1.0,
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 100.0, // Rp 100 per PCS
            'entered_product_discount_amount' => null, // Legacy: snapshot absent
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12.0,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
            'sub_total' => 10800.0,
            'product_tax_amount' => 0.0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'pricing_source' => 'manual',
        ]);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertNotNull($cartItem);

        // When entered snapshot is null, fixed entered discount must be derived: 100 * 12 = 1200
        $this->assertEquals(1200.0, (float) $cartItem->options->product_discount);
        $this->assertEquals(1200.0, (float) $cartItem->options->product_discount_input);

        $productCart = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase', 'data' => $purchase]);
        $productCart->assertSet('item_discount.' . $cartItem->rowId, 1200.0);
    }

    public function test_edit_form_preserves_explicit_zero_discount(): void
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-TEST-003',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'total_amount' => 12000.0,
            'paid_amount' => 0,
            'due_amount' => 12000.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $this->conversion->id,
            'quantity' => 12.0,
            'entered_quantity' => 1.0,
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 0.0,
            'entered_product_discount_amount' => 0.0, // Explicit zero
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12.0,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
            'sub_total' => 12000.0,
            'product_tax_amount' => 0.0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'pricing_source' => 'manual',
        ]);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(0.0, (float) $cartItem->options->product_discount);
        $this->assertEquals(0.0, (float) $cartItem->options->product_discount_input);
        $this->assertEquals(0.0, (float) $cartItem->options->entered_product_discount_amount);

        $productCart = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase', 'data' => $purchase]);
        $productCart->assertSet('item_discount.' . $cartItem->rowId, 0.0);
    }

    public function test_saving_unchanged_purchase_preserves_stored_subtotal_and_canonical_discount(): void
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-TEST-SAVE-001',
            'supplier_id' => $this->supplier->id,
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_DRAFTED,
            'total_amount' => 10800.0,
            'paid_amount' => 0,
            'due_amount' => 10800.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $this->conversion->id,
            'quantity' => 12.0,
            'entered_quantity' => 1.0,
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 100.0,
            'entered_product_discount_amount' => 1200.0,
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12.0,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
            'sub_total' => 10800.0,
            'product_tax_amount' => 0.0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'pricing_source' => 'automatic',
        ]);

        // Mount and save without changing rows
        $editForm = Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);
        $editForm->call('submit');

        $freshDetail = PurchaseDetail::where('purchase_id', $purchase->id)->first();
        $this->assertEquals(10800.0, (float) $freshDetail->sub_total);
        $this->assertEquals(100.0, (float) $freshDetail->product_discount_amount);
        $this->assertEquals(1200.0, (float) $freshDetail->entered_product_discount_amount);
    }

    public function test_changing_discount_replaces_snapshot_and_recalculates(): void
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-TEST-CHG-001',
            'supplier_id' => $this->supplier->id,
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_DRAFTED,
            'total_amount' => 10800.0,
            'paid_amount' => 0,
            'due_amount' => 10800.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $this->conversion->id,
            'quantity' => 12.0,
            'entered_quantity' => 1.0,
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 100.0,
            'entered_product_discount_amount' => 1200.0,
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12.0,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
            'sub_total' => 10800.0,
            'product_tax_amount' => 0.0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'pricing_source' => 'automatic',
        ]);

        // Mount EditForm
        $editForm = Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);
        $productCart = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase', 'data' => $purchase]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Change discount to 0 via ProductCart
        $productCart->set('item_discount.' . $rowId, 0);
        $productCart->call('setProductDiscount', $rowId, $this->product->id);

        $editForm->call('submit');

        $freshDetail = PurchaseDetail::where('purchase_id', $purchase->id)->first();
        $this->assertEquals(0.0, (float) $freshDetail->product_discount_amount);
        $this->assertEquals(0.0, (float) $freshDetail->entered_product_discount_amount);
        $this->assertEquals(12000.0, (float) $freshDetail->sub_total);
    }

    public function test_duplicate_prefills_cart_with_entered_unit_discount(): void
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-TEST-DUP-001',
            'supplier_id' => $this->supplier->id,
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_APPROVED,
            'total_amount' => 10800.0,
            'paid_amount' => 0,
            'due_amount' => 10800.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $this->conversion->id,
            'quantity' => 12.0,
            'entered_quantity' => 1.0,
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 100.0,
            'entered_product_discount_amount' => 1200.0,
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12.0,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
            'sub_total' => 10800.0,
            'product_tax_amount' => 0.0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'pricing_source' => 'automatic',
        ]);

        Livewire::test(\App\Livewire\Purchase\CreateForm::class, [
            'idempotencyToken' => 'test-dup-token',
            'duplicateId' => $purchase->id,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertNotNull($cartItem);
        $this->assertEquals(1200.0, (float) $cartItem->options->product_discount);
        $this->assertEquals(1200.0, (float) $cartItem->options->product_discount_input);
        $this->assertEquals(1200.0, (float) $cartItem->options->entered_product_discount_amount);
        $this->assertEquals(10800.0, (float) $cartItem->options->sub_total);
    }

    public function test_fractional_quantity_discounted_box_preserves_subtotal_and_canonical_quantity(): void
    {
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);

        $component->call('productSelected', [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'product_quantity' => $this->product->product_quantity,
            'product_unit' => $this->product->product_unit,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Change unit to BOX (factor 12)
        $component->call('updateUnit', $rowId, 'conv_' . $this->conversion->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Set price to 12000
        $component->set('unit_price.' . $rowId, 12000);
        $component->call('updatePrice', $rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Set discount to 1200 fixed
        $component->set('item_discount.' . $rowId, 1200);
        $component->call('setProductDiscount', $rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Update quantity to 0.500 BOX
        $component->call('updateQuantityDirect', $rowId, $this->product->id, 0.5);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(0.5, (float) $cartItem->qty);
        // Subtotal must be (12000 - 1200) * 0.5 = 5400
        $this->assertEquals(5400.0, (float) $cartItem->options->sub_total);
        $this->assertEquals(5400.0, (float) $cartItem->options->sub_total_before_tax);

        // Normalize
        $normalizer = app(PurchaseNormalizer::class);
        $normalized = $normalizer->normalize([], [$cartItem], false, $this->setting->id);
        $detail = $normalized['details'][0];

        $this->assertEquals(6.0, (float) $detail['quantity']); // 0.5 * 12 = 6 PCS
        $this->assertEquals(0.5, (float) $detail['entered_quantity']);
        $this->assertEquals(1000.0, (float) $detail['unit_price']);
        $this->assertEquals(12000.0, (float) $detail['entered_unit_price']);
        $this->assertEquals(100.0, (float) $detail['product_discount_amount']);
        $this->assertEquals(1200.0, (float) $detail['entered_product_discount_amount']);
        $this->assertEquals(5400.0, (float) $detail['sub_total']);
    }

    public function test_purchase_detail_discount_presentation_helpers(): void
    {
        // 1. Percentage discount
        $detailPercentage = new PurchaseDetail([
            'product_discount_type' => 'percentage',
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 10000.0,
            'product_discount_amount' => 100.0,
            'entered_product_discount_amount' => 10.0,
            'conversion_factor' => 10.0,
        ]);

        $this->assertTrue($detailPercentage->isPercentageDiscount());
        $this->assertEquals(10.0, $detailPercentage->resolveEnteredDiscountInput());
        $this->assertEquals(1000.0, $detailPercentage->resolveEnteredCurrencyDiscount());
        $this->assertEquals(100.0, $detailPercentage->resolveCanonicalCurrencyDiscount());

        // Case insensitivity: 'PERCENTAGE'
        $detailUpper = new PurchaseDetail([
            'product_discount_type' => 'PERCENTAGE',
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 10000.0,
            'product_discount_amount' => 100.0,
            'entered_product_discount_amount' => 10.0,
        ]);
        $this->assertTrue($detailUpper->isPercentageDiscount());

        // 2. Fixed discount with conversion
        $detailFixed = new PurchaseDetail([
            'product_discount_type' => 'fixed',
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 100.0,
            'entered_product_discount_amount' => 1200.0,
            'conversion_factor' => 12.0,
        ]);

        $this->assertFalse($detailFixed->isPercentageDiscount());
        $this->assertEquals(1200.0, $detailFixed->resolveEnteredDiscountInput());
        $this->assertEquals(1200.0, $detailFixed->resolveEnteredCurrencyDiscount());
        $this->assertEquals(100.0, $detailFixed->resolveCanonicalCurrencyDiscount());

        // 3. Explicit zero snapshot
        $detailZeroFixed = new PurchaseDetail([
            'product_discount_type' => 'fixed',
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 0.0,
            'entered_product_discount_amount' => 0.0,
            'conversion_factor' => 12.0,
        ]);
        $this->assertEquals(0.0, $detailZeroFixed->resolveEnteredDiscountInput());
        $this->assertEquals(0.0, $detailZeroFixed->resolveEnteredCurrencyDiscount());

        $detailZeroPercent = new PurchaseDetail([
            'product_discount_type' => 'percentage',
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 10000.0,
            'product_discount_amount' => 0.0,
            'entered_product_discount_amount' => 0.0,
        ]);
        $this->assertEquals(0.0, $detailZeroPercent->resolveEnteredDiscountInput());
        $this->assertEquals(0.0, $detailZeroPercent->resolveEnteredCurrencyDiscount());

        // 4. Absent legacy snapshots (derivation without DB rewrite)
        $detailLegacyFixed = new PurchaseDetail([
            'product_discount_type' => 'fixed',
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 100.0,
            'entered_product_discount_amount' => null,
            'conversion_factor' => 12.0,
        ]);
        $this->assertEquals(1200.0, $detailLegacyFixed->resolveEnteredDiscountInput());
        $this->assertEquals(1200.0, $detailLegacyFixed->resolveEnteredCurrencyDiscount());

        $detailLegacyPercent = new PurchaseDetail([
            'product_discount_type' => 'percentage',
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 10000.0,
            'product_discount_amount' => 100.0,
            'entered_product_discount_amount' => null,
            'conversion_factor' => 10.0,
        ]);
        $this->assertEquals(10.0, $detailLegacyPercent->resolveEnteredDiscountInput());
        $this->assertEquals(1000.0, $detailLegacyPercent->resolveEnteredCurrencyDiscount());
    }

    public function test_purchase_details_and_print_discount_and_dpp_rendering(): void
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-TEST-VIEW-001',
            'supplier_id' => $this->supplier->id,
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_DRAFTED,
            'total_amount' => 11988.0,
            'paid_amount' => 0,
            'due_amount' => 11988.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
        ]);

        // Detail 1: Fixed discount per BOX with tax excluded
        // Entered: 1 BOX @ Rp 12,000, discount Rp 1,200/BOX -> DPP 10,800, tax 11% = 1,188, subtotal = 11,988
        $detailFixed = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'purchase_unit_id' => $this->boxUnit->id,
            'product_unit_conversion_id' => $this->conversion->id,
            'quantity' => 12.0,
            'entered_quantity' => 1.0,
            'price' => 1000.0,
            'unit_price' => 1000.0,
            'entered_unit_price' => 12000.0,
            'product_discount_amount' => 100.0,
            'entered_product_discount_amount' => 1200.0,
            'product_discount_type' => 'fixed',
            'conversion_factor' => 12.0,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
            'product_tax_amount' => 1188.0,
            'sub_total' => 11988.0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'pricing_source' => 'manual',
        ]);

        $purchase->load(['purchaseDetails.purchaseUnit', 'purchaseDetails.product.baseUnit', 'supplier']);

        // Test show view rendering
        $showHtml = view('purchase::show', [
            'purchase' => $purchase,
            'supplier' => $purchase->supplier,
            'receivedNotes' => collect([]),
            'normBatches' => collect([]),
        ])->render();

        // 1. Shows Rp 1,200 entered discount and canonical Rp 100 / PCS
        $this->assertStringContainsString(formatRupiah(1200.0), $showHtml);
        $this->assertStringContainsString(formatRupiah(100.0) . ' / PCS', $showHtml);

        // 2. DPP must be sub_total - product_tax_amount = 11,988 - 1,188 = 10,800 (NOT 9,600!)
        $this->assertStringContainsString(formatRupiah(10800.0), $showHtml);

        // Test print view rendering
        $printHtml = view('purchase::print', [
            'purchase' => $purchase,
            'supplier' => $purchase->supplier,
            'details' => $purchase->purchaseDetails,
        ])->render();

        // In print view, fixed discount has '-' for percentage and '1.200' for discount value
        $this->assertStringContainsString('1.200', $printHtml);

        // Now test percentage row rendering: 10% on entered price Rp 10,000
        $purchasePercent = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-TEST-VIEW-002',
            'supplier_id' => $this->supplier->id,
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_DRAFTED,
            'total_amount' => 9000.0,
            'paid_amount' => 0,
            'due_amount' => 9000.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
        ]);

        $detailPercent = PurchaseDetail::create([
            'purchase_id' => $purchasePercent->id,
            'product_id' => $this->product->id,
            'purchase_unit_id' => $this->product->unit_id,
            'quantity' => 1.0,
            'entered_quantity' => 1.0,
            'price' => 10000.0,
            'unit_price' => 10000.0,
            'entered_unit_price' => 10000.0,
            'product_discount_amount' => 1000.0,
            'entered_product_discount_amount' => 10.0, // 10%
            'product_discount_type' => 'percentage',
            'conversion_factor' => 1.0,
            'unit_name' => 'PCS',
            'base_unit_name' => 'PCS',
            'product_tax_amount' => 0.0,
            'sub_total' => 9000.0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'pricing_source' => 'manual',
        ]);

        $purchasePercent->load(['purchaseDetails.purchaseUnit', 'purchaseDetails.product.baseUnit', 'supplier']);

        $showPercentHtml = view('purchase::show', [
            'purchase' => $purchasePercent,
            'supplier' => $purchasePercent->supplier,
            'receivedNotes' => collect([]),
            'normBatches' => collect([]),
        ])->render();

        // Must display 10% with Rp 1,000 equivalent
        $this->assertStringContainsString('10%', $showPercentHtml);
        $this->assertStringContainsString('(' . formatRupiah(1000.0) . ')', $showPercentHtml);

        $printPercentHtml = view('purchase::print', [
            'purchase' => $purchasePercent,
            'supplier' => $purchasePercent->supplier,
            'details' => $purchasePercent->purchaseDetails,
        ])->render();

        // Print view must show 10% in percentage column and 1.000 in amount column
        $this->assertStringContainsString('10%', $printPercentHtml);
        $this->assertStringContainsString('1.000', $printPercentHtml);
        // It must NOT show '0.1%' or '10'
        $this->assertStringNotContainsString('0.1%', $printPercentHtml);
    }

    public function test_persisted_purchase_flow_matrix(): void
    {
        $tax11 = \Modules\Setting\Entities\Tax::create([
            'name' => 'PPN 11%',
            'value' => 11.0,
            'is_active' => true,
        ]);

        $matrix = [
            // 1. Fixed conversion unit (BOX, factor 12, entered discount Rp1,200)
            'fixed_box_non_pkp' => [
                'unit' => $this->boxUnit,
                'conversion' => $this->conversion,
                'factor' => 12.0,
                'entered_price' => 12000.0,
                'discount_type' => 'fixed',
                'discount_input' => 1200.0,
                'is_tax_included' => false,
                'tax' => null,
                'expected_subtotal' => 10800.0,
                'expected_tax' => 0.0,
                'expected_canonical_discount' => 100.0,
                'expected_entered_discount' => 1200.0,
                'expected_recalc_subtotal_2qty' => 21600.0,
            ],
            'fixed_box_pkp_excluded' => [
                'unit' => $this->boxUnit,
                'conversion' => $this->conversion,
                'factor' => 12.0,
                'entered_price' => 12000.0,
                'discount_type' => 'fixed',
                'discount_input' => 1200.0,
                'is_tax_included' => false,
                'tax' => $tax11,
                'expected_subtotal' => 11988.0,
                'expected_tax' => 1188.0,
                'expected_canonical_discount' => 100.0,
                'expected_entered_discount' => 1200.0,
                'expected_recalc_subtotal_2qty' => 23976.0,
            ],
            'fixed_box_pkp_included' => [
                'unit' => $this->boxUnit,
                'conversion' => $this->conversion,
                'factor' => 12.0,
                'entered_price' => 12000.0,
                'discount_type' => 'fixed',
                'discount_input' => 1200.0,
                'is_tax_included' => true,
                'tax' => $tax11,
                'expected_subtotal' => 10800.0,
                'expected_tax' => 1070.27,
                'expected_canonical_discount' => 100.0,
                'expected_entered_discount' => 1200.0,
                'expected_recalc_subtotal_2qty' => 21600.0,
            ],
            // 2. Percentage conversion unit (BOX, factor 12, 10%)
            'percent_box_non_pkp' => [
                'unit' => $this->boxUnit,
                'conversion' => $this->conversion,
                'factor' => 12.0,
                'entered_price' => 12000.0,
                'discount_type' => 'percentage',
                'discount_input' => 10.0,
                'is_tax_included' => false,
                'tax' => null,
                'expected_subtotal' => 10800.0,
                'expected_tax' => 0.0,
                'expected_canonical_discount' => 100.0,
                'expected_entered_discount' => 10.0,
                'expected_recalc_subtotal_2qty' => 21600.0,
            ],
            'percent_box_pkp_excluded' => [
                'unit' => $this->boxUnit,
                'conversion' => $this->conversion,
                'factor' => 12.0,
                'entered_price' => 12000.0,
                'discount_type' => 'percentage',
                'discount_input' => 10.0,
                'is_tax_included' => false,
                'tax' => $tax11,
                'expected_subtotal' => 11988.0,
                'expected_tax' => 1188.0,
                'expected_canonical_discount' => 100.0,
                'expected_entered_discount' => 10.0,
                'expected_recalc_subtotal_2qty' => 23976.0,
            ],
            'percent_box_pkp_included' => [
                'unit' => $this->boxUnit,
                'conversion' => $this->conversion,
                'factor' => 12.0,
                'entered_price' => 12000.0,
                'discount_type' => 'percentage',
                'discount_input' => 10.0,
                'is_tax_included' => true,
                'tax' => $tax11,
                'expected_subtotal' => 10800.0,
                'expected_tax' => 1070.27,
                'expected_canonical_discount' => 100.0,
                'expected_entered_discount' => 10.0,
                'expected_recalc_subtotal_2qty' => 21600.0,
            ],
            // 3. Percentage base unit (PCS, factor 1, 10%)
            'percent_pcs_non_pkp' => [
                'unit' => $this->pcsUnit,
                'conversion' => null,
                'factor' => 1.0,
                'entered_price' => 10000.0,
                'discount_type' => 'percentage',
                'discount_input' => 10.0,
                'is_tax_included' => false,
                'tax' => null,
                'expected_subtotal' => 9000.0,
                'expected_tax' => 0.0,
                'expected_canonical_discount' => 1000.0,
                'expected_entered_discount' => 10.0,
                'expected_recalc_subtotal_2qty' => 18000.0,
            ],
            'percent_pcs_pkp_excluded' => [
                'unit' => $this->pcsUnit,
                'conversion' => null,
                'factor' => 1.0,
                'entered_price' => 10000.0,
                'discount_type' => 'percentage',
                'discount_input' => 10.0,
                'is_tax_included' => false,
                'tax' => $tax11,
                'expected_subtotal' => 9990.0,
                'expected_tax' => 990.0,
                'expected_canonical_discount' => 1000.0,
                'expected_entered_discount' => 10.0,
                'expected_recalc_subtotal_2qty' => 19980.0,
            ],
            'percent_pcs_pkp_included' => [
                'unit' => $this->pcsUnit,
                'conversion' => null,
                'factor' => 1.0,
                'entered_price' => 10000.0,
                'discount_type' => 'percentage',
                'discount_input' => 10.0,
                'is_tax_included' => true,
                'tax' => $tax11,
                'expected_subtotal' => 9000.0,
                'expected_tax' => 891.89,
                'expected_canonical_discount' => 1000.0,
                'expected_entered_discount' => 10.0,
                'expected_recalc_subtotal_2qty' => 18000.0,
            ],
        ];

        $normalizer = app(PurchaseNormalizer::class);

        foreach ($matrix as $key => $case) {
            $isPkp = $case['tax'] !== null;
            $this->setting->update(['is_pkp' => $isPkp]);

            $calculatedItemDiscount = $case['discount_type'] === 'percentage'
                ? round($case['entered_price'] * ($case['discount_input'] / 100), 2)
                : $case['discount_input'];

            $detailInput = [
                'id' => $this->product->id,
                'product_id' => $this->product->id,
                'qty' => 1.0,
                'price' => $case['entered_price'],
                'options' => [
                    'purchase_unit_id' => $case['unit']->id,
                    'product_unit_conversion_id' => $case['conversion']?->id,
                    'conversion_factor' => $case['factor'],
                    'entered_quantity' => 1.0,
                    'entered_unit_price' => $case['entered_price'],
                    'entered_product_discount_amount' => $case['discount_input'],
                    'product_discount_input' => $case['discount_input'],
                    'product_discount_type' => $case['discount_type'],
                    'product_discount' => $calculatedItemDiscount,
                    'sub_total' => $case['expected_subtotal'],
                    'sub_total_before_tax' => $case['expected_subtotal'] - $case['expected_tax'],
                    'product_tax_amount' => $case['expected_tax'],
                    'product_tax' => $case['tax']?->id,
                    'tax_id' => $case['tax']?->id,
                    'pricing_source' => 'automatic',
                    \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
                ],
                'tax_id' => $case['tax']?->id,
            ];

            $header = [
                'is_tax_included' => $case['is_tax_included'],
                'order_tax' => $case['tax']?->value ?? 0,
            ];

            // 1. Verify Normalizer output
            $normResult = $normalizer->normalize($header, [$detailInput], $isPkp, $this->setting->id);
            $normalized = $normResult['details'][0];

            $this->assertEquals($case['expected_canonical_discount'], (float) $normalized['product_discount_amount'], "{$key} canonical discount mismatch");
            $this->assertEquals($case['expected_entered_discount'], (float) $normalized['entered_product_discount_amount'], "{$key} entered discount mismatch");
            $this->assertEquals($case['expected_subtotal'], (float) $normalized['sub_total'], "{$key} subtotal mismatch");
            $this->assertEquals($case['expected_tax'], (float) $normalized['product_tax_amount'], "{$key} tax mismatch");

            // 2. Persist Purchase and Detail
            $purchase = Purchase::create([
                'date' => now()->format('Y-m-d'),
                'due_date' => now()->addDays(7)->format('Y-m-d'),
                'reference' => 'PUR-MAT-' . $key,
                'supplier_id' => $this->supplier->id,
                'payment_term_id' => $this->paymentTerm->id,
                'status' => Purchase::STATUS_DRAFTED,
                'total_amount' => $case['expected_subtotal'],
                'paid_amount' => 0,
                'due_amount' => $case['expected_subtotal'],
                'payment_status' => 'Unpaid',
                'payment_method' => 'Cash',
                'is_tax_included' => $case['is_tax_included'],
                'setting_id' => $this->setting->id,
            ]);

            $detail = PurchaseDetail::create([
                'purchase_id' => $purchase->id,
                'product_id' => $this->product->id,
                'purchase_unit_id' => $case['unit']->id,
                'product_unit_conversion_id' => $case['conversion']?->id,
                'quantity' => $case['factor'],
                'entered_quantity' => 1.0,
                'price' => $case['entered_price'] / $case['factor'],
                'unit_price' => $case['entered_price'] / $case['factor'],
                'entered_unit_price' => $case['entered_price'],
                'product_discount_amount' => $case['expected_canonical_discount'],
                'entered_product_discount_amount' => $case['expected_entered_discount'],
                'product_discount_type' => $case['discount_type'],
                'conversion_factor' => $case['factor'],
                'unit_name' => $case['unit']->name,
                'base_unit_name' => 'PCS',
                'tax_id' => $case['tax']?->id,
                'product_tax_amount' => $case['expected_tax'],
                'sub_total' => $case['expected_subtotal'],
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'pricing_source' => 'automatic',
            ]);

            // 3. Reopen in EditForm
            Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);
            $cartItem = Cart::instance('purchase')->content()->first();
            $this->assertNotNull($cartItem);
            $this->assertEquals($case['expected_entered_discount'], (float) $cartItem->options->product_discount_input, "{$key} hydrated discount_input mismatch");
            $this->assertEquals($calculatedItemDiscount, (float) $cartItem->options->product_discount, "{$key} hydrated product_discount mismatch");

            // 4. Render Details and Print
            $purchase->load(['purchaseDetails.purchaseUnit', 'purchaseDetails.product.baseUnit', 'supplier']);
            $showHtml = view('purchase::show', [
                'purchase' => $purchase,
                'supplier' => $purchase->supplier,
                'receivedNotes' => collect([]),
                'normBatches' => collect([]),
            ])->render();
            $this->assertNotEmpty($showHtml);

            $printHtml = view('purchase::print', [
                'purchase' => $purchase,
                'supplier' => $purchase->supplier,
                'details' => $purchase->purchaseDetails,
            ])->render();
            $this->assertNotEmpty($printHtml);

            // 5. Test Cart recalculation on quantity change to 2
            $productCart = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase', 'data' => $purchase]);
            $productCart->call('updateQuantityDirect', $cartItem->rowId, $this->product->id, 2.0);
            $freshCartItem = Cart::instance('purchase')->content()->first();
            $this->assertEquals($case['expected_recalc_subtotal_2qty'], (float) $freshCartItem->options->sub_total, "{$key} recalc subtotal mismatch");
        }
    }

    public function test_duplicate_base_unit_purchase_with_percentage_discount_retains_entered_percentage(): void
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-DUP-BASE-001',
            'supplier_id' => $this->supplier->id,
            'payment_term_id' => $this->paymentTerm->id,
            'status' => Purchase::STATUS_APPROVED,
            'total_amount' => 90000.0,
            'paid_amount' => 0,
            'due_amount' => 90000.0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => false,
            'setting_id' => $this->setting->id,
        ]);

        // Base unit row: 10 PCS @ Rp 10,000 with 10% discount
        // Canonical discount: Rp 1,000 / PCS
        // Entered discount: 10 (%)
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'purchase_unit_id' => null,
            'product_unit_conversion_id' => null,
            'quantity' => 10.0,
            'entered_quantity' => 10.0,
            'price' => 10000.0,
            'unit_price' => 10000.0,
            'entered_unit_price' => 10000.0,
            'product_discount_amount' => 1000.0,
            'entered_product_discount_amount' => 10.0, // 10%
            'product_discount_type' => 'percentage',
            'conversion_factor' => 1.0,
            'unit_name' => 'PCS',
            'base_unit_name' => 'PCS',
            'product_tax_amount' => 0.0,
            'sub_total' => 90000.0,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'pricing_source' => 'automatic',
        ]);

        Livewire::test(\App\Livewire\Purchase\CreateForm::class, [
            'idempotencyToken' => 'test-dup-base-token',
            'duplicateId' => $purchase->id,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertNotNull($cartItem);

        // Crucial: Must be 10 (10%), NOT 1000 (1000%)!
        $this->assertEquals(10.0, (float) $cartItem->options->product_discount_input);
        $this->assertEquals(10.0, (float) $cartItem->options->entered_product_discount_amount);
        $this->assertEquals(1000.0, (float) $cartItem->options->product_discount); // Currency equivalent per unit: Rp 1,000
        $this->assertEquals('percentage', $cartItem->options->product_discount_type);

        // ProductCart component mount must have item_discount = 10, not 1000
        $productCart = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);
        $productCart->assertSet('item_discount.' . $cartItem->rowId, 10.0);
        $productCart->assertSet('discount_type.' . $cartItem->rowId, 'percentage');

        // When recalculated (e.g. quantity changed to 5 PCS), subtotal must be (10000 - 1000) * 5 = 45,000 (NOT 0!)
        $productCart->call('updateQuantityDirect', $cartItem->rowId, $this->product->id, 5.0);
        $freshCartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(45000.0, (float) $freshCartItem->options->sub_total);
    }

    public function test_fixed_discount_exceeding_unit_price_is_capped_consistently_in_cart_and_normalizer(): void
    {
        // 1. In ProductCart: entering Rp 2,000 fixed discount against Rp 1,000 price
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);

        $component->call('productSelected', [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'product_quantity' => $this->product->product_quantity,
            'product_unit' => $this->product->product_unit,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Enter excessive fixed discount: 2000 against unit price 1000
        $component->set('item_discount.' . $rowId, 2000);
        $component->call('setProductDiscount', $rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Cart state and options must be capped at 1000
        $this->assertEquals(1000.0, (float) $component->get('item_discount')[$rowId]);
        $this->assertEquals(1000.0, (float) $cartItem->options->product_discount);
        $this->assertEquals(1000.0, (float) $cartItem->options->entered_product_discount_amount);
        $this->assertEquals(1000.0, (float) $cartItem->options->product_discount_input);
        $this->assertEquals(0.0, (float) $cartItem->options->sub_total);

        // 2. In PurchaseNormalizer: raw entered discount exceeding price must be capped
        $normalizer = app(PurchaseNormalizer::class);

        $detailInput = [
            'id' => $this->product->id,
            'product_id' => $this->product->id,
            'qty' => 1.0,
            'price' => 1000.0,
            'options' => [
                'purchase_unit_id' => $this->pcsUnit->id,
                'conversion_factor' => 1.0,
                'entered_quantity' => 1.0,
                'entered_unit_price' => 1000.0,
                'entered_product_discount_amount' => 2000.0, // Excessive: 2000 > 1000
                'product_discount_input' => 2000.0,
                'product_discount_type' => 'fixed',
                'product_discount' => 2000.0,
                'sub_total' => 0.0,
                'sub_total_before_tax' => 0.0,
                'product_tax_amount' => 0.0,
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ];

        $normResult = $normalizer->normalize([], [$detailInput], false, $this->setting->id);
        $normalized = $normResult['details'][0];

        $this->assertEquals(1000.0, (float) $normalized['entered_product_discount_amount']); // Capped at entered unit price 1000
        $this->assertEquals(1000.0, (float) $normalized['product_discount_amount']); // Capped at canonical unit price 1000
        $this->assertEquals(0.0, (float) $normalized['sub_total']);

        // 3. Converted unit excessive fixed discount: 1 BOX (12 PCS) @ 12,000/BOX, discount 15,000/BOX
        $detailInputBox = [
            'id' => $this->product->id,
            'product_id' => $this->product->id,
            'qty' => 1.0,
            'price' => 12000.0,
            'options' => [
                'purchase_unit_id' => $this->boxUnit->id,
                'product_unit_conversion_id' => $this->conversion->id,
                'conversion_factor' => 12.0,
                'entered_quantity' => 1.0,
                'entered_unit_price' => 12000.0,
                'entered_product_discount_amount' => 15000.0, // Excessive: 15000 > 12000
                'product_discount_input' => 15000.0,
                'product_discount_type' => 'fixed',
                'product_discount' => 15000.0,
                'sub_total' => 0.0,
                'sub_total_before_tax' => 0.0,
                'product_tax_amount' => 0.0,
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ];

        $normResultBox = $normalizer->normalize([], [$detailInputBox], false, $this->setting->id);
        $normalizedBox = $normResultBox['details'][0];

        $this->assertEquals(12000.0, (float) $normalizedBox['entered_product_discount_amount']); // Capped at entered unit price 12000
        $this->assertEquals(1000.0, (float) $normalizedBox['product_discount_amount']); // Capped at canonical unit price 1000 (12000 / 12)
        $this->assertEquals(0.0, (float) $normalizedBox['sub_total']);
    }

    public function test_capped_fixed_discount_with_repeating_unit_price_preserves_cart_total_during_normalization_and_saving(): void
    {
        $repeatingProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Repeating Price Product',
            'product_code' => 'RPP-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 10000,
            'product_price' => 33333.33,
            'product_cost' => 33333.333333,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 33333.333333,
        ]);

        Cart::instance('purchase')->destroy();

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);
        $component->call('productSelected', [
            'id' => $repeatingProduct->id,
            'product_name' => $repeatingProduct->product_name,
            'product_code' => $repeatingProduct->product_code,
            'product_quantity' => $repeatingProduct->product_quantity,
            'product_unit' => $repeatingProduct->product_unit,
            'last_purchase_price' => 33333.333333,
            'average_purchase_price' => 33333.333333,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Set quantity to 1,200
        $component->call('updateQuantityDirect', $rowId, $repeatingProduct->id, 1200.0);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Apply excessive fixed discount: e.g. Rp 50,000 (exceeds unit price Rp 33,333.333333)
        $component->set('item_discount.' . $rowId, 50000.0);
        $component->call('setProductDiscount', $rowId, $repeatingProduct->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // 1. Cart subtotal must be exactly Rp 0 (not shifted)
        $this->assertEquals(0.0, (float) $cartItem->options->sub_total);
        $this->assertEquals(33333.33, (float) $component->get('item_discount')[$rowId]); // Form display rounded to 2 decimals
        $this->assertEquals(33333.333333, (float) $cartItem->options->product_discount, '', 1e-4);
        $this->assertEquals(33333.333333, (float) $cartItem->options->entered_product_discount_amount, '', 1e-4);

        // 2. Normalization must preserve Rp 0 total, NOT produce Rp 4
        $normalizer = app(PurchaseNormalizer::class);
        $normResult = $normalizer->normalize([], Cart::instance('purchase')->content(), false, $this->setting->id);
        $normalizedDetail = $normResult['details'][0];

        $this->assertEquals(0.0, (float) $normalizedDetail['sub_total'], 'Normalized subtotal must remain 0.0, not shift to 4.0');
        $this->assertEquals(0.0, (float) $normResult['header']['total_amount']);

        // 3. Normalization must also handle when entered discount input is 33,333.33 (rounded to 2 decimals)
        $detailInputWithRoundedDiscount = [
            'id' => $repeatingProduct->id,
            'product_id' => $repeatingProduct->id,
            'qty' => 1200.0,
            'price' => 33333.33,
            'options' => [
                'purchase_unit_id' => $this->pcsUnit->id,
                'conversion_factor' => 1.0,
                'canonical_unit_price' => 33333.333333,
                'entered_quantity' => 1200.0,
                'entered_unit_price' => 33333.33,
                'entered_product_discount_amount' => 33333.33, // Rounded display discount
                'product_discount_input' => 33333.33,
                'product_discount_type' => 'fixed',
                'product_discount' => 33333.333333, // Exact applied discount from cart
                'is_discount_capped' => true,
                'sub_total' => 0.0,
                'sub_total_before_tax' => 0.0,
                'product_tax_amount' => 0.0,
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ];

        $normResultRounded = $normalizer->normalize([], [$detailInputWithRoundedDiscount], false, $this->setting->id);
        $this->assertEquals(0.0, (float) $normResultRounded['details'][0]['sub_total']);
        $this->assertEquals(0.0, (float) $normResultRounded['header']['total_amount']);

        // 4. Full save through CreateForm produces Rp 0 persisted sub_total and total_amount
        $createForm = Livewire::test(\App\Livewire\Purchase\CreateForm::class, [
            'idempotencyToken' => 'test-capped-repeating-token',
        ]);
        $createForm->set('supplier_id', $this->supplier->id);
        $createForm->set('payment_term', $this->paymentTerm->id);

        // Populate cart after CreateForm mount (as mount destroys the purchase cart)
        $cartComponent = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);
        $cartComponent->call('productSelected', [
            'id' => $repeatingProduct->id,
            'product_name' => $repeatingProduct->product_name,
            'product_code' => $repeatingProduct->product_code,
            'product_quantity' => $repeatingProduct->product_quantity,
            'product_unit' => $repeatingProduct->product_unit,
            'last_purchase_price' => 33333.333333,
            'average_purchase_price' => 33333.333333,
        ]);
        $cItem = Cart::instance('purchase')->content()->first();
        $cartComponent->call('updateQuantityDirect', $cItem->rowId, $repeatingProduct->id, 1200.0);
        $cItem = Cart::instance('purchase')->content()->first();
        $cartComponent->set('item_discount.' . $cItem->rowId, 50000.0);
        $cartComponent->call('setProductDiscount', $cItem->rowId, $repeatingProduct->id);

        $createForm->call('submit');

        $createdPurchase = Purchase::latest('id')->first();
        $this->assertNotNull($createdPurchase);
        $this->assertEquals(0.0, (float) $createdPurchase->total_amount, 'Persisted purchase total must be Rp 0');
        $createdDetail = $createdPurchase->purchaseDetails()->first();
        $this->assertNotNull($createdDetail);
        $this->assertEquals(0.0, (float) $createdDetail->sub_total, 'Persisted purchase detail subtotal must be Rp 0, not Rp 4');
    }

    public function test_explicit_zero_discount_with_small_fractional_price_is_not_treated_as_full_discount(): void
    {
        $fractionalProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Fractional Price Product',
            'product_code' => 'FPP-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 200000,
            'product_price' => 0.004,
            'product_cost' => 0.004,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 0.004,
        ]);

        Cart::instance('purchase')->destroy();

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);
        $component->call('productSelected', [
            'id' => $fractionalProduct->id,
            'product_name' => $fractionalProduct->product_name,
            'product_code' => $fractionalProduct->product_code,
            'product_quantity' => $fractionalProduct->product_quantity,
            'product_unit' => $fractionalProduct->product_unit,
            'last_purchase_price' => 0.004,
            'average_purchase_price' => 0.004,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Set quantity to 100,000
        $component->call('updateQuantityDirect', $rowId, $fractionalProduct->id, 100000.0);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Explicit zero discount
        $component->set('item_discount.' . $rowId, 0.0);
        $component->call('setProductDiscount', $rowId, $fractionalProduct->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Cart subtotal must be exactly Rp 400 (0.004 * 100,000), NOT Rp 0
        $this->assertEquals(400.0, (float) $cartItem->options->sub_total);
        $this->assertEquals(0.0, (float) $cartItem->options->product_discount);
        $this->assertEquals(0.0, (float) $cartItem->options->entered_product_discount_amount);
        $this->assertFalse((bool) ($cartItem->options->is_discount_capped ?? false));

        // In PurchaseNormalizer: unit price Rp0.004, explicit discount 0, quantity 100,000 becomes Rp400
        $normalizer = app(PurchaseNormalizer::class);
        $detailInput = [
            'id' => $fractionalProduct->id,
            'product_id' => $fractionalProduct->id,
            'qty' => 100000.0,
            'price' => 0.004,
            'options' => [
                'purchase_unit_id' => $this->pcsUnit->id,
                'conversion_factor' => 1.0,
                'canonical_unit_price' => 0.004,
                'entered_quantity' => 100000.0,
                'entered_unit_price' => 0.004,
                'entered_product_discount_amount' => 0.0,
                'product_discount_input' => 0.0,
                'product_discount_type' => 'fixed',
                'product_discount' => 0.0,
                'sub_total' => 400.0,
                'sub_total_before_tax' => 400.0,
                'product_tax_amount' => 0.0,
                'pricing_source' => 'automatic',
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ];

        $normResult = $normalizer->normalize([], [$detailInput], false, $this->setting->id);
        $this->assertEquals(0.0, (float) $normResult['details'][0]['entered_product_discount_amount']);
        $this->assertEquals(0.0, (float) $normResult['details'][0]['product_discount_amount']);
        $this->assertEquals(400.0, (float) $normResult['details'][0]['sub_total'], 'Explicit zero discount on Rp0.004 unit price must produce Rp400 subtotal, not Rp0');
    }

    public function test_legitimate_discount_slightly_below_unit_price_is_not_treated_as_full_discount(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Proximity Check Product',
            'product_code' => 'PCP-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 10000,
            'product_price' => 10.004,
            'product_cost' => 10.004,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 10.004,
        ]);

        Cart::instance('purchase')->destroy();

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);
        $component->call('productSelected', [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_quantity' => $product->product_quantity,
            'product_unit' => $product->product_unit,
            'last_purchase_price' => 10.004,
            'average_purchase_price' => 10.004,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Set quantity to 1,000
        $component->call('updateQuantityDirect', $rowId, $product->id, 1000.0);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Legitimate discount: Rp 10.000 (which is Rp 0.004 below the exact unit price Rp 10.004)
        $component->set('item_discount.' . $rowId, 10.0);
        $component->call('setProductDiscount', $rowId, $product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Cart subtotal must be exactly Rp 4.00 ((10.004 - 10.000) * 1,000), NOT Rp 0.00
        $this->assertEquals(4.0, (float) $cartItem->options->sub_total);
        $this->assertEquals(10.0, (float) $cartItem->options->product_discount);
        $this->assertEquals(10.0, (float) $cartItem->options->entered_product_discount_amount);
        $this->assertFalse((bool) ($cartItem->options->is_discount_capped ?? false));

        // In PurchaseNormalizer: must preserve legitimate partial discount
        $normalizer = app(PurchaseNormalizer::class);
        $normResult = $normalizer->normalize([], Cart::instance('purchase')->content(), false, $this->setting->id);
        $this->assertEquals(10.0, (float) $normResult['details'][0]['entered_product_discount_amount']);
        $this->assertEquals(10.0, (float) $normResult['details'][0]['product_discount_amount']);
        $this->assertEquals(4.0, (float) $normResult['details'][0]['sub_total'], 'Legitimate discount slightly below exact price must yield net price, not full discount');
    }

    public function test_capped_discount_preserves_exact_cap_when_switching_units_and_saving(): void
    {
        $repeatingProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Repeating Price Unit Switch Product',
            'product_code' => 'RPUSP-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 10000,
            'product_price' => 33333.333333,
            'product_cost' => 33333.333333,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 33333.333333,
        ]);

        $conversion12 = ProductUnitConversion::create([
            'product_id' => $repeatingProduct->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        Cart::instance('purchase')->destroy();

        $createForm = Livewire::test(\App\Livewire\Purchase\CreateForm::class, [
            'idempotencyToken' => 'test-capped-unit-switch-save-token',
        ]);
        $createForm->set('supplier_id', $this->supplier->id);
        $createForm->set('payment_term', $this->paymentTerm->id);

        $cartComponent = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);
        $cartComponent->call('productSelected', [
            'id' => $repeatingProduct->id,
            'product_name' => $repeatingProduct->product_name,
            'product_code' => $repeatingProduct->product_code,
            'product_quantity' => $repeatingProduct->product_quantity,
            'product_unit' => $repeatingProduct->product_unit,
            'last_purchase_price' => 33333.333333,
            'average_purchase_price' => 33333.333333,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Cap discount at unit price Rp33,333.333333
        $cartComponent->set('item_discount.' . $rowId, 50000.0);
        $cartComponent->call('setProductDiscount', $rowId, $repeatingProduct->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;
        $this->assertTrue((bool) ($cartItem->options->is_discount_capped ?? false));
        $this->assertEquals(0.0, (float) $cartItem->options->sub_total);

        // Switch to box of 12 (factor 12)
        $cartComponent->call('updateUnit', $rowId, 'conv_' . $conversion12->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // At BOX of 12: 33,333.333333 * 12 = 399,999.999996
        // Capped discount equals exact unit price without rounding either value
        // Subtotal must be Rp 0.00 (not Rp 0.04)
        $this->assertEquals((float) $cartItem->options->entered_unit_price, (float) $cartItem->options->product_discount);
        $this->assertEquals(400000.0, round((float) $cartItem->options->product_discount, 2));
        $this->assertEquals((float) $cartItem->options->entered_unit_price, (float) $cartItem->options->entered_product_discount_amount);
        $this->assertEquals(0.0, (float) $cartItem->options->sub_total, 'Cart subtotal after unit switch of capped discount must be Rp 0, not Rp 0.04');
        $this->assertTrue((bool) ($cartItem->options->is_discount_capped ?? false), 'Cap state must remain consistent after unit switch');

        // Normalizer should also produce Rp 0 subtotal
        $normalizer = app(PurchaseNormalizer::class);
        $normResult = $normalizer->normalize([], Cart::instance('purchase')->content(), false, $this->setting->id);
        $this->assertEquals(400000.0, round((float) $normResult['details'][0]['entered_product_discount_amount'], 2));
        $this->assertEquals(0.0, (float) $normResult['details'][0]['sub_total']);
        $this->assertEquals(0.0, (float) $normResult['header']['total_amount']);

        // Save through CreateForm
        $createForm->call('submit');

        $createdPurchase = Purchase::latest('id')->first();
        $this->assertNotNull($createdPurchase);
        $this->assertEquals(0.0, (float) $createdPurchase->total_amount, 'Persisted purchase total must be Rp 0');

        $createdDetail = $createdPurchase->purchaseDetails()->first();
        $this->assertNotNull($createdDetail);
        $this->assertEquals(0.0, (float) $createdDetail->sub_total, 'Persisted purchase detail subtotal must be Rp 0, not Rp 0.04');
        $this->assertEquals(400000.0, round((float) $createdDetail->entered_product_discount_amount, 2));
        $this->assertEquals(33333.33, round((float) $createdDetail->product_discount_amount, 2));
    }

    public function test_unit_switching_preserves_legitimate_exact_price_precision_and_cost(): void
    {
        $exactProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Exact Precision Unit Switch Product',
            'product_code' => 'EPUSP-001',
            'unit_id' => $this->pcsUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'product_quantity' => 1000000,
            'product_price' => 1.000004,
            'product_cost' => 1.000004,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_price' => 1.000004,
        ]);

        $conversion12 = ProductUnitConversion::create([
            'product_id' => $exactProduct->id,
            'unit_id' => $this->boxUnit->id,
            'base_unit_id' => $this->pcsUnit->id,
            'conversion_factor' => 12.0,
        ]);

        Cart::instance('purchase')->destroy();

        $createForm = Livewire::test(\App\Livewire\Purchase\CreateForm::class, [
            'idempotencyToken' => 'test-exact-price-switch-save-token',
        ]);
        $createForm->set('supplier_id', $this->supplier->id);
        $createForm->set('payment_term', $this->paymentTerm->id);

        $cartComponent = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);
        $cartComponent->call('productSelected', [
            'id' => $exactProduct->id,
            'product_name' => $exactProduct->product_name,
            'product_code' => $exactProduct->product_code,
            'product_quantity' => $exactProduct->product_quantity,
            'product_unit' => $exactProduct->product_unit,
            'last_purchase_price' => 1.000004,
            'average_purchase_price' => 1.000004,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Switch to box of 12 (factor 12)
        $cartComponent->call('updateUnit', $rowId, 'conv_' . $conversion12->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Set quantity to 100,000 BOX
        $cartComponent->call('updateQuantityDirect', $rowId, $exactProduct->id, 100000.0);

        $cartItem = Cart::instance('purchase')->content()->first();

        // 1.000004 * 12 = 12.000048 per BOX
        // For 100,000 BOX: 12.000048 * 100,000 = 1,200,004.80 (must NOT be rounded to 1,200,000.00)
        $this->assertEquals(1200004.80, (float) $cartItem->options->sub_total, '', 0.01);

        // Submit through CreateForm
        $createForm->call('submit');

        $createdPurchase = Purchase::latest('id')->first();
        $this->assertNotNull($createdPurchase);
        $this->assertEquals(1200004.80, (float) $createdPurchase->total_amount, '', 0.01);

        $createdDetail = $createdPurchase->purchaseDetails()->first();
        $this->assertNotNull($createdDetail);
        $this->assertEquals(1200004.80, (float) $createdDetail->sub_total, '', 0.01);
        $this->assertEquals(1.000004, (float) $createdDetail->unit_price, '', 0.000001);
    }
}



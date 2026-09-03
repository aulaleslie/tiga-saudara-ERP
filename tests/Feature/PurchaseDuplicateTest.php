<?php

namespace Tests\Feature;

use App\Livewire\Purchase\CreateForm;
use App\Models\User;
use Carbon\Carbon;
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
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseDuplicateTest extends TestCase
{
    use RefreshDatabase;

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
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        session(['setting_id' => $setting->id]);

        PaymentTerm::create(['name' => 'COD', 'longevity' => 0]);
        PaymentTerm::create(['name' => 'NET 30', 'longevity' => 30]);
    }

    public function test_purchase_duplicate_prefills_correctly(): void
    {
        $supplier = Supplier::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::where('name', 'NET 30')->first();

        // Create original purchase
        $originalDate = Carbon::now()->subDays(10);
        $originalDueDate = $originalDate->copy()->addDays(30);

        $originalPurchase = Purchase::create([
            'date' => $originalDate->format('Y-m-d'),
            'due_date' => $originalDueDate->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'payment_term_id' => $paymentTerm->id,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'is_tax_included' => true,
            'setting_id' => session('setting_id'),
            'note' => 'Original Note',
        ]);

        // Mock Livewire component for duplication
        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'test-token',
            'duplicateId' => $originalPurchase->id
        ]);

        // 1. Check is_tax_included (User reported as not properly copied)
        // Note: Code showed it was being copied in prefillFromPurchase, 
        // but it might not be synced to CreateForm property if it's only in ProductCart
        // In CreateForm.php, line 507: $this->is_tax_included = (bool) $purchase->is_tax_included;
        $component->assertSet('is_tax_included', true);

        // 2. Check date (Should be current date, not original date)
        $today = Carbon::now()->format('Y-m-d');
        $component->assertSet('date', $today);

        // 3. Check due_date (Should be recalculated based on payment term from today)
        $expectedDueDate = Carbon::now()->addDays(30)->format('Y-m-d');
        $component->assertSet('due_date', $expectedDueDate);

        // 4. Check other fields set to null
        $component->assertSet('supplier_purchase_number', null);
        $component->assertSet('tax_ref_no', null);

        // 5. Check global discount type
        $component->assertSet('global_discount_type', 'percentage');
        $component->assertSet('global_discount', 0);
    }

    public function test_purchase_duplicate_copies_fixed_discount_correctly(): void
    {
        $supplier = Supplier::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::where('name', 'NET 30')->first();

        $originalPurchase = Purchase::create([
            'date' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'payment_term_id' => $paymentTerm->id,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 50,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'total_amount' => 950,
            'due_amount' => 950,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'is_tax_included' => false,
            'setting_id' => session('setting_id'),
            'note' => 'Fixed Discount Note',
        ]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'test-token-fixed',
            'duplicateId' => $originalPurchase->id
        ]);

        $component->assertSet('global_discount_type', 'fixed');
        $component->assertSet('global_discount', 50);
        $component->assertSet('is_tax_included', false);
    }

    public function test_purchase_duplicate_infers_tax_included_for_legacy_mismatched_flag(): void
    {
        $sourcePurchase = $this->createLegacyMismatchedTaxIncludedPurchase();

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-legacy-prefill',
            'duplicateId' => $sourcePurchase->id,
        ]);

        $component->assertSet('is_tax_included', true);
    }

    public function test_purchase_duplicate_submit_persists_inferred_tax_included_for_legacy_mismatch(): void
    {
        $sourcePurchase = $this->createLegacyMismatchedTaxIncludedPurchase();

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-legacy-submit',
            'duplicateId' => $sourcePurchase->id,
        ]);

        $component->assertSet('is_tax_included', true)
            ->call('submit')
            ->assertRedirect(route('purchases.index'));

        $this->assertDatabaseCount('purchases', 2);

        $duplicatedPurchase = Purchase::query()->latest('id')->first();
        $this->assertNotNull($duplicatedPurchase);
        $this->assertTrue((bool) $duplicatedPurchase->is_tax_included);
    }

    public function test_purchase_duplicate_keeps_stored_flag_when_inference_is_not_available(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        $product = $this->createTestProduct();

        $originalPurchase = Purchase::create([
            'date' => Carbon::now()->subDays(1)->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(29)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'payment_term_id' => $paymentTerm->id,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'due_amount' => 10000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'is_tax_included' => false,
            'setting_id' => session('setting_id'),
            'note' => 'No inferable tax line',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-no-inferable',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->assertSet('is_tax_included', false);
    }

    public function test_purchase_duplicate_carries_over_valid_conversion_unit_line(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-conv-valid',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        $this->assertSame($boxUnit->id, $duplicatedDetail->purchase_unit_id);
        $this->assertSame($conversion->id, $duplicatedDetail->product_unit_conversion_id);
        $this->assertEquals(2, (float) $duplicatedDetail->entered_quantity);
        $this->assertEquals(12, (float) $duplicatedDetail->conversion_factor);
        $this->assertEquals(24, (float) $duplicatedDetail->quantity);
    }

    public function test_purchase_duplicate_carries_over_decimal_conversion_quantity(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 4,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2.5,
            'entered_unit_price' => 4000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 4,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-conv-decimal',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        $this->assertEquals(2.5, (float) $duplicatedDetail->entered_quantity);
        $this->assertEquals(10, (float) $duplicatedDetail->quantity);
    }

    public function test_purchase_duplicate_falls_back_to_base_unit_when_conversion_no_longer_exists(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        // The conversion is deleted after the source purchase was made, so
        // duplication must not resurrect it as a newly selectable unit --
        // it must instead fall back to a clean base-unit row and still
        // submit successfully with canonical values.
        $conversion->delete();

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-conv-deleted',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        $this->assertSame($pcsUnit->id, $duplicatedDetail->purchase_unit_id);
        $this->assertNull($duplicatedDetail->product_unit_conversion_id);
        $this->assertEquals(1, (float) $duplicatedDetail->conversion_factor);
        $this->assertEquals(24, (float) $duplicatedDetail->quantity);
        $this->assertEquals(24, (float) $duplicatedDetail->entered_quantity);
    }

    public function test_purchase_duplicate_falls_back_to_base_unit_when_conversion_unit_is_inactive(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        // The unit is deactivated after the source purchase was made.
        $boxUnit->update(['is_active' => false]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-conv-inactive',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        $this->assertSame($pcsUnit->id, $duplicatedDetail->purchase_unit_id);
        $this->assertNull($duplicatedDetail->product_unit_conversion_id);
        $this->assertEquals(1, (float) $duplicatedDetail->conversion_factor);
        $this->assertEquals(24, (float) $duplicatedDetail->quantity);
    }

    public function test_purchase_duplicate_falls_back_to_base_unit_when_conversion_factor_has_changed(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        // The conversion's base unit is changed to no longer match the
        // product's base unit -- it is no longer eligible for new activity,
        // even though the row still exists.
        $mismatchedBaseUnit = Unit::create(['name' => 'KG', 'short_name' => 'kg', 'operator' => '*', 'operation_value' => 1]);
        $conversion->update(['base_unit_id' => $mismatchedBaseUnit->id]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-conv-mismatched',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        $this->assertSame($pcsUnit->id, $duplicatedDetail->purchase_unit_id);
        $this->assertNull($duplicatedDetail->product_unit_conversion_id);
        $this->assertEquals(1, (float) $duplicatedDetail->conversion_factor);
        $this->assertEquals(24, (float) $duplicatedDetail->quantity);
    }

    public function test_purchase_duplicate_falls_back_to_base_unit_when_conversion_factor_is_no_longer_eligible(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        // The conversion factor is edited down to an invalid legacy value
        // (<= 1) after the source purchase was made.
        $conversion->update(['conversion_factor' => 1]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-conv-ineligible',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        $this->assertSame($pcsUnit->id, $duplicatedDetail->purchase_unit_id);
        $this->assertNull($duplicatedDetail->product_unit_conversion_id);
        $this->assertEquals(1, (float) $duplicatedDetail->conversion_factor);
        $this->assertEquals(24, (float) $duplicatedDetail->quantity);
    }

    public function test_purchase_duplicate_carries_over_fixed_discount_in_entered_unit_basis(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        // Entered basis: 2 BOX at 12000/BOX, with a discount of 1200/BOX
        // entered (canonical: quantity 24 PCS, discount 100/PCS = 2400 total).
        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 21600,
            'product_discount_amount' => 2400,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 1200,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-discount-fixed',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        $this->assertSame('fixed', strtolower($duplicatedDetail->product_discount_type));
        // Entered-unit-basis discount snapshot is preserved verbatim...
        $this->assertEquals(1200, (float) $duplicatedDetail->entered_product_discount_amount);
        // ...and the canonical (base-unit) discount is derived by dividing
        // through the conversion factor, matching PurchaseUomConversionService.
        $this->assertEquals(100, (float) $duplicatedDetail->product_discount_amount);
    }

    public function test_purchase_duplicate_carries_over_percentage_discount_in_entered_unit_basis(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        // 10% off the normalized base-unit price of 1000/PCS = 100/PCS
        // canonical discount, i.e. sub_total = (1000 - 100) * 24 = 21600.
        // Percentage discounts are basis-agnostic, so the entered-unit-basis
        // snapshot stores the same 10 (percentage points), not a currency amount.
        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 21600,
            'product_discount_amount' => 100,
            'product_discount_type' => 'percentage',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 10,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-discount-percentage',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        $this->assertSame('percentage', strtolower($duplicatedDetail->product_discount_type));
        // Entered-unit-basis percentage snapshot carries over verbatim.
        $this->assertEquals(10, (float) $duplicatedDetail->entered_product_discount_amount);
        // Canonical per-base-unit monetary discount: 10% of the normalized
        // base-unit price (1000) = 100.
        $this->assertEquals(100, (float) $duplicatedDetail->product_discount_amount);
        $this->assertEquals(24, (float) $duplicatedDetail->quantity);
        $this->assertEqualsWithDelta(21600, (float) $duplicatedDetail->sub_total, 0.01);

        $duplicatedPurchase = Purchase::query()->find($duplicatedDetail->purchase_id);
        $this->assertNotNull($duplicatedPurchase);
        $this->assertEqualsWithDelta(21600, (float) $duplicatedPurchase->total_amount, 0.01);
    }

    public function test_purchase_duplicate_falls_back_to_base_unit_when_conversion_factor_has_changed_in_place(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        // Source line: 2 BOX @ factor 12 = 24 canonical PCS.
        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        // The same conversion row (same id, same unit, still active and
        // eligible) is later edited to a different factor. Reusing it as-is
        // would silently reinterpret "2 BOX" as 20 canonical PCS instead of
        // the source document's 24 -- so this must still fall back to a
        // clean base-unit row built from the source's canonical values.
        $conversion->update(['conversion_factor' => 10]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-conv-factor-changed',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        $this->assertSame($pcsUnit->id, $duplicatedDetail->purchase_unit_id);
        $this->assertNull($duplicatedDetail->product_unit_conversion_id);
        $this->assertEquals(1, (float) $duplicatedDetail->conversion_factor);
        // Canonical base-unit values are preserved from the source document,
        // not recomputed against the new factor.
        $this->assertEquals(24, (float) $duplicatedDetail->quantity);
        $this->assertEquals(24, (float) $duplicatedDetail->entered_quantity);
        $this->assertEquals(1000, (float) $duplicatedDetail->entered_unit_price);
    }

    public function test_purchase_duplicate_of_legacy_base_unit_only_row_stays_base_unit(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        $product = $this->createTestProduct();

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            // Legacy row: no conversion snapshot columns populated at all.
        ]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-legacy',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedDetail = PurchaseDetail::query()
            ->where('purchase_id', '!=', $originalPurchase->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($duplicatedDetail);
        // No conversion was selected, so the line resolves to the product's base unit
        // (not null) with no conversion row -- it does not inherit any unit identity.
        $this->assertNull($duplicatedDetail->product_unit_conversion_id);
        $this->assertEquals(5, (float) $duplicatedDetail->quantity);
    }

    public function test_purchase_duplicate_with_mixed_unit_lines_for_same_product(): void
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        [$product, $pcsUnit, $boxUnit] = $this->createConversionProduct();

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $pcsUnit->id,
            'conversion_factor' => 12,
        ]);

        $originalPurchase = $this->createConversionPurchase($supplier, $paymentTerm);

        // Base-unit line for the product.
        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 3000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Conversion-unit line for the same product.
        PurchaseDetail::create([
            'purchase_id' => $originalPurchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 24,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 24000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'purchase_unit_id' => $boxUnit->id,
            'product_unit_conversion_id' => $conversion->id,
            'entered_quantity' => 2,
            'entered_unit_price' => 12000,
            'entered_product_discount_amount' => 0,
            'conversion_factor' => 12,
            'unit_name' => 'BOX',
            'base_unit_name' => 'PCS',
        ]);

        $component = Livewire::test(CreateForm::class, [
            'idempotencyToken' => 'token-dup-mixed-unit',
            'duplicateId' => $originalPurchase->id,
        ]);

        $component->call('submit')->assertRedirect(route('purchases.index'));

        $duplicatedPurchase = Purchase::query()
            ->where('id', '!=', $originalPurchase->id)
            ->latest('id')
            ->first();

        $duplicatedDetails = PurchaseDetail::query()
            ->where('purchase_id', $duplicatedPurchase->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $duplicatedDetails);
        $this->assertNull($duplicatedDetails[0]->product_unit_conversion_id);
        $this->assertEquals(3, (float) $duplicatedDetails[0]->quantity);
        $this->assertSame($boxUnit->id, $duplicatedDetails[1]->purchase_unit_id);
        $this->assertEquals(24, (float) $duplicatedDetails[1]->quantity);
    }

    /**
     * @return array{0: Product, 1: Unit, 2: Unit}
     */
    private function createConversionProduct(): array
    {
        $pcsUnit = Unit::firstOrCreate(
            ['short_name' => 'pcs'],
            ['name' => 'PCS', 'operator' => '*', 'operation_value' => 1]
        );
        $boxUnit = Unit::firstOrCreate(
            ['short_name' => 'box'],
            ['name' => 'BOX', 'operator' => '*', 'operation_value' => 1]
        );

        $product = Product::create([
            'product_name' => 'Conversion Duplicate Product',
            'product_code' => 'DUP-CONV-' . uniqid(),
            'product_quantity' => 100,
            'setting_id' => session('setting_id'),
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_unit' => 'pcs',
            'unit_id' => $pcsUnit->id,
            'base_unit_id' => $pcsUnit->id,
        ]);

        return [$product, $pcsUnit, $boxUnit];
    }

    private function createConversionPurchase(Supplier $supplier, PaymentTerm $paymentTerm): Purchase
    {
        return Purchase::create([
            'date' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'payment_term_id' => $paymentTerm->id,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'total_amount' => 24000,
            'due_amount' => 24000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'is_tax_included' => false,
            'setting_id' => session('setting_id'),
            'note' => 'Conversion duplicate source',
        ]);
    }

    private function createLegacyMismatchedTaxIncludedPurchase(): Purchase
    {
        [$supplier, $paymentTerm] = $this->createSupplierAndTerm();
        $product = $this->createTestProduct();
        $tax = Tax::create(['name' => 'PPN 11%', 'value' => 11]);

        $purchase = Purchase::create([
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(25)->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'payment_term_id' => $paymentTerm->id,
            'tax_amount' => 2200,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'total_amount' => 22200,
            'due_amount' => 22200,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'is_tax_included' => false, // Legacy inconsistent flag
            'setting_id' => session('setting_id'),
            'note' => 'Legacy tax included purchase',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 11100,
            'unit_price' => 11100,
            'sub_total' => 22200,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 2200,
            'tax_id' => $tax->id,
        ]);

        return $purchase;
    }

    /**
     * @return array{0:\Modules\People\Entities\Supplier,1:\Modules\Purchase\Entities\PaymentTerm}
     */
    private function createSupplierAndTerm(): array
    {
        $supplier = Supplier::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::where('name', 'NET 30')->firstOrFail();

        return [$supplier, $paymentTerm];
    }

    private function createTestProduct(): Product
    {
        return Product::create([
            'product_name' => 'Duplicate Test Product',
            'product_code' => 'DUP-TEST-001',
            'product_quantity' => 10,
            'setting_id' => session('setting_id'),
            'product_cost' => 100,
            'product_price' => 120,
            'product_unit' => 'pcs',
        ]);
    }
}

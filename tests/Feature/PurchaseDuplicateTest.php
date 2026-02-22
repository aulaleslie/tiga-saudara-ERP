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
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
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

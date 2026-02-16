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
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
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
}

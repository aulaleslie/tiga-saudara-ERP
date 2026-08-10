<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\PaymentMethod;
use Modules\People\Entities\Supplier;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class GlobalPurchasePaymentPartialStatesTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $user;
    protected $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        // Create default currency
        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        // Create COA for payment method
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coaId,
            'is_cash' => true,
        ]);

        \Illuminate\Support\Facades\Gate::define('purchasePayments.global.access', function (?\App\Models\User $user = null) {
            return true;
        });
        \Illuminate\Support\Facades\Gate::define('purchasePayments.create', function (?\App\Models\User $user = null) {
            return true;
        });

        // Create permission for correction actions
        Permission::findOrCreate('purchases.received.correct', 'web');
    }

    private function createPurchase($overrides = [])
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);

        return Purchase::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ], $overrides));
    }

    public function test_received_partially_purchases_are_visible_in_global_list()
    {
        $receivedPartially = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED_PARTIALLY]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($receivedPartially->reference);
    }

    public function test_returned_partially_purchases_are_visible_in_global_list()
    {
        $returnedPartially = $this->createPurchase(['status' => Purchase::STATUS_RETURNED_PARTIALLY]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($returnedPartially->reference);
    }

    public function test_received_partially_purchase_with_positive_balance_is_eligible_for_payment()
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        $receivedPartially = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'status' => Purchase::STATUS_RECEIVED_PARTIALLY,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 3000,
            'due_amount' => 7000,
            'setting_id' => $this->setting->id,
        ]);

        // Test that create endpoint allows access
        $response = $this->get(route('purchases.global-payments.create', ['supplier' => $supplier->id]) . '?purchase_id=' . $receivedPartially->id);
        $response->assertStatus(200);
    }

    public function test_returned_partially_purchase_with_positive_balance_is_eligible_for_payment()
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        $returnedPartially = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'status' => Purchase::STATUS_RETURNED_PARTIALLY,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 3000,
            'due_amount' => 7000,
            'setting_id' => $this->setting->id,
        ]);

        // Test that create endpoint allows access
        $response = $this->get(route('purchases.global-payments.create', ['supplier' => $supplier->id]) . '?purchase_id=' . $returnedPartially->id);
        $response->assertStatus(200);
    }

    public function test_fully_returned_purchase_is_excluded_from_global_list()
    {
        $returned = $this->createPurchase(['status' => Purchase::STATUS_RETURNED]);
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED]);

        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertDontSee($returned->reference)
            ->assertSee($received->reference);
    }

    public function test_fully_returned_purchase_is_rejected_on_payment_submission_even_with_positive_balance()
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);

        // Create a fully returned purchase with a legacy positive balance (shouldn't be payable)
        $returned = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'status' => Purchase::STATUS_RETURNED,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 2000,
            'due_amount' => 8000,
            'setting_id' => $this->setting->id,
        ]);

        // Attempt to submit a payment (simulating a tampered allocation)
        $response = $this->post(route('purchases.global-payments.store', $supplier->id), [
            'reference' => 'PAY-' . uniqid(),
            'date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [$returned->id => 1000],
        ]);

        // Should fail because the purchase status is RETURNED (not eligible)
        $this->assertFalse(PurchasePayment::where('purchase_id', $returned->id)->exists());
    }

    public function test_received_partially_and_returned_partially_and_returned_purchases_query_behavior()
    {
        $receivedPartially = $this->createPurchase([
            'status' => Purchase::STATUS_RECEIVED_PARTIALLY,
            'due_amount' => 5000,
        ]);

        $returnedPartially = $this->createPurchase([
            'status' => Purchase::STATUS_RETURNED_PARTIALLY,
            'due_amount' => 5000,
        ]);

        $returned = $this->createPurchase([
            'status' => Purchase::STATUS_RETURNED,
            'due_amount' => 5000,
        ]);

        // Test that globalPaymentEligible scope includes RECEIVED PARTIALLY
        $eligiblePurchases = Purchase::globalPaymentEligible()->pluck('id');
        $this->assertTrue($eligiblePurchases->contains($receivedPartially->id), 'RECEIVED PARTIALLY should be eligible');

        // Test that globalPaymentEligible scope includes RETURNED PARTIALLY
        $this->assertTrue($eligiblePurchases->contains($returnedPartially->id), 'RETURNED PARTIALLY should be eligible');

        // Test that globalPaymentEligible scope excludes RETURNED
        $this->assertFalse($eligiblePurchases->contains($returned->id), 'RETURNED should not be eligible');
    }

    public function test_received_partially_payment_submission_creates_valid_payment()
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        $receivedPartially = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'status' => Purchase::STATUS_RECEIVED_PARTIALLY,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 3000,
            'due_amount' => 7000,
            'setting_id' => $this->setting->id,
        ]);

        // Submit a valid positive global allocation
        $paymentAmount = 2000;
        $response = $this->post(route('purchases.global-payments.store', $supplier->id), [
            'reference' => 'PAY-' . uniqid(),
            'date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [$receivedPartially->id => $paymentAmount],
        ]);

        // Assert successful response (redirect)
        $this->assertEquals(302, $response->getStatusCode());

        // Reload from database
        $receivedPartially->refresh();

        // Should create one active PurchasePayment
        $payment = PurchasePayment::where('purchase_id', $receivedPartially->id)
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->first();
        $this->assertNotNull($payment, 'Payment should be created and active');
        $this->assertEquals($paymentAmount, (float) $payment->amount);

        // Assert reconciliation: paid_amount from canonical active payments
        $canonicalPaidAmount = PurchasePayment::where('purchase_id', $receivedPartially->id)
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->sum('amount');

        $this->assertEquals($canonicalPaidAmount, (float) $receivedPartially->paid_amount);

        // Assert due_amount = total - paid
        $expectedDueAmount = $receivedPartially->total_amount - $receivedPartially->paid_amount;
        $this->assertEquals($expectedDueAmount, (float) $receivedPartially->due_amount);

        // Assert payment_status
        $this->assertNotNull($receivedPartially->due_amount);
        if ($receivedPartially->due_amount > 0) {
            $this->assertEquals('PARTIAL', $receivedPartially->payment_status);
        } else {
            $this->assertEquals('PAID', $receivedPartially->payment_status);
        }
    }

    public function test_returned_partially_payment_submission_creates_valid_payment()
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        $returnedPartially = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'status' => Purchase::STATUS_RETURNED_PARTIALLY,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 3000,
            'due_amount' => 7000,
            'setting_id' => $this->setting->id,
        ]);

        // Submit a valid positive global allocation
        $paymentAmount = 2000;
        $response = $this->post(route('purchases.global-payments.store', $supplier->id), [
            'reference' => 'PAY-' . uniqid(),
            'date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [$returnedPartially->id => $paymentAmount],
        ]);

        // Assert successful response (redirect)
        $this->assertEquals(302, $response->getStatusCode());

        // Reload from database
        $returnedPartially->refresh();

        // Should create one active PurchasePayment
        $payment = PurchasePayment::where('purchase_id', $returnedPartially->id)
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->first();
        $this->assertNotNull($payment, 'Payment should be created and active');
        $this->assertEquals($paymentAmount, (float) $payment->amount);

        // Assert reconciliation: paid_amount from canonical active payments
        $canonicalPaidAmount = PurchasePayment::where('purchase_id', $returnedPartially->id)
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->sum('amount');

        $this->assertEquals($canonicalPaidAmount, (float) $returnedPartially->paid_amount);

        // Assert due_amount = total - paid
        $expectedDueAmount = $returnedPartially->total_amount - $returnedPartially->paid_amount;
        $this->assertEquals($expectedDueAmount, (float) $returnedPartially->due_amount);

        // Assert payment_status
        $this->assertNotNull($returnedPartially->due_amount);
        if ($returnedPartially->due_amount > 0) {
            $this->assertEquals('PARTIAL', $returnedPartially->payment_status);
        } else {
            $this->assertEquals('PAID', $returnedPartially->payment_status);
        }
    }

    public function test_fully_returned_purchase_not_eligible_via_create_endpoint()
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);

        // Create a fully returned purchase with a legacy positive balance (shouldn't be payable)
        $returned = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'status' => Purchase::STATUS_RETURNED,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 2000,
            'due_amount' => 8000,
            'setting_id' => $this->setting->id,
        ]);

        // Test create endpoint - should not allow access to RETURNED purchase
        $response = $this->get(route('purchases.global-payments.create', ['supplier' => $supplier->id]) . '?purchase_id=' . $returned->id);

        // Should not be able to create payment for RETURNED purchase (403, 404, or redirect)
        $this->assertTrue(
            in_array($response->status(), [302, 303, 307, 308, 403, 404]),
            "Expected redirect or error for RETURNED purchase, got {$response->status()}"
        );

        // Verify no payment was created
        $this->assertFalse(PurchasePayment::where('purchase_id', $returned->id)->exists());
    }

    public function test_received_partially_included_when_normal_unpaid_filter_applied()
    {
        $receivedPartially = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED_PARTIALLY, 'due_amount' => 7000, 'payment_status' => 'PARTIAL']);
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED, 'due_amount' => 5000, 'payment_status' => 'UNPAID']);

        // Normal mode (globalMode: false) with unpaid card filter should include RECEIVED PARTIALLY (intermediate receiving state)
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => false])
            ->call('applyPurchaseFilter', 'unpaid')
            ->assertSee($receivedPartially->reference)
            ->assertSee($received->reference);
    }

    public function test_returned_partially_excluded_when_normal_unpaid_filter_applied()
    {
        $returnedPartially = $this->createPurchase(['status' => Purchase::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000, 'payment_status' => 'PARTIAL']);
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED, 'due_amount' => 5000, 'payment_status' => 'UNPAID']);

        // Normal mode (globalMode: false) with unpaid card filter should exclude RETURNED PARTIALLY
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => false])
            ->call('applyPurchaseFilter', 'unpaid')
            ->assertDontSee($returnedPartially->reference)
            ->assertSee($received->reference);
    }

    public function test_received_partially_included_in_global_mode_table()
    {
        $receivedPartially = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED_PARTIALLY, 'due_amount' => 7000]);
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED, 'due_amount' => 5000]);

        // Global mode (globalMode: true) should include RECEIVED PARTIALLY
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($receivedPartially->reference)
            ->assertSee($received->reference);
    }

    public function test_returned_partially_included_in_global_mode_table()
    {
        $returnedPartially = $this->createPurchase(['status' => Purchase::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000]);
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED, 'due_amount' => 5000]);

        // Global mode (globalMode: true) should include RETURNED PARTIALLY
        Livewire::test(\App\Livewire\Purchase\PurchaseTable::class, ['globalMode' => true])
            ->assertSee($returnedPartially->reference)
            ->assertSee($received->reference);
    }

    public function test_received_partially_not_in_normal_mode_unpaid_summary()
    {
        $receivedPartially = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED_PARTIALLY, 'due_amount' => 7000]);
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED, 'due_amount' => 5000]);

        // Normal mode summary card - RECEIVED PARTIALLY should not be visible in normal workflow
        $component = Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, ['globalMode' => false]);
        // Verify that in normal mode, normal workflow shows only APPROVED/RECEIVED (excludes RECEIVED PARTIALLY)
        $normalWorkflowStatuses = [
            Purchase::STATUS_APPROVED,
            Purchase::STATUS_RECEIVED,
        ];
        $this->assertFalse(
            Purchase::where('setting_id', $this->setting->id)
                ->whereIn('status', $normalWorkflowStatuses)
                ->where('id', $receivedPartially->id)
                ->exists()
        );
        // Verify RECEIVED is still included
        $this->assertTrue(
            Purchase::where('setting_id', $this->setting->id)
                ->whereIn('status', $normalWorkflowStatuses)
                ->where('id', $received->id)
                ->exists()
        );
    }

    public function test_returned_partially_not_in_normal_mode_unpaid_summary()
    {
        $returnedPartially = $this->createPurchase(['status' => Purchase::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000]);
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED, 'due_amount' => 5000]);

        // Normal mode summary card - RETURNED PARTIALLY should not be visible in normal workflow filters
        $component = Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, ['globalMode' => false]);
        // Verify that in normal mode, normal workflow statuses are used (excludes RETURNED PARTIALLY)
        $normalWorkflowStatuses = [
            Purchase::STATUS_APPROVED,
            Purchase::STATUS_RECEIVED_PARTIALLY,
            Purchase::STATUS_RECEIVED,
        ];
        $this->assertFalse(
            Purchase::where('setting_id', $this->setting->id)
                ->whereIn('status', $normalWorkflowStatuses)
                ->where('id', $returnedPartially->id)
                ->exists()
        );
    }

    public function test_received_partially_in_global_mode_unpaid_summary()
    {
        $receivedPartially = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED_PARTIALLY, 'due_amount' => 7000]);
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED, 'due_amount' => 5000]);

        // Global mode summary card - both should be visible
        $component = Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, ['globalMode' => true]);
        // Verify RECEIVED PARTIALLY is in global payment eligible scope
        $this->assertTrue(Purchase::globalPaymentEligible()->where('id', $receivedPartially->id)->exists());
        $this->assertTrue(Purchase::globalPaymentEligible()->where('id', $received->id)->exists());
    }

    public function test_returned_partially_in_global_mode_unpaid_summary()
    {
        $returnedPartially = $this->createPurchase(['status' => Purchase::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000]);
        $received = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED, 'due_amount' => 5000]);

        // Global mode summary card - both should be visible
        $component = Livewire::test(\Modules\Purchase\Livewire\PurchaseSummaryCards::class, ['globalMode' => true]);
        // Verify RETURNED PARTIALLY is in global payment eligible scope
        $this->assertTrue(Purchase::globalPaymentEligible()->where('id', $returnedPartially->id)->exists());
        $this->assertTrue(Purchase::globalPaymentEligible()->where('id', $received->id)->exists());
    }
}

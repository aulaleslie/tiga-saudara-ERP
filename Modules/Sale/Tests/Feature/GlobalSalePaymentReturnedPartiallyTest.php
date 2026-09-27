<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\PaymentMethod;
use Modules\People\Entities\Customer;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class GlobalSalePaymentReturnedPartiallyTest extends TestCase
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

        \Illuminate\Support\Facades\Gate::define('salePayments.global.access', function (?\App\Models\User $user = null) {
            return true;
        });
        \Illuminate\Support\Facades\Gate::define('salePayments.create', function (?\App\Models\User $user = null) {
            return true;
        });
    }

    private function createSale($overrides = [])
    {
        $customer = Customer::factory()->create();

        return Sale::create(array_merge([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ], $overrides));
    }

    public function test_returned_partially_sales_are_visible_in_global_list()
    {
        $returnedPartially = $this->createSale(['status' => Sale::STATUS_RETURNED_PARTIALLY]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($returnedPartially->reference);
    }

    public function test_returned_partially_sales_with_positive_balance_are_eligible_for_payment()
    {
        $customer = Customer::factory()->create();
        $returnedPartially = Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_RETURNED_PARTIALLY,
            'payment_status' => 'PARTIAL',
            'total_amount' => 10000,
            'paid_amount' => 3000,
            'due_amount' => 7000,
            'setting_id' => $this->setting->id,
        ]);

        // Test that create endpoint allows access
        $response = $this->get(route('sales.global-payments.create', $returnedPartially->id));
        $response->assertStatus(200);
    }

    public function test_returned_sales_are_excluded_from_global_list()
    {
        $returned = $this->createSale(['status' => Sale::STATUS_RETURNED]);
        $approved = $this->createSale(['status' => Sale::STATUS_APPROVED]);

        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertDontSee($returned->reference)
            ->assertSee($approved->reference);
    }

    public function test_returned_sale_is_rejected_on_payment_submission_even_with_positive_balance()
    {
        $customer = Customer::factory()->create();

        // Create a returned sale with a legacy positive balance (shouldn't be payable)
        $returned = Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_RETURNED,
            'payment_status' => 'PARTIAL',
            'total_amount' => 10000,
            'paid_amount' => 2000,
            'due_amount' => 8000,
            'setting_id' => $this->setting->id,
        ]);

        // Attempt to submit a payment (simulating a tampered allocation)
        $response = $this->post(route('sales.global-payments.store', $returned->id), [
            'reference' => 'PAY-' . uniqid(),
            'date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [$returned->id => 1000],
        ]);

        // Should fail because the sale status is RETURNED (not eligible)
        $this->assertFalse(SalePayment::where('sale_id', $returned->id)->exists());
    }

    public function test_returned_partially_and_returned_sales_query_behavior()
    {
        $returnedPartially = $this->createSale(['status' => Sale::STATUS_RETURNED_PARTIALLY, 'due_amount' => 5000]);
        $returned = $this->createSale(['status' => Sale::STATUS_RETURNED, 'due_amount' => 5000]);

        // Test that globalPaymentEligible scope includes RETURNED PARTIALLY
        $eligibleSales = Sale::globalPaymentEligible()->pluck('id');
        $this->assertTrue($eligibleSales->contains($returnedPartially->id), 'RETURNED PARTIALLY should be eligible');

        // Test that globalPaymentEligible scope excludes RETURNED
        $this->assertFalse($eligibleSales->contains($returned->id), 'RETURNED should not be eligible');
    }

    public function test_returned_partially_invisible_in_normal_mode()
    {
        $returnedPartially = $this->createSale(['status' => Sale::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000]);
        $approved = $this->createSale(['status' => Sale::STATUS_APPROVED, 'due_amount' => 5000]);

        // Normal mode (globalMode = false) should exclude RETURNED PARTIALLY from query
        $normalModeSales = Sale::query()
            ->approvedUp()
            ->where('setting_id', $this->setting->id)
            ->pluck('id');

        $this->assertFalse($normalModeSales->contains($returnedPartially->id), 'RETURNED PARTIALLY should not appear in normal mode');
        $this->assertTrue($normalModeSales->contains($approved->id), 'APPROVED should appear in normal mode');
    }

    public function test_returned_partially_visible_in_global_mode()
    {
        $returnedPartially = $this->createSale(['status' => Sale::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000]);
        $approved = $this->createSale(['status' => Sale::STATUS_APPROVED, 'due_amount' => 5000]);

        // Global mode should include RETURNED PARTIALLY in query
        $globalModeSales = Sale::globalPaymentEligible()->pluck('id');

        $this->assertTrue($globalModeSales->contains($returnedPartially->id), 'RETURNED PARTIALLY should appear in global mode');
        $this->assertTrue($globalModeSales->contains($approved->id), 'APPROVED should appear in global mode');
    }

    public function test_returned_partially_payment_submission_creates_valid_payment()
    {
        $customer = Customer::factory()->create();
        $returnedPartially = Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_RETURNED_PARTIALLY,
            'payment_status' => 'PARTIAL',
            'total_amount' => 10000,
            'paid_amount' => 3000,
            'due_amount' => 7000,
            'setting_id' => $this->setting->id,
        ]);

        // Submit a valid positive global allocation
        $paymentAmount = 2000;
        $response = $this->post(route('sales.global-payments.store', $returnedPartially->id), [
            'reference' => 'PAY-' . uniqid(),
            'date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'allocations' => [$returnedPartially->id => $paymentAmount],
        ]);

        // Assert successful response (redirect)
        $this->assertEquals(302, $response->getStatusCode());

        // Reload from database
        $returnedPartially->refresh();

        // Should create one active SalePayment
        $payment = SalePayment::where('sale_id', $returnedPartially->id)
            ->where('status', SalePayment::STATUS_ACTIVE)
            ->first();
        $this->assertNotNull($payment, 'Payment should be created and active');
        $this->assertEquals($paymentAmount, (float) $payment->amount);

        // Assert reconciliation: paid_amount from canonical active payments
        $canonicalPaidAmount = SalePayment::where('sale_id', $returnedPartially->id)
            ->where('status', SalePayment::STATUS_ACTIVE)
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

    public function test_returned_sale_not_eligible_via_create_endpoint()
    {
        $customer = Customer::factory()->create();

        // Create a fully returned sale with a legacy positive balance (shouldn't be payable)
        $returned = Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_RETURNED,
            'payment_status' => 'PARTIAL',
            'total_amount' => 10000,
            'paid_amount' => 2000,
            'due_amount' => 8000,
            'setting_id' => $this->setting->id,
        ]);

        // Test create endpoint - should not allow access to RETURNED sale
        $response = $this->get(route('sales.global-payments.create', $returned->id));

        // Should not be able to create payment for RETURNED sale (403, 404, or redirect)
        $this->assertTrue(
            in_array($response->status(), [302, 303, 307, 308, 403, 404]),
            "Expected redirect or error for RETURNED sale, got {$response->status()}"
        );

        // Verify no payment was created
        $this->assertFalse(SalePayment::where('sale_id', $returned->id)->exists());
    }

    public function test_returned_partially_excluded_when_normal_unpaid_filter_applied()
    {
        $returnedPartially = $this->createSale(['status' => Sale::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000, 'payment_status' => 'PARTIAL']);
        $approved = $this->createSale(['status' => Sale::STATUS_APPROVED, 'due_amount' => 5000, 'payment_status' => 'UNPAID']);

        // Normal mode (globalMode: false) with unpaid card filter should exclude RETURNED PARTIALLY
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => false])
            ->call('applySaleFilter', 'unpaid')
            ->assertDontSee($returnedPartially->reference)
            ->assertSee($approved->reference);
    }

    public function test_returned_partially_included_in_global_mode_table()
    {
        $returnedPartially = $this->createSale(['status' => Sale::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000]);
        $approved = $this->createSale(['status' => Sale::STATUS_APPROVED, 'due_amount' => 5000]);

        // Global mode (globalMode: true) should include RETURNED PARTIALLY
        Livewire::test(\App\Livewire\Sale\SaleTable::class, ['globalMode' => true])
            ->assertSee($returnedPartially->reference)
            ->assertSee($approved->reference);
    }

    public function test_returned_partially_not_in_normal_mode_unpaid_summary()
    {
        $returnedPartially = $this->createSale(['status' => Sale::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000]);
        $approved = $this->createSale(['status' => Sale::STATUS_APPROVED, 'due_amount' => 5000]);

        // Normal mode summary card - only APPROVED should contribute to outstanding
        $component = Livewire::test(\Modules\Sale\Livewire\SaleSummaryCards::class, ['globalMode' => false]);
        // Check that component renders and query would exclude RETURNED_PARTIALLY
        $this->assertFalse(Sale::approvedUp()->where('setting_id', $this->setting->id)->where('id', $returnedPartially->id)->exists());
    }

    public function test_returned_partially_in_global_mode_unpaid_summary()
    {
        $returnedPartially = $this->createSale(['status' => Sale::STATUS_RETURNED_PARTIALLY, 'due_amount' => 7000]);
        $approved = $this->createSale(['status' => Sale::STATUS_APPROVED, 'due_amount' => 5000]);

        // Global mode summary card - both should be visible
        $component = Livewire::test(\Modules\Sale\Livewire\SaleSummaryCards::class, ['globalMode' => true]);
        // Verify RETURNED PARTIALLY is in global payment eligible scope
        $this->assertTrue(Sale::globalPaymentEligible()->where('id', $returnedPartially->id)->exists());
        $this->assertTrue(Sale::globalPaymentEligible()->where('id', $approved->id)->exists());
    }
}

<?php

namespace Modules\Pos\Tests\Unit;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReachableSalesResolver;
use Modules\Pos\Services\PosSettlementProjectionService;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosSettlementProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;
    protected Setting $setting1;
    protected Setting $setting2;
    protected Location $location1;
    protected Location $location2;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected PaymentMethod $paymentMethod;
    protected PosSettlementProjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting1 = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Setting 1',
            'company_email' => 's1@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's1@example.com',
            'footer_text' => 'F1',
            'document_prefix' => 'D1',
            'purchase_prefix_document' => 'P1',
            'sale_prefix_document' => 'S1',
            'pos_enabled' => true,
        ]);

        $this->setting2 = Setting::firstOrCreate(['id' => 2], [
            'company_name' => 'Setting 2',
            'company_email' => 's2@example.com',
            'company_phone' => '0812',
            'company_address' => 'Addr 2',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's2@example.com',
            'footer_text' => 'F2',
            'document_prefix' => 'D2',
            'purchase_prefix_document' => 'P2',
            'sale_prefix_document' => 'S2',
            'pos_enabled' => true,
        ]);

        $this->location1 = Location::firstOrCreate(['id' => 10], ['name' => 'Loc 10', 'setting_id' => 1]);
        $this->location2 = Location::firstOrCreate(['id' => 20], ['name' => 'Loc 20', 'setting_id' => 2]);

        $this->user = User::factory()->create();
        $this->customer = Customer::create([
            'customer_name' => 'Customer A',
            'customer_phone' => '08123456789',
            'customer_email' => 'cust@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Street',
        ]);

        $coa = ChartOfAccount::firstOrCreate(
            ['setting_id' => $this->setting1->id, 'account_number' => '1100-TEST-1'],
            [
                'name' => 'Cash Account Test',
                'account_number' => '1100-TEST-1',
                'category' => 'Kas & Bank',
                'setting_id' => $this->setting1->id,
            ]
        );

        $this->paymentMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Cash'],
            ['name' => 'Cash', 'is_cash' => true, 'coa_id' => $coa->id]
        );

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Terminal 1',
            'code' => 'T1',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opening_float_total' => 0,
        ]);

        $this->service = new PosSettlementProjectionService();
    }

    public function test_split_checkout_projection_and_reachability()
    {
        $sale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        SalePayment::create([
            'sale_id' => $sale1->id,
            'amount' => 100000,
            'date' => now()->toDateString(),
            'reference' => 'SP-001',
            'payment_method_id' => $this->paymentMethod->id,
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $sale2 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-002',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'due_date' => now()->subDays(5)->toDateString(),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting2->id,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-SPLIT-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash1',
            'subtotal' => 150000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 150000,
            'paid_total' => 100000,
            'change_total' => 0,
            'payment_method_id' => $this->paymentMethod->id,
            'payment_reference' => 'PAY-001',
            'receipt_number' => 'RCP-001',
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => '1_10_notax',
            'source_setting_id' => 1,
            'source_location_id' => 10,
            'tax_bucket' => 'notax',
            'sale_id' => $sale1->id,
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_total' => 100000,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => '2_20_notax',
            'source_setting_id' => 2,
            'source_location_id' => 20,
            'tax_bucket' => 'notax',
            'sale_id' => $sale2->id,
            'subtotal' => 50000,
            'grand_total' => 50000,
            'paid_total' => 0,
        ]);

        $projection = $this->service->project($trx);

        $this->assertTrue($projection['is_valid']);
        $this->assertEquals(150000.0, $projection['total_amount']);
        $this->assertEquals(100000.0, $projection['effective_paid']);
        $this->assertEquals(50000.0, $projection['live_due']);
        $this->assertEquals(PosSettlementProjectionService::PAYMENT_STATUS_PARTIAL, $projection['payment_status']);
        $this->assertTrue($projection['is_overdue']);
        $this->assertEquals(50000.0, $projection['overdue_amount']);
        $this->assertEquals(now()->subDays(5)->toDateString(), $projection['effective_due_date']);
        $this->assertTrue($projection['is_payable']);
    }

    public function test_inline_fallback_and_zero_down_debt()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-INLINE-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 75000,
            'paid_amount' => 0,
            'due_amount' => 75000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-INLINE-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash2',
            'subtotal' => 75000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 75000,
            'paid_total' => 0,
            'change_total' => 0,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCP-002',
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        $projection = $this->service->project($trx);

        $this->assertTrue($projection['is_valid']);
        $this->assertEquals(75000.0, $projection['total_amount']);
        $this->assertEquals(0.0, $projection['effective_paid']);
        $this->assertEquals(75000.0, $projection['live_due']);
        $this->assertEquals(PosSettlementProjectionService::PAYMENT_STATUS_UNPAID, $projection['payment_status']);
        $this->assertTrue($projection['is_payable']);
    }

    public function test_archived_reachable_sale_is_not_payable_despite_positive_due()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-ARCH-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 60000,
            'paid_amount' => 0,
            'due_amount' => 60000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);
        $sale->update(['archived_at' => now(), 'archived_by' => $this->user->id]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-ARCH-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_arch',
            'subtotal' => 60000,
            'grand_total' => 60000,
            'paid_total' => 0,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCP-ARCH-01',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $projection = $this->service->project($trx);

        $this->assertEquals(60000.0, $projection['live_due']);
        $this->assertFalse($projection['is_payable'], 'A transaction with an archived reachable Sale must not be payable.');
    }

    public function test_fully_returned_sale_is_not_payable_but_partially_returned_sale_is()
    {
        $fullyReturnedSale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-RETURNED-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 40000,
            'paid_amount' => 0,
            'due_amount' => 40000,
            'status' => Sale::STATUS_RETURNED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $trxReturned = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-RETURNED-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkoutReturned = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trxReturned->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_returned',
            'subtotal' => 40000,
            'grand_total' => 40000,
            'paid_total' => 0,
            'sale_id' => $fullyReturnedSale->id,
            'receipt_number' => 'RCP-RETURNED-01',
        ]);
        $trxReturned->update(['completed_checkout_id' => $checkoutReturned->id]);

        $returnedProjection = $this->service->project($trxReturned);
        $this->assertFalse($returnedProjection['is_payable'], 'A fully RETURNED Sale must not be payable.');

        // A partially-returned Sale remains eligible per Sale::scopeGlobalPaymentEligible().
        $partiallyReturnedSale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-RETURNED-PARTIAL-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 40000,
            'paid_amount' => 0,
            'due_amount' => 40000,
            'status' => Sale::STATUS_RETURNED_PARTIALLY,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $trxPartial = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-RETURNED-PARTIAL-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkoutPartial = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trxPartial->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_returned_partial',
            'subtotal' => 40000,
            'grand_total' => 40000,
            'paid_total' => 0,
            'sale_id' => $partiallyReturnedSale->id,
            'receipt_number' => 'RCP-RETURNED-PARTIAL-01',
        ]);
        $trxPartial->update(['completed_checkout_id' => $checkoutPartial->id]);

        $partialProjection = $this->service->project($trxPartial);
        $this->assertTrue($partialProjection['is_payable'], 'A RETURNED PARTIALLY Sale with positive due must remain payable.');
    }

    public function test_eager_loaded_fast_path_matches_query_per_sale_fallback_including_credits()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-EAGER-CREDIT-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 600000,
            'date' => now(),
            'reference' => 'PAY-EAGER-CASH',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RETURN-EAGER-01',
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 300000,
            'paid_amount' => 0,
            'due_amount' => 300000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 500000,
            'remaining_amount' => 300000,
            'status' => 'open',
        ]);

        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 300000,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-EAGER-CREDIT-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_eager_credit',
            'subtotal' => 1000000,
            'grand_total' => 1000000,
            'paid_total' => 0,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCP-EAGER-CREDIT-01',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        // Baseline via the query-per-Sale fallback path (no eager loading).
        $freshTrx = PosTransaction::find($trx->id);
        $fallbackProjection = $this->service->project($freshTrx);

        // Fast path: same eager-load spec the Livewire list/cards/controller use.
        // PosCheckoutSale/PosCheckout 'sale' is constrained with withArchived() so an
        // archived reachable Sale is still present for the resolver rather than
        // silently dropped by ArchivingScope.
        $eagerTrx = PosTransaction::query()
            ->with(PosSettlementProjectionService::reachableSalesEagerLoad())
            ->findOrFail($trx->id);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $eagerProjection = $this->service->project($eagerTrx);
        $queryLog = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Effective paid = 600,000 cash + 300,000 credit = 900,000; live due = 100,000.
        $this->assertEquals(900000.0, $fallbackProjection['effective_paid']);
        $this->assertEquals(100000.0, $fallbackProjection['live_due']);

        $this->assertEquals($fallbackProjection['effective_paid'], $eagerProjection['effective_paid']);
        $this->assertEquals($fallbackProjection['live_due'], $eagerProjection['live_due']);
        $this->assertEquals($fallbackProjection['payment_status'], $eagerProjection['payment_status']);

        // The fast path must not issue any additional queries: the resolver should reuse
        // the eager-loaded 'sale' (and nested salePayments.creditApplications) relations
        // instead of re-querying Sale per transaction.
        $this->assertCount(
            0,
            $queryLog,
            'project() on an eager-loaded transaction must not issue additional queries. Executed: ' . json_encode(array_column($queryLog, 'query'))
        );
    }

    public function test_base_eligible_query_requires_a_resolvable_sale_row_not_merely_a_non_null_foreign_key()
    {
        // A dangling FK (sale_id set but no matching Sale row) cannot be constructed in a
        // SQLite-backed test transaction here because the foreign_keys pragma cannot be
        // toggled mid-transaction under RefreshDatabase, and pos_checkouts.sale_id /
        // pos_checkout_sales.sale_id are both FK-constrained with nullOnDelete(), so a real
        // delete always nulls the column rather than leaving it dangling. Instead, this
        // verifies the query itself resolves the Sale row (EXISTS subquery against the
        // sales table) rather than only checking the foreign-key column is non-null, which
        // is what would allow a genuinely dangling reference (e.g. from direct data
        // corruption) through in production.
        $sql = $this->service->baseEligibleQuery()->toSql();

        $this->assertStringContainsString(
            'exists (select * from "sales"',
            $sql,
            'baseEligibleQuery() must resolve the Sale row via an EXISTS subquery, not merely check the sale_id column is non-null.'
        );
        $this->assertStringNotContainsString(
            '"sale_id" is not null',
            $sql,
            'baseEligibleQuery() must not rely on a bare non-null foreign-key check for Sale reachability.'
        );
    }

    public function test_invalidated_payment_reopens_balance()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-INV-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 80000,
            'paid_amount' => 80000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 80000,
            'date' => now()->toDateString(),
            'reference' => 'SP-INV-01',
            'payment_method_id' => $this->paymentMethod->id,
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-INV-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash3',
            'subtotal' => 80000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 80000,
            'paid_total' => 80000,
            'change_total' => 0,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCP-003',
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        $projection1 = $this->service->project($trx);
        $this->assertEquals(PosSettlementProjectionService::PAYMENT_STATUS_PAID, $projection1['payment_status']);
        $this->assertEquals(0.0, $projection1['live_due']);

        // Invalidate payment
        $payment->update([
            'status' => SalePayment::STATUS_INVALIDATED,
            'invalidated_at' => now(),
        ]);
        $sale->reconcileFromActivePayments();

        $projection2 = $this->service->project($trx);
        $this->assertEquals(PosSettlementProjectionService::PAYMENT_STATUS_UNPAID, $projection2['payment_status']);
        $this->assertEquals(80000.0, $projection2['live_due']);
        $this->assertEquals(0.0, $projection2['effective_paid']);
    }

    public function test_mapping_anomalies_and_diagnostics()
    {
        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-ANOMALY-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash4',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'paid_total' => 50000,
            'sale_id' => null, // No inline sale_id and no split sales
            'receipt_number' => 'RCP-004',
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        $diagnostics = $this->service->diagnose($trx);
        $this->assertFalse($diagnostics['is_valid']);
        $this->assertNotEmpty($diagnostics['anomalies']);

        $projection = $this->service->project($trx);
        $this->assertFalse($projection['is_valid']);
        $this->assertFalse($projection['is_payable']);
    }
}

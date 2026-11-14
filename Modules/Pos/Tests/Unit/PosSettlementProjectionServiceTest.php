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

        $context = $this->service->prepareReplacementSales([$eagerTrx]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $eagerProjection = $this->service->project($eagerTrx, $context);
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

    public function test_returned_zero_split_with_payable_sibling_is_payable(): void
    {
        // Sale 1: fully returned split sale (zero total, zero due), archived
        $returnedSale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-RET-ZERO-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => Sale::STATUS_RETURNED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);
        $returnedSale->update(['archived_at' => now(), 'archived_by' => $this->user->id]);

        // Sale 2: payable sibling with positive debt
        $payableSale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-PAYABLE-01',
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
            'setting_id' => $this->setting2->id,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-RET-SIB-01',
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
            'payload_hash' => 'hash_retsib',
            'subtotal' => 75000,
            'grand_total' => 75000,
            'paid_total' => 0,
            'receipt_number' => 'RCP-RET-SIB-01',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => '1_10_notax',
            'source_setting_id' => 1,
            'source_location_id' => 10,
            'tax_bucket' => 'notax',
            'sale_id' => $returnedSale->id,
            'subtotal' => 0,
            'grand_total' => 0,
            'paid_total' => 0,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => '2_20_notax',
            'source_setting_id' => 2,
            'source_location_id' => 20,
            'tax_bucket' => 'notax',
            'sale_id' => $payableSale->id,
            'subtotal' => 75000,
            'grand_total' => 75000,
            'paid_total' => 0,
        ]);

        $projection = $this->service->project($trx);

        $this->assertTrue($projection['is_valid']);
        $this->assertTrue($projection['is_payable'], 'A transaction with an archived returned-zero split and a payable sibling must be payable.');
        $this->assertEquals(75000.0, $projection['live_due']);
        $this->assertEquals(75000.0, $projection['total_amount']);
        $this->assertCount(2, $projection['sales'], 'Both reachable sales must remain resolved.');
    }

    public function test_all_zero_splits_are_not_payable(): void
    {
        $returnedSale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-ZERO-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => Sale::STATUS_RETURNED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $returnedSale2 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-ZERO-02',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => Sale::STATUS_RETURNED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting2->id,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-ALL-ZERO-01',
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
            'payload_hash' => 'hash_allzero',
            'subtotal' => 0,
            'grand_total' => 0,
            'paid_total' => 0,
            'receipt_number' => 'RCP-ALL-ZERO-01',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => '1_10_notax',
            'source_setting_id' => 1,
            'source_location_id' => 10,
            'tax_bucket' => 'notax',
            'sale_id' => $returnedSale1->id,
            'subtotal' => 0,
            'grand_total' => 0,
            'paid_total' => 0,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => '2_20_notax',
            'source_setting_id' => 2,
            'source_location_id' => 20,
            'tax_bucket' => 'notax',
            'sale_id' => $returnedSale2->id,
            'subtotal' => 0,
            'grand_total' => 0,
            'paid_total' => 0,
        ]);

        $projection = $this->service->project($trx);

        $this->assertTrue($projection['is_valid']);
        $this->assertEquals(0.0, $projection['live_due']);
        $this->assertFalse($projection['is_payable'], 'A transaction with all zero splits must not be payable.');
    }

    public function test_returned_zero_split_with_broken_mapping_is_not_payable(): void
    {
        $returnedSale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-BROKEN-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => Sale::STATUS_RETURNED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-BROKEN-01',
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
            'payload_hash' => 'hash_broken',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'paid_total' => 0,
            'receipt_number' => 'RCP-BROKEN-01',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        // One valid sale mapping
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => '1_10_notax',
            'source_setting_id' => 1,
            'source_location_id' => 10,
            'tax_bucket' => 'notax',
            'sale_id' => $returnedSale->id,
            'subtotal' => 0,
            'grand_total' => 0,
            'paid_total' => 0,
        ]);

        // Duplicate split mapping creating mapping anomaly
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => '2_20_notax',
            'source_setting_id' => 2,
            'source_location_id' => 20,
            'tax_bucket' => 'notax',
            'sale_id' => $returnedSale->id,
            'subtotal' => 50000,
            'grand_total' => 50000,
            'paid_total' => 0,
        ]);

        $diagnostics = $this->service->diagnose($trx);
        $this->assertFalse($diagnostics['is_valid']);
        $this->assertNotEmpty($diagnostics['anomalies']);

        $projection = $this->service->project($trx);
        $this->assertFalse($projection['is_valid']);
        $this->assertFalse($projection['is_payable'], 'Broken sale mapping must remain non-payable even with a returned-zero split.');
    }

    public function test_cross_owner_payment_transfer_projects_identically_on_eager_and_fallback_paths_with_zero_extra_queries(): void
    {
        $sourceSale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-XFER-SRC',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 45000,
            'paid_amount' => 45000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $originalPayment = SalePayment::create([
            'sale_id' => $sourceSale->id,
            'amount' => 2270000,
            'date' => now()->toDateString(),
            'reference' => 'SP-XFER-ORIG',
            'payment_method_id' => $this->paymentMethod->id,
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $replacementSale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-XFER-REPL',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2225000,
            'paid_amount' => 2225000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_PAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting2->id,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RET-XFER-PROJ-01',
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 2225000,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $product = \Modules\Product\Entities\Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Printer Test',
            'product_code' => 'PRN-TEST-01',
            'product_quantity' => 10,
            'product_cost' => 1500000,
            'product_price' => 2225000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
        ]);

        $saleReturnDetail = \Modules\SalesReturn\Entities\SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => $product->id,
            'product_name' => 'Printer',
            'product_code' => 'PRN-01',
            'quantity' => 1,
            'price' => 2225000,
            'unit_price' => 2225000,
            'sub_total' => 2225000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        \Modules\Pos\Entities\PosOwnerPaymentTransfer::create([
            'execution_key' => 'test-xfer-projection-01',
            'pos_return_id' => 1,
            'sale_return_id' => $saleReturn->id,
            'sale_return_detail_id' => $saleReturnDetail->id,
            'source_sale_id' => $sourceSale->id,
            'replacement_sale_id' => $replacementSale->id,
            'original_sale_payment_id' => $originalPayment->id,
            'payment_method_id' => $this->paymentMethod->id,
            'payment_method' => 'Cash',
            'amount' => 2225000,
            'amount_minor' => 222500000,
            'transfer_source' => \Modules\Pos\Entities\PosOwnerPaymentTransfer::SOURCE_LIVE_EXECUTION,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-XFER-01',
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
            'payload_hash' => 'hash_xfer',
            'subtotal' => 2270000,
            'grand_total' => 2270000,
            'paid_total' => 2270000,
            'receipt_number' => 'RCP-XFER-01',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => '1_10_notax',
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'tax_bucket' => 'notax',
            'sale_id' => $sourceSale->id,
            'subtotal' => 2270000,
            'grand_total' => 2270000,
            'paid_total' => 2270000,
        ]);

        $posReturn = \Modules\Pos\Entities\PosReturn::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $trx->code,
            'receipt_number' => $checkout->receipt_number,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'PR-XFER-01',
            'return_option' => \Modules\Pos\Entities\PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
            'approval_status' => \Modules\Pos\Entities\PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 2225000,
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash-xfer-01',
            'is_reversed' => false,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $sourceDetail = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sourceSale->id,
            'product_id' => $product->id,
            'product_name' => 'Printer',
            'product_code' => 'PRN-01',
            'quantity' => 0,
            'price' => 2225000,
            'unit_price' => 2225000,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $posReturnLine = \Modules\Pos\Entities\PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'sale_id' => $sourceSale->id,
            'sale_detail_id' => $sourceDetail->id,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'product_id' => $product->id,
            'product_name' => 'Printer',
            'product_code' => 'PRN-01',
            'quantity' => 1,
            'unit_price' => 2225000,
            'line_total' => 2225000,
            'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $replacementSale->id,
            'dispatch_date' => now(),
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);

        \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $replacementSale->id,
            'pos_return_line_id' => $posReturnLine->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location2->id,
            'is_inventory_managed' => true,
        ]);

        // Fallback path
        $fallbackTrx = PosTransaction::findOrFail($trx->id);
        $fallbackProjection = $this->service->project($fallbackTrx);

        // Eager path
        $eagerTrx = PosTransaction::query()
            ->with(PosSettlementProjectionService::reachableSalesEagerLoad())
            ->findOrFail($trx->id);
        $context = $this->service->prepareReplacementSales([$eagerTrx]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $eagerProjection = $this->service->project($eagerTrx, $context);
        $queryLog = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertTrue($fallbackProjection['is_valid']);
        $this->assertEquals(2270000.0, $fallbackProjection['total_amount']);
        $this->assertEquals(2270000.0, $fallbackProjection['effective_paid']);
        $this->assertEquals(0.0, $fallbackProjection['live_due']);
        $this->assertEquals(PosSettlementProjectionService::PAYMENT_STATUS_PAID, $fallbackProjection['payment_status']);

        $this->assertEquals($fallbackProjection['total_amount'], $eagerProjection['total_amount']);
        $this->assertEquals($fallbackProjection['effective_paid'], $eagerProjection['effective_paid']);
        $this->assertEquals($fallbackProjection['live_due'], $eagerProjection['live_due']);
        $this->assertEquals($fallbackProjection['payment_status'], $eagerProjection['payment_status']);
        $this->assertCount(0, $queryLog, 'project() on eager-loaded cross-owner transfer must issue 0 extra queries.');
    }

    public function test_staged_classifier_disjoint_and_exhaustive_against_resolver_diagnostics(): void
    {
        $readService = app(\Modules\Pos\Services\PosGlobalPaymentReadService::class);

        $makeTrxWithCheckout = function (string $code, ?int $checkoutCustomerId, bool $createSplit = true, ?int $inlineSaleId = null) {
            $trx = PosTransaction::create([
                'setting_id' => $this->setting1->id,
                'customer_id' => $checkoutCustomerId,
                'created_by' => $this->user->id,
                'owner_user_id' => $this->user->id,
                'last_saved_by' => $this->user->id,
                'source_pos_session_id' => $this->session->id,
                'code' => $code,
                'status' => PosTransaction::STATUS_COMPLETED,
            ]);

            $checkout = PosCheckout::create([
                'setting_id' => $this->setting1->id,
                'pos_transaction_id' => $trx->id,
                'pos_session_id' => $this->session->id,
                'terminal_id' => $this->terminal->id,
                'cashier_user_id' => $this->user->id,
                'customer_id' => $checkoutCustomerId,
                'status' => PosCheckout::STATUS_POSTED,
                'idempotency_key' => uniqid('cls_', true),
                'payload_hash' => 'hash_' . $code,
                'subtotal' => 10000,
                'grand_total' => 10000,
                'paid_total' => 0,
                'payment_method_id' => $this->paymentMethod->id,
                'receipt_number' => 'RCP-' . $code,
                'sale_id' => $inlineSaleId,
            ]);
            $trx->update(['completed_checkout_id' => $checkout->id]);

            return [$trx, $checkout];
        };

        $makeSale = function (string $ref, ?int $customerId, int $settingId = null): Sale {
            return Sale::create([
                'date' => now()->toDateString(),
                'reference' => $ref,
                'customer_id' => $customerId,
                'customer_name' => $customerId ? $this->customer->customer_name : 'Walk-in Customer',
                'tax_percentage' => 0,
                'tax_amount' => 0,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'total_amount' => 10000,
                'paid_amount' => 0,
                'due_amount' => 10000,
                'status' => Sale::STATUS_DISPATCHED,
                'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
                'payment_method' => 'Cash',
                'setting_id' => $settingId ?? $this->setting1->id,
            ]);
        };

        // 1. Ordinary multi-owner valid split
        [$ordMulti, $coMulti] = $makeTrxWithCheckout('CLS-ORD-MULTI', $this->customer->id);
        $sM1 = $makeSale('SL-CLS-M1', $this->customer->id, $this->setting1->id);
        $sM2 = $makeSale('SL-CLS-M2', $this->customer->id, $this->setting2->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coMulti->id, 'sale_id' => $sM1->id, 'split_key' => 'owner_1', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 10000, 'grand_total' => 10000, 'paid_total' => 0]);
        PosCheckoutSale::create(['pos_checkout_id' => $coMulti->id, 'sale_id' => $sM2->id, 'split_key' => 'owner_2', 'source_setting_id' => $this->setting2->id, 'source_location_id' => $this->location2->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 10000, 'grand_total' => 10000, 'paid_total' => 0]);

        // 2. Ordinary null-customer valid split
        [$ordNullCust, $coNullCust] = $makeTrxWithCheckout('CLS-ORD-NULLC', null);
        $sNC = $makeSale('SL-CLS-NC', null, $this->setting1->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coNullCust->id, 'sale_id' => $sNC->id, 'split_key' => 'main', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 10000, 'grand_total' => 10000, 'paid_total' => 0]);

        // 3. Reversed completed return on valid split stays ordinary
        [$ordRevRet, $coRevRet] = $makeTrxWithCheckout('CLS-ORD-REVRET', $this->customer->id);
        $sRR = $makeSale('SL-CLS-RR', $this->customer->id, $this->setting1->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coRevRet->id, 'sale_id' => $sRR->id, 'split_key' => 'main', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 10000, 'grand_total' => 10000, 'paid_total' => 0]);
        \Modules\Pos\Entities\PosReturn::create([
            'setting_id' => $this->setting1->id, 'pos_checkout_id' => $coRevRet->id, 'pos_transaction_id' => $ordRevRet->id,
            'transaction_code' => $ordRevRet->code, 'receipt_number' => $coRevRet->receipt_number,
            'return_option' => 'REFUND', 'approval_status' => 'APPROVED', 'source_snapshot' => [],
            'source_snapshot_hash' => 'rev', 'total_amount' => 1000, 'reference' => 'PR-REV',
            'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED, 'is_reversed' => true, 'created_by' => $this->user->id,
        ]);

        // 4. Exceptional: customer mismatch
        $otherCust = Customer::create(['customer_name' => 'Other C', 'customer_phone' => '0819', 'customer_email' => 'o@e.com', 'city' => 'C', 'country' => 'ID', 'address' => 'S']);
        [$excCustMismatch, $coCM] = $makeTrxWithCheckout('CLS-EXC-CM', $this->customer->id);
        $sCM = $makeSale('SL-CLS-CM', $otherCust->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coCM->id, 'sale_id' => $sCM->id, 'split_key' => 'main', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 10000, 'grand_total' => 10000, 'paid_total' => 0]);

        // 5. Exceptional: duplicate sale_id (needs FK check relaxed or direct insert with valid sale)
        [$excDupSale, $coDS] = $makeTrxWithCheckout('CLS-EXC-DS', $this->customer->id);
        $sDS = $makeSale('SL-CLS-DS', $this->customer->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coDS->id, 'sale_id' => $sDS->id, 'split_key' => 'k1', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 5000, 'grand_total' => 5000, 'paid_total' => 0]);
        PosCheckoutSale::create(['pos_checkout_id' => $coDS->id, 'sale_id' => $sDS->id, 'split_key' => 'k2', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 5000, 'grand_total' => 5000, 'paid_total' => 0]);

        // 6. Exceptional: case-colliding split_key ('owner_a' vs 'OWNER_A')
        [$excCaseKey, $coCK] = $makeTrxWithCheckout('CLS-EXC-CK', $this->customer->id);
        $sCK1 = $makeSale('SL-CLS-CK1', $this->customer->id);
        $sCK2 = $makeSale('SL-CLS-CK2', $this->customer->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coCK->id, 'sale_id' => $sCK1->id, 'split_key' => 'owner_a', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 5000, 'grand_total' => 5000, 'paid_total' => 0]);
        PosCheckoutSale::create(['pos_checkout_id' => $coCK->id, 'sale_id' => $sCK2->id, 'split_key' => 'OWNER_A', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 5000, 'grand_total' => 5000, 'paid_total' => 0]);

        // 7. Exceptional: inline mapping (no pos_checkout_sales)
        $sInline = $makeSale('SL-CLS-INL', $this->customer->id);
        [$excInline, ] = $makeTrxWithCheckout('CLS-EXC-INL', $this->customer->id, false, $sInline->id);

        // 8. Exceptional: checkout-linked and transaction-linked active returns
        [$excRetCo, $coRC] = $makeTrxWithCheckout('CLS-EXC-RC', $this->customer->id);
        $sRC = $makeSale('SL-CLS-RC', $this->customer->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coRC->id, 'sale_id' => $sRC->id, 'split_key' => 'main', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 10000, 'grand_total' => 10000, 'paid_total' => 0]);
        \Modules\Pos\Entities\PosReturn::create([
            'setting_id' => $this->setting1->id, 'pos_checkout_id' => $coRC->id, 'pos_transaction_id' => 0,
            'transaction_code' => $excRetCo->code, 'receipt_number' => $coRC->receipt_number,
            'return_option' => 'REFUND', 'approval_status' => 'APPROVED', 'source_snapshot' => [],
            'source_snapshot_hash' => 'rc', 'total_amount' => 1000, 'reference' => 'PR-RC',
            'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED, 'is_reversed' => false, 'created_by' => $this->user->id,
        ]);

        [$excRetTx, $coRT] = $makeTrxWithCheckout('CLS-EXC-RT', $this->customer->id);
        $sRT = $makeSale('SL-CLS-RT', $this->customer->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coRT->id, 'sale_id' => $sRT->id, 'split_key' => 'main', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 10000, 'grand_total' => 10000, 'paid_total' => 0]);
        \Modules\Pos\Entities\PosReturn::create([
            'setting_id' => $this->setting1->id, 'pos_checkout_id' => 0, 'pos_transaction_id' => $excRetTx->id,
            'transaction_code' => $excRetTx->code, 'receipt_number' => $coRT->receipt_number,
            'return_option' => 'REFUND', 'approval_status' => 'APPROVED', 'source_snapshot' => [],
            'source_snapshot_hash' => 'rt', 'total_amount' => 1000, 'reference' => 'PR-RT',
            'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED, 'is_reversed' => false, 'created_by' => $this->user->id,
        ]);

        // 9. Exceptional: null sale_id alongside valid sibling, empty split_key, and duplicate exact split_key
        $sRealSib1 = $makeSale('SL-CLS-SIB1', $this->customer->id);
        [$excNullSale, $coNS] = $makeTrxWithCheckout('CLS-EXC-NS', $this->customer->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coNS->id, 'sale_id' => $sRealSib1->id, 'split_key' => 'main', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 5000, 'grand_total' => 5000, 'paid_total' => 0]);
        PosCheckoutSale::create(['pos_checkout_id' => $coNS->id, 'sale_id' => null, 'split_key' => 'broken_null', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 5000, 'grand_total' => 5000, 'paid_total' => 0]);

        $sRealSib2 = $makeSale('SL-CLS-SIB2', $this->customer->id);
        [$excEmptyKey, $coEK] = $makeTrxWithCheckout('CLS-EXC-EK', $this->customer->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coEK->id, 'sale_id' => $sRealSib2->id, 'split_key' => '', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 10000, 'grand_total' => 10000, 'paid_total' => 0]);

        $sRealSib3a = $makeSale('SL-CLS-SIB3A', $this->customer->id);
        $sRealSib3b = $makeSale('SL-CLS-SIB3B', $this->customer->id);
        [$excDupKey, $coDK] = $makeTrxWithCheckout('CLS-EXC-DK', $this->customer->id);
        PosCheckoutSale::create(['pos_checkout_id' => $coDK->id, 'sale_id' => $sRealSib3a->id, 'split_key' => 'main', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 5000, 'grand_total' => 5000, 'paid_total' => 0]);
        PosCheckoutSale::create(['pos_checkout_id' => $coDK->id, 'sale_id' => $sRealSib3b->id, 'split_key' => ' MAIN ', 'source_setting_id' => $this->setting1->id, 'source_location_id' => $this->location1->id, 'tax_bucket' => 'NON_PKP', 'subtotal' => 5000, 'grand_total' => 5000, 'paid_total' => 0]);

        $allTrxIds = [
            $ordMulti->id, $ordNullCust->id, $ordRevRet->id,
            $excCustMismatch->id, $excDupSale->id, $excCaseKey->id,
            $excInline->id, $excRetCo->id, $excRetTx->id,
            $excNullSale->id, $excEmptyKey->id, $excDupKey->id,
        ];

        $query = $this->service->baseEligibleQuery()->whereIn('pos_transactions.id', $allTrxIds);
        $classified = $readService->classifyCandidates($query, 'id', 'asc');

        $ordinaryIds = array_keys($classified['ordinary']);
        $exceptionalIds = $classified['exceptional_ids'];

        $this->assertEmpty(array_intersect($ordinaryIds, $exceptionalIds), 'Classification must be strictly disjoint.');
        $combined = array_merge($ordinaryIds, $exceptionalIds);
        sort($combined);
        $expectedBase = $this->service->baseEligibleQuery()->whereIn('pos_transactions.id', $allTrxIds)->pluck('id')->all();
        sort($expectedBase);
        $this->assertSame($expectedBase, $combined, 'Classification must exhaustively cover all eligible rows.');

        $this->assertContains($ordMulti->id, $ordinaryIds);
        $this->assertContains($ordNullCust->id, $ordinaryIds);
        $this->assertContains($ordRevRet->id, $ordinaryIds);

        foreach ([$excCustMismatch->id, $excDupSale->id, $excCaseKey->id, $excInline->id, $excRetCo->id, $excRetTx->id, $excNullSale->id, $excEmptyKey->id, $excDupKey->id] as $expId) {
            $this->assertContains($expId, $exceptionalIds, "Expected tx {$expId} to classify conservatively as exceptional.");
        }

        // Verify diagnostics agreement on all classified records in base query
        foreach ($expectedBase as $tid) {
            $trx = PosTransaction::findOrFail($tid);
            $diag = $this->service->diagnose($trx);
            if (in_array($tid, $ordinaryIds, true)) {
                $this->assertTrue($diag['is_valid']);
                $this->assertTrue($diag['has_split_mapping']);
                $this->assertFalse($diag['is_inline_fallback']);
            }
        }
    }

    public function test_staged_ordinary_projection_matches_oracle_under_ledger_fanout_boundaries_and_avoids_model_hydration(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $readService = app(\Modules\Pos\Services\PosGlobalPaymentReadService::class);

        $makeSplitSale = function (array $attrs): Sale {
            return Sale::create(array_merge([
                'date' => '2026-11-01',
                'due_date' => '2026-11-20',
                'customer_id' => $this->customer->id,
                'customer_name' => $this->customer->customer_name,
                'tax_percentage' => 0,
                'tax_amount' => 0,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'status' => Sale::STATUS_DISPATCHED,
                'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
                'payment_method' => 'Cash',
                'setting_id' => $this->setting1->id,
                'paid_amount' => 0,
            ], $attrs));
        };

        $attachSplitTrx = function (string $code, array $sales): PosTransaction {
            $trx = PosTransaction::create([
                'setting_id' => $this->setting1->id,
                'customer_id' => $this->customer->id,
                'created_by' => $this->user->id,
                'owner_user_id' => $this->user->id,
                'last_saved_by' => $this->user->id,
                'source_pos_session_id' => $this->session->id,
                'code' => $code,
                'status' => PosTransaction::STATUS_COMPLETED,
            ]);
            $checkout = PosCheckout::create([
                'setting_id' => $this->setting1->id,
                'pos_transaction_id' => $trx->id,
                'pos_session_id' => $this->session->id,
                'terminal_id' => $this->terminal->id,
                'cashier_user_id' => $this->user->id,
                'customer_id' => $this->customer->id,
                'status' => PosCheckout::STATUS_POSTED,
                'idempotency_key' => uniqid('fn_', true),
                'payload_hash' => 'hash_' . $code,
                'subtotal' => array_sum(array_map(fn ($s) => (float) $s->total_amount, $sales)),
                'grand_total' => array_sum(array_map(fn ($s) => (float) $s->total_amount, $sales)),
                'paid_total' => 0,
                'payment_method_id' => $this->paymentMethod->id,
                'receipt_number' => 'RCP-' . $code,
            ]);
            $trx->update(['completed_checkout_id' => $checkout->id]);
            foreach ($sales as $idx => $sale) {
                PosCheckoutSale::create([
                    'pos_checkout_id' => $checkout->id,
                    'sale_id' => $sale->id,
                    'split_key' => 'owner_' . $idx,
                    'source_setting_id' => $sale->setting_id,
                    'source_location_id' => $sale->setting_id === $this->setting1->id ? $this->location1->id : $this->location2->id,
                    'tax_bucket' => 'NON_PKP',
                    'subtotal' => $sale->total_amount,
                    'grand_total' => $sale->total_amount,
                    'paid_total' => 0,
                ]);
            }

            return $trx;
        };

        // 1. Multi-payment + multi-credit fanout + active transfer + inactivated payment + negative net paid clamp + overpayment clamp
        $sFanA = $makeSplitSale(['reference' => 'FAN-A', 'total_amount' => 1000, 'due_amount' => 1000, 'due_date' => '2026-11-10', 'setting_id' => $this->setting1->id]);
        $sFanB = $makeSplitSale(['reference' => 'FAN-B', 'total_amount' => 500, 'due_amount' => 500, 'due_date' => null, 'setting_id' => $this->setting2->id]);
        $p1 = SalePayment::create(['sale_id' => $sFanA->id, 'amount' => 300, 'date' => '2026-11-12', 'reference' => 'P1', 'payment_method_id' => $this->paymentMethod->id, 'payment_method' => 'Cash', 'status' => SalePayment::STATUS_ACTIVE]);
        $p2 = SalePayment::create(['sale_id' => $sFanA->id, 'amount' => 200, 'date' => '2026-10-15', 'reference' => 'P2', 'payment_method_id' => $this->paymentMethod->id, 'payment_method' => 'Cash', 'status' => SalePayment::STATUS_ACTIVE]);
        // Invalidated payment ignored
        SalePayment::create(['sale_id' => $sFanA->id, 'amount' => 9999, 'date' => '2026-11-13', 'reference' => 'PINV', 'payment_method_id' => $this->paymentMethod->id, 'payment_method' => 'Cash', 'status' => 'CANCELLED']);
        // 2 credit applications on p1 (fanout must NOT double p1->amount!)
        $saleRet1 = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => '2026-11-05', 'reference' => 'SR-FAN-1', 'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id, 'customer_name' => $this->customer->customer_name,
            'total_amount' => 150, 'paid_amount' => 0, 'due_amount' => 150,
            'payment_status' => 'UNPAID', 'payment_method' => 'Cash', 'status' => 'APPROVED',
        ]);
        $saleRet2 = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => '2026-11-05', 'reference' => 'SR-FAN-2', 'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id, 'customer_name' => $this->customer->customer_name,
            'total_amount' => 50, 'paid_amount' => 0, 'due_amount' => 50,
            'payment_status' => 'UNPAID', 'payment_method' => 'Cash', 'status' => 'APPROVED',
        ]);
        $cred1 = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id, 'sale_return_id' => $saleRet1->id, 'amount' => 100, 'remaining_amount' => 100, 'status' => 'open',
        ]);
        $cred2 = \Modules\SalesReturn\Entities\CustomerCredit::create([
            'customer_id' => $this->customer->id, 'sale_return_id' => $saleRet2->id, 'amount' => 50, 'remaining_amount' => 50, 'status' => 'open',
        ]);
        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $p1->id, 'customer_credit_id' => $cred1->id, 'amount' => 100,
        ]);
        \Modules\SalesReturn\Entities\SalePaymentCreditApplication::create([
            'sale_payment_id' => $p1->id, 'customer_credit_id' => $cred2->id, 'amount' => 50,
        ]);
        // Negative net paid on sFanB (outgoing > payments) + overpayment scenario covered on sOver
        \Illuminate\Support\Facades\DB::table('pos_owner_payment_transfers')->insert([
            'execution_key' => 't-out', 'pos_return_id' => 1, 'sale_return_detail_id' => 1,
            'source_sale_id' => $sFanB->id, 'replacement_sale_id' => $sFanA->id, 'original_sale_payment_id' => $p1->id,
            'payment_method' => 'Cash', 'amount' => 150, 'amount_minor' => 15000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Inactive-backed transfer ignored
        $pInact = SalePayment::create(['sale_id' => $sFanA->id, 'amount' => 500, 'date' => '2026-11-13', 'reference' => 'P-INACT', 'payment_method_id' => $this->paymentMethod->id, 'payment_method' => 'Cash', 'status' => 'CANCELLED']);
        \Illuminate\Support\Facades\DB::table('pos_owner_payment_transfers')->insert([
            'execution_key' => 't-inact', 'pos_return_id' => 2, 'sale_return_detail_id' => 2,
            'source_sale_id' => $sFanA->id, 'replacement_sale_id' => $sFanB->id, 'original_sale_payment_id' => $pInact->id,
            'payment_method' => 'Cash', 'amount' => 500, 'amount_minor' => 50000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $trxFan = $attachSplitTrx('STG-FAN', [$sFanA, $sFanB]);

        // 2. Boundary: paid exactly 30 days ago (2026-10-15) -> counts recent; 31 days ago (2026-10-14) -> excluded; future (+1d) -> excluded
        $s30d = $makeSplitSale(['reference' => 'SL-30D', 'total_amount' => 400, 'due_amount' => 0]);
        SalePayment::create(['sale_id' => $s30d->id, 'amount' => 600, 'date' => '2026-10-15', 'reference' => 'P-30D', 'payment_method_id' => $this->paymentMethod->id, 'payment_method' => 'Cash', 'status' => SalePayment::STATUS_ACTIVE]);
        $trx30d = $attachSplitTrx('STG-30D', [$s30d]);

        $sFuture = $makeSplitSale(['reference' => 'SL-FUT', 'total_amount' => 400, 'due_amount' => 0]);
        SalePayment::create(['sale_id' => $sFuture->id, 'amount' => 400, 'date' => '2026-11-15', 'reference' => 'P-FUT', 'payment_method_id' => $this->paymentMethod->id, 'payment_method' => 'Cash', 'status' => SalePayment::STATUS_ACTIVE]);
        $trxFuture = $attachSplitTrx('STG-FUT', [$sFuture]);

        // 3. Archived returned-zero sibling + unpaid sibling
        $sZeroRet = $makeSplitSale(['reference' => 'SL-ZRET', 'total_amount' => 0, 'due_amount' => 0, 'status' => Sale::STATUS_RETURNED, 'archived_at' => now()]);
        $sSibling = $makeSplitSale(['reference' => 'SL-ZSUB', 'total_amount' => 300, 'due_amount' => 300, 'due_date' => '2026-11-12']);
        $trxZeroRet = $attachSplitTrx('STG-ZRET', [$sZeroRet, $sSibling]);

        // Oracle per transaction
        $oracles = [];
        foreach ([$trxFan, $trx30d, $trxFuture, $trxZeroRet] as $t) {
            $fresh = PosTransaction::with(PosSettlementProjectionService::reachableSalesEagerLoad())->findOrFail($t->id);
            $oracles[$t->id] = $this->service->project($fresh);
        }

        $hydratedSales = 0;
        $hydratedPayments = 0;
        Sale::retrieved(function () use (&$hydratedSales) { $hydratedSales++; });
        SalePayment::retrieved(function () use (&$hydratedPayments) { $hydratedPayments++; });

        PosSettlementProjectionService::resetProjectionCount();
        $stagedPage = $readService->paginateFilteredTransactions(
            $this->service->baseEligibleQuery(),
            fn (array $p) => true,
            'id',
            'asc',
            10,
            1
        );
        $summary = $readService->calculateSummaryCards();

        $this->assertSame(0, $hydratedPayments, 'Stage 2 ordinary reads must hydrate 0 SalePayment models.');
        $this->assertSame(0, $hydratedSales, 'Stage 2 ordinary reads must hydrate 0 Sale models.');
        $this->assertSame(0, PosSettlementProjectionService::$projectionCount, 'Ordinary path must not invoke legacy project().');

        foreach ($oracles as $tid => $oracle) {
            $proj = $stagedPage['rowProjections'][$tid];
            foreach (['total_amount', 'effective_paid', 'live_due', 'payment_status', 'effective_due_date', 'is_overdue', 'overdue_amount', 'has_recent_payment_30d', 'is_payable', 'is_valid'] as $k) {
                $this->assertSame($oracle[$k], $proj[$k], "Mismatch on field {$k} for tx {$tid}");
            }
        }

        // Oracle summary check
        $this->assertSame(2, $summary['outstanding']['count']);
        $this->assertSame(1000.0, $summary['outstanding']['total']); // (1000-800=200 + 500) + 300
        $this->assertSame(2, $summary['overdue']['count']);
        $this->assertSame(1, $summary['paid_within_30_days']['count']); // trx30d yes, trxFuture no
        $this->assertSame(600.0, $summary['paid_within_30_days']['total']); // capped overpayment still yields effective_paid 600
    }
}

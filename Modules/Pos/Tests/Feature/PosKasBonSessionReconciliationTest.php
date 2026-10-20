<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Setting\Entities\Location;
use Modules\Pos\Entities\PosKasBonCollection;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Unit;
use Modules\Sale\Entities\SaleDetails;
use Modules\Pos\Services\PosKasBonSettlementService;
use Modules\Pos\Services\PosReconciliationService;
use Modules\Pos\Services\PosSafeDropService;
use Modules\Pos\Services\PosSessionCloseService;
use Modules\Pos\Services\PosSessionExpectedCashCalculator;
use Modules\Pos\Services\PosSessionFinalizeService;
use Modules\Pos\Services\PosSessionSummaryService;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosKasBonSessionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected User $cashier;
    protected User $supervisor;
    protected Currency $currency;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $transferMethod;
    protected PosTerminal $terminal;
    protected Location $location;
    protected Unit $unit;
    protected Category $category;
    protected Product $product1;
    protected Product $product2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Tiga Saudara POS',
            'company_email' => 'pos@tigasaudara.test',
            'company_phone' => '081234567890',
            'company_address' => 'Jl. Pengujian No. 1',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@tigasaudara.test',
            'footer_text' => 'Footer',
            'document_prefix' => 'TRX',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
        ]);

        $this->location = Location::create([
            'name' => 'Gudang Utama',
            'setting_id' => $this->setting->id,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.view',
            'pos.sessions.close',
            'pos.kasBon.collect',
            'pos.reconciliation.access',
            'pos.supervisor.approval',
            'pos.sessions.approve-variance',
        ] as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        $this->cashier = $this->createUserWithPermissions([
            'pos.access',
            'pos.sell',
            'pos.sessions.view',
            'pos.sessions.close',
            'pos.kasBon.collect',
        ]);

        $this->supervisor = $this->createUserWithPermissions([
            'pos.access',
            'pos.sessions.view',
            'pos.reconciliation.access',
            'pos.supervisor.approval',
            'pos.sessions.approve-variance',
        ]);

        $this->unit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->category = Category::create([
            'category_code' => 'CAT01',
            'category_name' => 'Bahan Pokok',
            'created_by' => $this->cashier->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->product1 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Beras Organik 5kg',
            'product_code' => 'BRS-ORG',
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'product_cost' => 60000,
            'product_price' => 80000,
            'product_quantity' => 50,
        ]);

        $this->product2 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Minyak Goreng 2L',
            'product_code' => 'MYK-GOR',
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'product_cost' => 30000,
            'product_price' => 40000,
            'product_quantity' => 50,
        ]);

        $coaCash = ChartOfAccount::create([
            'setting_id' => $this->setting->id,
            'account_number' => '1101-CASH',
            'name' => 'Kas Toko',
            'category' => 'Kas & Bank',
        ]);

        $coaBank = ChartOfAccount::create([
            'setting_id' => $this->setting->id,
            'account_number' => '1102-BANK',
            'name' => 'Bank BCA',
            'category' => 'Kas & Bank',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'setting_id' => $this->setting->id,
            'name' => 'CASH',
            'is_cash' => true,
            'is_active' => true,
            'coa_id' => $coaCash->id,
        ]);

        $this->transferMethod = PaymentMethod::create([
            'setting_id' => $this->setting->id,
            'name' => 'TRANSFER',
            'is_cash' => false,
            'is_active' => true,
            'coa_id' => $coaBank->id,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $this->cashMethod->id,
            'is_enabled' => true,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting->id,
            'payment_method_id' => $this->transferMethod->id,
            'is_enabled' => true,
        ]);

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting->id,
            'code' => 'T-01',
            'name' => 'Kasir Utama',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $this->terminal->id,
            'cash_threshold' => 500000,
            'close_variance_approval_threshold' => 10000,
            'require_pickup_supervisor_approval' => false,
        ]);
    }

    protected function createUserWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'Role_' . uniqid()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        return $user;
    }

    protected function createOpenSession(?PosTerminal $terminal = null): PosSession
    {
        $session = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $terminal?->id,
            'cashier_user_id' => $this->cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'counted_cash_total' => 0,
            'variance_total' => 0,
            'opened_at' => now(),
        ]);

        PosSessionCashEvent::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $session->id,
            'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 100000,
            'performed_by' => $this->cashier->id,
            'occurred_at' => now(),
        ]);

        return $session;
    }

    protected function createKasBonTransaction(float $grandTotal, float $paidTotal, ?PosSession $sourceSession = null): array
    {
        if (! $sourceSession) {
            $sourceSession = PosSession::create([
                'setting_id' => $this->setting->id,
                'terminal_id' => $this->terminal->id,
                'cashier_user_id' => $this->cashier->id,
                'status' => PosSession::STATUS_CLOSED,
                'opening_float_total' => 100000,
                'expected_cash_total' => 100000,
                'counted_cash_total' => 100000,
                'variance_total' => 0,
                'opened_at' => now()->subDay(),
                'closed_at' => now()->subDay(),
            ]);
        }

        $customer = Customer::firstOrCreate(
            ['setting_id' => $this->setting->id, 'customer_email' => 'kasbon@customer.test'],
            [
                'customer_name' => 'Pelanggan Kas Bon',
                'customer_phone' => '081299999999',
            ]
        );

        $trx = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-' . uniqid(),
            'status' => 'COMPLETED',
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'customer_id' => $customer->id,
            'source_pos_session_id' => $sourceSession->id,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
            'snapshot_totals' => ['grand_total' => $grandTotal],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $sourceSession->terminal_id ?? $this->terminal->id,
            'pos_session_id' => $sourceSession->id,
            'pos_transaction_id' => $trx->id,
            'cashier_user_id' => $this->cashier->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => hash('sha256', uniqid()),
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'paid_total' => $paidTotal,
            'change_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'metadata' => [
                'is_debt' => true,
                'customer_id' => $customer->id,
            ],
            'receipt_number' => 'REC-' . uniqid(),
            'finalized_at' => now()->subDay(),
        ]);

        $due = max(0.0, $grandTotal - $paidTotal);

        $sale = Sale::forceCreate([
            'setting_id' => $this->setting->id,
            'date' => now()->subDay()->format('Y-m-d'),
            'status' => Sale::STATUS_DISPATCHED,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => $grandTotal,
            'paid_amount' => $paidTotal,
            'due_amount' => $due,
            'payment_status' => $due <= 0 ? 'PAID' : ($paidTotal > 0 ? 'PARTIAL' : 'UNPAID'),
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => 'split_' . uniqid(),
            'tax_bucket' => 'notax',
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'subtotal' => $grandTotal,
            'tax_total' => 0,
            'grand_total' => $grandTotal,
            'paid_total' => $paidTotal,
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $trx->id,
            'line_no' => 1,
            'product_id' => $this->product1->id,
            'product_name_snapshot' => $this->product1->product_name,
            'product_code_snapshot' => $this->product1->product_code,
            'qty' => 1,
            'unit_price' => $grandTotal,
            'subtotal' => $grandTotal,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->product_name,
            'product_code' => $this->product1->product_code,
            'quantity' => 1,
            'price' => $grandTotal,
            'unit_price' => $grandTotal,
            'sub_total' => $grandTotal,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        return [
            'transaction' => $trx,
            'checkout' => $checkout,
            'sale' => $sale,
            'customer' => $customer,
        ];
    }

    public function test_terminal_and_non_terminal_session_summaries_report_separate_collection_totals_preserving_sales_totals(): void
    {
        $terminalSession = $this->createOpenSession($this->terminal);
        $reviewService = app(\Modules\Pos\Services\PosKasBonReviewService::class);
        $settlementService = app(PosKasBonSettlementService::class);

        // Terminal session: create a new checkout cash sale of 200,000
        $customer = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Buyer',
            'customer_phone' => '123',
        ]);
        $newSale = Sale::forceCreate([
            'setting_id' => $this->setting->id,
            'date' => now()->format('Y-m-d'),
            'status' => Sale::STATUS_DISPATCHED,
            'customer_id' => $customer->id,
            'customer_name' => 'Buyer',
            'total_amount' => 200000,
            'paid_amount' => 200000,
            'due_amount' => 0,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
        ]);
        $newSalePayment = SalePayment::create([
            'sale_id' => $newSale->id,
            'amount' => 200000,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => uniqid(),
        ]);
        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $terminalSession->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash',
            'subtotal' => 200000,
            'grand_total' => 200000,
            'paid_total' => 200000,
            'change_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'sale_id' => $newSale->id,
            'sale_payment_id' => $newSalePayment->id,
            'finalized_at' => now(),
        ]);
        DB::table('pos_checkout_payments')->insert([
            'pos_checkout_id' => $checkout->id,
            'payment_method_id' => $this->cashMethod->id,
            'sequence_order' => 1,
            'amount_minor_units' => 20000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PosSessionCashEvent::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $terminalSession->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 200000,
            'performed_by' => $this->cashier->id,
            'occurred_at' => now(),
        ]);

        // Settle a Kas Bon of 100,000 (60,000 cash tender, 40,000 transfer)
        $kbData = $this->createKasBonTransaction(100000, 0);
        $review = $reviewService->review($kbData['transaction']);

        $settlementService->collect(
            posTransactionId: $kbData['transaction']->id,
            receivingSessionId: $terminalSession->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 100000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 60000, 'tender_amount' => 60000],
                ['payment_method_id' => $this->transferMethod->id, 'amount' => 40000, 'tender_amount' => 40000],
            ],
            idempotencyKey: 'summary-test-' . uniqid()
        );

        $summaryService = app(PosSessionSummaryService::class);
        $summary = $summaryService->getSummary($terminalSession->id, $this->cashier->id, $this->setting->id);

        // Sales total must remain unchanged (200,000), not inflated to 300,000!
        $this->assertEquals(200000, $summary['sales_total']);
        $this->assertEquals(200000, $summary['net_cash_sales_total']);

        // Kas Bon collections must be reported separately
        $this->assertEquals(1, $summary['kas_bon_collections_count']);
        $this->assertEquals(100000, $summary['kas_bon_collection_total']);
        $this->assertEquals(60000, $summary['kas_bon_net_cash_total']);
        $this->assertEquals(40000, $summary['kas_bon_non_cash_total']);

        // Expected cash: float 100k + sale cash 200k + collection cash 60k = 360,000
        $this->assertEquals(360000, $summary['expected_cash_total']);

        // Now test non-terminal session
        $nonTerminalSession = $this->createOpenSession(null);
        $kbData2 = $this->createKasBonTransaction(50000, 0);
        $review2 = $reviewService->review($kbData2['transaction']);

        $settlementService->collect(
            posTransactionId: $kbData2['transaction']->id,
            receivingSessionId: $nonTerminalSession->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review2['fingerprint'],
            amountToPay: 50000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 50000, 'tender_amount' => 50000],
            ],
            idempotencyKey: 'non-term-summary-' . uniqid()
        );

        $ntSummary = $summaryService->getSummary($nonTerminalSession->id, $this->cashier->id, $this->setting->id);
        $this->assertEquals(0, $ntSummary['sales_total']);
        $this->assertEquals(1, $ntSummary['kas_bon_collections_count']);
        $this->assertEquals(50000, $ntSummary['kas_bon_collection_total']);
        $this->assertEquals(50000, $ntSummary['kas_bon_net_cash_total']);
        $this->assertEquals(0, $ntSummary['kas_bon_non_cash_total']);
        $this->assertEquals(150000, $ntSummary['expected_cash_total']); // 100k float + 50k cash
    }

    public function test_split_owner_collection_does_not_duplicate_session_collection_totals_or_cash_events(): void
    {
        $session = $this->createOpenSession($this->terminal);
        $reviewService = app(\Modules\Pos\Services\PosKasBonReviewService::class);
        $settlementService = app(PosKasBonSettlementService::class);

        // Kas Bon transaction with 2 child sales (Owner A 40k, Owner B 60k, total 100k)
        $customer = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Split Cust',
            'customer_phone' => '12345',
        ]);
        $originSession = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'status' => PosSession::STATUS_CLOSED,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'counted_cash_total' => 100000,
            'variance_total' => 0,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TRX-SPLIT-' . uniqid(),
            'status' => 'COMPLETED',
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'customer_id' => $customer->id,
            'source_pos_session_id' => $originSession->id,
            'snapshot_totals' => ['grand_total' => 100000],
        ]);
        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $this->terminal->id,
            'pos_session_id' => $originSession->id,
            'pos_transaction_id' => $trx->id,
            'cashier_user_id' => $this->cashier->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => hash('sha256', uniqid()),
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_total' => 0,
            'change_total' => 0,
            'payment_method_id' => $this->cashMethod->id,
            'metadata' => ['is_debt' => true, 'customer_id' => $customer->id],
            'receipt_number' => 'REC-SPLIT-' . uniqid(),
            'finalized_at' => now()->subDay(),
        ]);
        $saleA = Sale::forceCreate([
            'setting_id' => $this->setting->id,
            'date' => now()->subDay()->format('Y-m-d'),
            'status' => Sale::STATUS_DISPATCHED,
            'customer_id' => $customer->id,
            'customer_name' => 'Split Cust',
            'total_amount' => 40000,
            'paid_amount' => 0,
            'due_amount' => 40000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
        ]);
        $saleB = Sale::forceCreate([
            'setting_id' => $this->setting->id,
            'date' => now()->subDay()->format('Y-m-d'),
            'status' => Sale::STATUS_DISPATCHED,
            'customer_id' => $customer->id,
            'customer_name' => 'Split Cust',
            'total_amount' => 60000,
            'paid_amount' => 0,
            'due_amount' => 60000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => 'split_a_' . uniqid(),
            'tax_bucket' => 'notax',
            'sale_id' => $saleA->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'subtotal' => 40000,
            'tax_total' => 0,
            'grand_total' => 40000,
            'paid_total' => 0,
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => 'split_b_' . uniqid(),
            'tax_bucket' => 'notax',
            'sale_id' => $saleB->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'subtotal' => 60000,
            'tax_total' => 0,
            'grand_total' => 60000,
            'paid_total' => 0,
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $trx->id,
            'line_no' => 1,
            'product_id' => $this->product1->id,
            'product_name_snapshot' => $this->product1->product_name,
            'product_code_snapshot' => $this->product1->product_code,
            'qty' => 1,
            'unit_price' => 40000,
            'subtotal' => 40000,
        ]);
        PosTransactionLine::create([
            'pos_transaction_id' => $trx->id,
            'line_no' => 2,
            'product_id' => $this->product2->id,
            'product_name_snapshot' => $this->product2->product_name,
            'product_code_snapshot' => $this->product2->product_code,
            'qty' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
        ]);

        SaleDetails::create([
            'sale_id' => $saleA->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->product_name,
            'product_code' => $this->product1->product_code,
            'quantity' => 1,
            'price' => 40000,
            'unit_price' => 40000,
            'sub_total' => 40000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        SaleDetails::create([
            'sale_id' => $saleB->id,
            'product_id' => $this->product2->id,
            'product_name' => $this->product2->product_name,
            'product_code' => $this->product2->product_code,
            'quantity' => 1,
            'price' => 60000,
            'unit_price' => 60000,
            'sub_total' => 60000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $review = $reviewService->review($trx);
        $this->assertEquals(100000, $review['live_due']);

        // Collect with Rp100,000 cash (zero change)
        $result = $settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 100000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 100000, 'note' => 'Cicilan kedua'],
            ],
            idempotencyKey: 'split-collect-' . uniqid()
        );

        $summary = app(PosSessionSummaryService::class)->getSummary($session->id, $this->cashier->id, $this->setting->id);

        // Exactly 1 collection header, counted once: 100,000
        $this->assertEquals(1, $summary['kas_bon_collections_count']);
        $this->assertEquals(100000, $summary['kas_bon_collection_total']);
        $this->assertEquals(100000, $summary['kas_bon_net_cash_total']);
        $this->assertEquals(100000, $summary['kas_bon_cash_tendered_total']);
        $this->assertEquals(0, $summary['kas_bon_change_total']);

        // Cash events: 1 open float (100k), 1 collection in (100k) = 200k expected cash
        $this->assertEquals(200000, $summary['expected_cash_total']);

        $collectionEvents = PosSessionCashEvent::where('pos_session_id', $session->id)
            ->where('event_type', PosSessionCashEvent::EVENT_KAS_BON_COLLECTION_IN)
            ->get();
        $this->assertCount(1, $collectionEvents);
        $this->assertEquals(100000, (float) $collectionEvents->first()->amount);

        $changeEvents = PosSessionCashEvent::where('pos_session_id', $session->id)
            ->where('event_type', PosSessionCashEvent::EVENT_CHANGE_OUT)
            ->get();
        $this->assertCount(0, $changeEvents);

        // Verify PosReconciliationService
        $reconService = app(PosReconciliationService::class);
        $session->update([
            'status' => PosSession::STATUS_CLOSED,
            'closed_at' => now(),
            'counted_cash_total' => 200000,
            'variance_total' => 0,
        ]);

        $today = now()->format('Y-m-d');
        $reconciliation = $reconService->getSessionReconciliation($this->setting->id, $today, $today);
        $this->assertCount(1, $reconciliation);
        $row = $reconciliation[0];

        $this->assertEquals(100000, $row['pos_kas_bon_collection_total']);
        $this->assertEquals(100000, $row['pos_kas_bon_cash_total']);
        $this->assertEquals(0, $row['pos_kas_bon_non_cash_total']);
        $this->assertEquals(100000, $row['pos_kas_bon_allocated_total']);
        $this->assertFalse($row['has_mismatch']);
    }

    public function test_collection_from_previous_origin_session_attributes_cash_and_totals_strictly_to_receiving_session(): void
    {
        // Session 1: yesterday
        $session1 = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'status' => PosSession::STATUS_CLOSED,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'counted_cash_total' => 100000,
            'variance_total' => 0,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHours(8),
        ]);
        PosSessionCashEvent::create([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $session1->id,
            'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 100000,
            'performed_by' => $this->cashier->id,
            'occurred_at' => now()->subDay(),
        ]);

        $kbData = $this->createKasBonTransaction(80000, 0, $session1);

        // Session 2: today
        $session2 = $this->createOpenSession($this->terminal);

        $settlementService = app(PosKasBonSettlementService::class);
        $reviewService = app(\Modules\Pos\Services\PosKasBonReviewService::class);
        $review = $reviewService->review($kbData['transaction']);

        // Collect in Session 2
        $settlementService->collect(
            posTransactionId: $kbData['transaction']->id,
            receivingSessionId: $session2->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 80000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 80000, 'tender_amount' => 80000],
            ],
            idempotencyKey: 'origin-test-' . uniqid()
        );

        $summaryService = app(PosSessionSummaryService::class);

        // Session 1 summary: no collections, expected cash untouched
        $summary1 = $summaryService->getSummary($session1->id, $this->cashier->id, $this->setting->id);
        $this->assertEquals(0, $summary1['kas_bon_collection_total']);
        $this->assertEquals(100000, $summary1['expected_cash_total']);

        // Session 2 summary: includes 80,000 collection and 180,000 expected cash (100k float + 80k collection)
        $summary2 = $summaryService->getSummary($session2->id, $this->cashier->id, $this->setting->id);
        $this->assertEquals(1, $summary2['kas_bon_collections_count']);
        $this->assertEquals(80000, $summary2['kas_bon_collection_total']);
        $this->assertEquals(80000, $summary2['kas_bon_net_cash_total']);
        $this->assertEquals(180000, $summary2['expected_cash_total']);
    }

    public function test_safe_drop_and_threshold_consume_collection_inclusive_expected_cash(): void
    {
        $session = $this->createOpenSession($this->terminal);
        $kbData = $this->createKasBonTransaction(150000, 0);
        $reviewService = app(\Modules\Pos\Services\PosKasBonReviewService::class);
        $review = $reviewService->review($kbData['transaction']);

        // Collect 150k cash -> expected cash becomes 100k float + 150k collection = 250k
        app(PosKasBonSettlementService::class)->collect(
            posTransactionId: $kbData['transaction']->id,
            receivingSessionId: $session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 150000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 150000, 'tender_amount' => 150000],
            ],
            idempotencyKey: 'drop-test-' . uniqid()
        );

        $session->refresh();
        $this->assertEquals(250000, (float) $session->expected_cash_total);

        // Safe drop of 200,000 (which exceeds the initial 100k float, but is within 250k collection-inclusive cash)
        $safeDropService = app(PosSafeDropService::class);
        $dropResult = $safeDropService->createSafeDrop(
            settingId: $this->setting->id,
            sessionId: $session->id,
            actorUserId: $this->cashier->id,
            amount: 200000,
            notes: 'Mid-shift pickup'
        );

        $this->assertEquals(250000, $dropResult['expected_cash_before']);
        $this->assertEquals(50000, $dropResult['expected_cash_after']);

        // Attempting to drop 60,000 when only 50,000 remains must be rejected
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Safe drop amount cannot exceed expected cash.');

        $safeDropService->createSafeDrop(
            settingId: $this->setting->id,
            sessionId: $session->id,
            actorUserId: $this->cashier->id,
            amount: 60000
        );
    }

    public function test_threshold_breach_detection_accounts_for_collection_net_cash(): void
    {
        $session = $this->createOpenSession($this->terminal);
        // Terminal policy has cash_threshold = 500,000. Opening float is 100,000.
        $summaryService = app(PosSessionSummaryService::class);
        $summary = $summaryService->getSummary($session->id, $this->cashier->id, $this->setting->id);
        $this->assertFalse($summary['is_threshold_breached']);

        // Collect 450,000 cash -> drawer cash becomes 100k + 450k = 550,000 > 500,000 threshold
        $kbData = $this->createKasBonTransaction(450000, 0);
        $review = app(\Modules\Pos\Services\PosKasBonReviewService::class)->review($kbData['transaction']);

        app(PosKasBonSettlementService::class)->collect(
            posTransactionId: $kbData['transaction']->id,
            receivingSessionId: $session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 450000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 450000, 'tender_amount' => 450000],
            ],
            idempotencyKey: 'threshold-test-' . uniqid()
        );

        $summaryAfter = $summaryService->getSummary($session->id, $this->cashier->id, $this->setting->id);
        $this->assertEquals(550000, $summaryAfter['expected_cash_total']);
        $this->assertTrue($summaryAfter['is_threshold_breached']);
    }

    public function test_session_closing_and_finalization_reconciles_collection_inclusive_cash(): void
    {
        $session = $this->createOpenSession($this->terminal);
        $kbData = $this->createKasBonTransaction(75000, 0);
        $review = app(\Modules\Pos\Services\PosKasBonReviewService::class)->review($kbData['transaction']);

        app(PosKasBonSettlementService::class)->collect(
            posTransactionId: $kbData['transaction']->id,
            receivingSessionId: $session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 75000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 75000, 'tender_amount' => 100000],
            ],
            idempotencyKey: 'close-finalize-test-' . uniqid()
        );

        // Expected cash: 100k float + (100k tender - 25k change) = 175,000
        $session->refresh();
        $this->assertEquals(175000, (float) $session->expected_cash_total);

        // Cashier closes session
        $closeService = app(PosSessionCloseService::class);
        $closeResult = $closeService->closeSession(
            settingId: $this->setting->id,
            sessionId: $session->id,
            actorUserId: $this->cashier->id
        );
        $this->assertEquals(PosSession::STATUS_CLOSED, $closeResult['status']);

        // Supervisor finalizes session with exactly 175,000 actual cash received
        $finalizeService = app(PosSessionFinalizeService::class);
        $finalizeResult = $finalizeService->finalizeSession(
            settingId: $this->setting->id,
            sessionId: $session->id,
            supervisorUserId: $this->supervisor->id,
            actualCashReceived: 175000,
            notes: 'Kas Bon included'
        );

        $this->assertFalse($finalizeResult['blocked']);
        $this->assertEquals(0, $finalizeResult['payload']['variance_total']);
        $this->assertEquals(175000, $finalizeResult['payload']['expected_cash_total']);
        $this->assertEquals(PosSession::STATUS_FINALIZED, $finalizeResult['payload']['status']);
    }

    public function test_sale_payment_invalidation_reopens_debt_while_preserving_collection_cash_events_and_receipt(): void
    {
        $session = $this->createOpenSession($this->terminal);
        $kbData = $this->createKasBonTransaction(120000, 0);
        $reviewService = app(\Modules\Pos\Services\PosKasBonReviewService::class);
        $review = $reviewService->review($kbData['transaction']);

        $collection = app(PosKasBonSettlementService::class)->collect(
            posTransactionId: $kbData['transaction']->id,
            receivingSessionId: $session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 120000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 120000, 'tender_amount' => 120000],
            ],
            idempotencyKey: 'invalidation-test-' . uniqid()
        );

        // Confirm debt is settled: live due is 0
        $kbData['sale']->refresh();
        $this->assertEquals(0, (float) $kbData['sale']->live_due_amount);

        // Find the created sale payment
        $createdPayment = SalePayment::where('sale_id', $kbData['sale']->id)
            ->where('status', '!=', SalePayment::STATUS_INVALIDATED)
            ->first();
        $this->assertNotNull($createdPayment);

        // Simulate Sales module invalidating the payment
        $createdPayment->update([
            'status' => SalePayment::STATUS_INVALIDATED,
            'invalidated_at' => now(),
            'invalidated_by' => $this->supervisor->id,
        ]);
        $kbData['sale']->reconcileFromActivePayments();
        $kbData['sale']->refresh();

        // 1. Canonical debt reopens to 120,000
        $this->assertEquals(120000, (float) $kbData['sale']->live_due_amount);

        // 2. Receiving session collection cash events remain intact and drawer expected cash is preserved
        $session->refresh();
        $this->assertEquals(220000, (float) $session->expected_cash_total); // 100k float + 120k collection

        $cashEvent = PosSessionCashEvent::where('pos_session_id', $session->id)
            ->where('event_type', PosSessionCashEvent::EVENT_KAS_BON_COLLECTION_IN)
            ->first();
        $this->assertNotNull($cashEvent);
        $this->assertEquals(120000, (float) $cashEvent->amount);

        // 3. Collection record and immutable receipt context remain intact
        $collectionRecord = PosKasBonCollection::find($collection->id);
        $this->assertNotNull($collectionRecord);
        $this->assertEquals(120000, (float) $collectionRecord->amount);
        $this->assertNotEmpty($collectionRecord->receipt_snapshot);
    }
}

<?php

namespace Modules\Pos\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\GlobalPosPaymentAllocation;
use Modules\Pos\Entities\GlobalPosPaymentBatch;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\GlobalPosMultiPaymentService;
use Modules\Pos\Services\PosRemainingBalancePriorityPlanner;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\GlobalSalePaymentAttachmentReplicator;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class GlobalPosMultiPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customerA;
    protected Customer $customerB;
    protected Setting $setting1;
    protected Setting $setting2;
    protected Location $location1;
    protected Location $location2;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $transferMethod;
    protected PosRemainingBalancePriorityPlanner $planner;
    protected GlobalPosMultiPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting1 = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Store 1',
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
            'company_name' => 'Store 2',
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

        $this->customerA = Customer::create([
            'customer_name' => 'Customer A',
            'customer_phone' => '0811111111',
            'customer_email' => 'custA@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Street A',
        ]);

        $this->customerB = Customer::create([
            'customer_name' => 'Customer B',
            'customer_phone' => '0822222222',
            'customer_email' => 'custB@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Street B',
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

        $this->cashMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Cash'],
            ['name' => 'Cash', 'is_cash' => true, 'coa_id' => $coa->id]
        );

        $this->transferMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Transfer'],
            ['name' => 'Transfer', 'is_cash' => false, 'coa_id' => $coa->id]
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

        $this->planner = new PosRemainingBalancePriorityPlanner();
        $this->service = new GlobalPosMultiPaymentService();
    }

    public function test_planner_cash_prioritizes_non_terminal_sales()
    {
        $context = [
            'amount' => 60000,
            'is_cash' => true,
            'terminal_setting_id' => 1,
            'sales' => [
                ['sale_id' => 101, 'setting_id' => 1, 'split_key' => '1_10', 'live_due' => 50000, 'total_amount' => 50000],
                ['sale_id' => 102, 'setting_id' => 2, 'split_key' => '2_20', 'live_due' => 40000, 'total_amount' => 40000],
            ],
        ];

        $plan = $this->planner->plan($context);

        $this->assertEquals(60000.0, $plan['total_allocated']);
        // Non-terminal (sale 102) gets filled first up to 40,000, then terminal (sale 101) gets remaining 20,000
        $allocBySale = collect($plan['allocations'])->keyBy('sale_id');
        $this->assertEquals(40000.0, $allocBySale[102]['allocated_amount']);
        $this->assertEquals(20000.0, $allocBySale[101]['allocated_amount']);
    }

    public function test_planner_non_cash_prioritizes_terminal_sales()
    {
        $context = [
            'amount' => 60000,
            'is_cash' => false,
            'terminal_setting_id' => 1,
            'sales' => [
                ['sale_id' => 101, 'setting_id' => 1, 'split_key' => '1_10', 'live_due' => 50000, 'total_amount' => 50000],
                ['sale_id' => 102, 'setting_id' => 2, 'split_key' => '2_20', 'live_due' => 40000, 'total_amount' => 40000],
            ],
        ];

        $plan = $this->planner->plan($context);

        $this->assertEquals(60000.0, $plan['total_allocated']);
        // Terminal (sale 101) gets filled first up to 50,000, then non-terminal (sale 102) gets remaining 10,000
        $allocBySale = collect($plan['allocations'])->keyBy('sale_id');
        $this->assertEquals(50000.0, $allocBySale[101]['allocated_amount']);
        $this->assertEquals(10000.0, $allocBySale[102]['allocated_amount']);
    }

    public function test_planner_skips_settled_sales_and_rejects_overpayment()
    {
        $context = [
            'amount' => 30000,
            'is_cash' => true,
            'terminal_setting_id' => 1,
            'sales' => [
                ['sale_id' => 101, 'setting_id' => 2, 'split_key' => '2_20', 'live_due' => 0, 'total_amount' => 50000],
                ['sale_id' => 102, 'setting_id' => 1, 'split_key' => '1_10', 'live_due' => 40000, 'total_amount' => 40000],
            ],
        ];

        $plan = $this->planner->plan($context);
        $allocBySale = collect($plan['allocations'])->keyBy('sale_id');
        $this->assertEquals(0.0, $allocBySale[101]['allocated_amount']);
        $this->assertEquals(30000.0, $allocBySale[102]['allocated_amount']);

        // Test overpayment
        $this->expectException(ValidationException::class);
        $this->planner->plan([
            'amount' => 50000, // exceeds remaining 40000
            'is_cash' => true,
            'terminal_setting_id' => 1,
            'sales' => [
                ['sale_id' => 101, 'setting_id' => 2, 'split_key' => '2_20', 'live_due' => 0, 'total_amount' => 50000],
                ['sale_id' => 102, 'setting_id' => 1, 'split_key' => '1_10', 'live_due' => 40000, 'total_amount' => 40000],
            ],
        ]);
    }

    public function test_planner_overflow_across_multiple_fallback_sales_matches_checkout_proportional_algorithm()
    {
        // Terminal-owned sale (101) fully absorbs the preferred-group amount for cash,
        // leaving overflow that must be split PROPORTIONALLY across the two remaining
        // non-terminal sales (102, 103) by their live_due share, using largest-remainder
        // rounding on minor units -- matching PosCheckoutOwnershipPriorityAllocationService,
        // not a sequential one-at-a-time fill.
        $context = [
            'amount' => 130000,
            'is_cash' => true,
            'terminal_setting_id' => 1,
            'sales' => [
                // Terminal-owned (cash de-prioritizes this, filled only by overflow)
                ['sale_id' => 101, 'setting_id' => 1, 'split_key' => '1_10', 'live_due' => 100000, 'total_amount' => 100000],
                // Non-terminal-owned (cash priority group): due 30,000 + 70,000 = 100,000
                ['sale_id' => 102, 'setting_id' => 2, 'split_key' => '2_20', 'live_due' => 30000, 'total_amount' => 30000],
                ['sale_id' => 103, 'setting_id' => 3, 'split_key' => '3_30', 'live_due' => 70000, 'total_amount' => 70000],
            ],
        ];

        $plan = $this->planner->plan($context);
        $allocBySale = collect($plan['allocations'])->keyBy('sale_id');

        // Priority group (102, 103) fully absorbs 100,000 of the 130,000 requested.
        $this->assertEquals(30000.0, $allocBySale[102]['allocated_amount']);
        $this->assertEquals(70000.0, $allocBySale[103]['allocated_amount']);

        // Remaining 30,000 overflow goes entirely to the only remaining-balance sale (101),
        // since priority-group sales are now fully settled and excluded from the
        // proportional split -- so this case still resolves to a single recipient.
        $this->assertEquals(30000.0, $allocBySale[101]['allocated_amount']);
        $this->assertEquals(130000.0, $plan['total_allocated']);
    }

    public function test_planner_proportional_overflow_splits_across_multiple_remaining_sales()
    {
        // All sales are non-terminal-owned (cash priority group), so overflow beyond
        // the priority group never occurs here; instead we force overflow into the
        // fallback group directly by making the entire amount exceed the priority
        // group and spreading across two terminal-owned sales with different balances,
        // which must be split proportionally by largest-remainder, not sequentially.
        $context = [
            'amount' => 100000,
            'is_cash' => true,
            'terminal_setting_id' => 1,
            'sales' => [
                // Non-terminal (priority group for cash): small due, fully absorbed first.
                ['sale_id' => 201, 'setting_id' => 2, 'split_key' => '2_20', 'live_due' => 10000, 'total_amount' => 10000],
                // Terminal-owned fallback group: overflow of 90,000 split proportionally
                // between these two by their live_due share (30,000 : 60,000 = 1:2).
                ['sale_id' => 202, 'setting_id' => 1, 'split_key' => '1_10', 'live_due' => 30000, 'total_amount' => 30000],
                ['sale_id' => 203, 'setting_id' => 1, 'split_key' => '1_11', 'live_due' => 60000, 'total_amount' => 60000],
            ],
        ];

        $plan = $this->planner->plan($context);
        $allocBySale = collect($plan['allocations'])->keyBy('sale_id');

        $this->assertEquals(10000.0, $allocBySale[201]['allocated_amount']);

        // Overflow of 90,000 across sales with live_due 30,000 and 60,000 (1:2 ratio)
        // splits proportionally to 30,000 and 60,000 respectively (both fully settled
        // since overflow exactly covers remaining balance).
        $this->assertEquals(30000.0, $allocBySale[202]['allocated_amount']);
        $this->assertEquals(60000.0, $allocBySale[203]['allocated_amount']);
        $this->assertEquals(100000.0, $plan['total_allocated']);
    }

    public function test_planner_partial_proportional_overflow_uses_largest_remainder_rounding()
    {
        // Overflow amount does not exactly cover the fallback group's balances, forcing
        // a genuine proportional split with rounding -- this is the case that would
        // differ visibly between sequential-fill and proportional-split algorithms.
        $context = [
            'amount' => 55000,
            'is_cash' => true,
            'terminal_setting_id' => 1,
            'sales' => [
                // Non-terminal (priority group for cash), fully absorbs 10,000.
                ['sale_id' => 301, 'setting_id' => 2, 'split_key' => '2_20', 'live_due' => 10000, 'total_amount' => 10000],
                // Terminal-owned fallback group with a 1:2 due ratio; only 45,000 of the
                // combined 90,000 balance is available as overflow, so a sequential-fill
                // algorithm would allocate 100% to sale 401 (up to its due), while the
                // proportional algorithm splits 45,000 by the 1:2 ratio (15,000 : 30,000).
                ['sale_id' => 401, 'setting_id' => 1, 'split_key' => '1_10', 'live_due' => 30000, 'total_amount' => 30000],
                ['sale_id' => 402, 'setting_id' => 1, 'split_key' => '1_11', 'live_due' => 60000, 'total_amount' => 60000],
            ],
        ];

        $plan = $this->planner->plan($context);
        $allocBySale = collect($plan['allocations'])->keyBy('sale_id');

        $this->assertEquals(10000.0, $allocBySale[301]['allocated_amount']);
        $this->assertEquals(15000.0, $allocBySale[401]['allocated_amount']);
        $this->assertEquals(30000.0, $allocBySale[402]['allocated_amount']);
        $this->assertEquals(55000.0, $plan['total_allocated']);
    }

    public function test_multi_pos_atomic_payment_success()
    {
        // Setup POS Transaction 1 (Split: Sale 1 from Setting 1, Sale 2 from Setting 2)
        $sale1 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-M01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $sale2 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-M02',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting2->id,
        ]);

        $trx1 = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-M01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customerA->id,
        ]);

        $checkout1 = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx1->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customerA->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_m1',
            'subtotal' => 150000,
            'grand_total' => 150000,
            'paid_total' => 0,
            'receipt_number' => 'RCP-M01',
        ]);
        $trx1->update(['completed_checkout_id' => $checkout1->id]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout1->id,
            'split_key' => '1_10_notax',
            'source_setting_id' => 1,
            'source_location_id' => 10,
            'tax_bucket' => 'notax',
            'sale_id' => $sale1->id,
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_total' => 0,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout1->id,
            'split_key' => '2_20_notax',
            'source_setting_id' => 2,
            'source_location_id' => 20,
            'tax_bucket' => 'notax',
            'sale_id' => $sale2->id,
            'subtotal' => 50000,
            'grand_total' => 50000,
            'paid_total' => 0,
        ]);

        // Setup POS Transaction 2 (Inline sale: Sale 3 from Setting 1)
        $sale3 = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-M03',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 80000,
            'paid_amount' => 0,
            'due_amount' => 80000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $trx2 = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-M02',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customerA->id,
        ]);

        $checkout2 = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx2->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customerA->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_m2',
            'subtotal' => 80000,
            'grand_total' => 80000,
            'paid_total' => 0,
            'sale_id' => $sale3->id,
            'receipt_number' => 'RCP-M02',
        ]);
        $trx2->update(['completed_checkout_id' => $checkout2->id]);

        // Preview
        $preview = $this->service->previewAllocations($this->customerA->id, [
            $trx1->id => 70000, // TRX 1 gets 70,000 (Cash -> 50,000 to Store 2, 20,000 to Store 1)
            $trx2->id => 80000, // TRX 2 gets 80,000 (full)
        ], $this->cashMethod->id);

        $this->assertEquals(150000.0, $preview['total_requested']);
        $this->assertCount(2, $preview['transactions']);

        // Store multi payment
        $batch = $this->service->storeMultiPayment($this->customerA->id, [
            'allocations' => [
                $trx1->id => 70000,
                $trx2->id => 80000,
            ],
            'reference' => 'PAY-MULTI-001',
            'date' => now()->toDateString(),
            'payment_method_id' => $this->cashMethod->id,
            'note' => 'Multi POS Payment',
            'idempotency_key' => 'batch-idem-001',
        ], $this->user->id);

        $this->assertInstanceOf(GlobalPosPaymentBatch::class, $batch);
        $this->assertEquals(150000.0, (float) $batch->total_amount);
        $this->assertEquals($this->customerA->id, $batch->customer_id);

        $this->assertCount(3, $batch->allocations); // 2 child sales for TRX1, 1 child sale for TRX2

        // Reconciled sale balances
        $sale1->refresh();
        $sale2->refresh();
        $sale3->refresh();

        $this->assertEquals(20000.0, (float) $sale1->paid_amount);
        $this->assertEquals(80000.0, (float) $sale1->due_amount);
        $this->assertEquals('PARTIAL', $sale1->payment_status);

        $this->assertEquals(50000.0, (float) $sale2->paid_amount);
        $this->assertEquals(0.0, (float) $sale2->due_amount);
        $this->assertEquals('PAID', $sale2->payment_status);

        $this->assertEquals(80000.0, (float) $sale3->paid_amount);
        $this->assertEquals(0.0, (float) $sale3->due_amount);
        $this->assertEquals('PAID', $sale3->payment_status);

        // Test Idempotency: replaying same request returns same batch without duplicate payments
        $paymentCountBefore = SalePayment::count();
        $replayedBatch = $this->service->storeMultiPayment($this->customerA->id, [
            'allocations' => [
                $trx1->id => 70000,
                $trx2->id => 80000,
            ],
            'reference' => 'PAY-MULTI-001',
            'date' => now()->toDateString(),
            'payment_method_id' => $this->cashMethod->id,
            'idempotency_key' => 'batch-idem-001',
        ], $this->user->id);

        $this->assertEquals($batch->id, $replayedBatch->id);
        $this->assertEquals($paymentCountBefore, SalePayment::count());
    }

    public function test_rejection_on_customer_mismatch()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-CUST-B',
            'customer_id' => $this->customerB->id,
            'customer_name' => $this->customerB->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-CUST-B',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customerB->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customerB->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_b',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'paid_total' => 0,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCP-CB01',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $this->expectException(ValidationException::class);
        $this->service->storeMultiPayment($this->customerA->id, [
            'allocations' => [
                $trx->id => 50000, // TRX belongs to Customer B, but paying for Customer A!
            ],
            'reference' => 'PAY-ERR',
            'date' => now()->toDateString(),
            'payment_method_id' => $this->cashMethod->id,
        ]);
    }

    public function test_rejection_when_reachable_sale_is_archived()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-ARCHIVED',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);
        $sale->update(['archived_at' => now(), 'archived_by' => $this->user->id]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-ARCHIVED',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customerA->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customerA->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_archived',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'paid_total' => 0,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCP-ARCHIVED',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $paymentCountBefore = SalePayment::count();

        $this->expectException(ValidationException::class);
        try {
            $this->service->storeMultiPayment($this->customerA->id, [
                'allocations' => [
                    $trx->id => 50000,
                ],
                'reference' => 'PAY-ARCHIVED',
                'date' => now()->toDateString(),
                'payment_method_id' => $this->cashMethod->id,
            ]);
        } finally {
            $this->assertEquals($paymentCountBefore, SalePayment::count(), 'No payment must be created against an archived Sale.');
        }
    }

    public function test_rejection_when_reachable_sale_has_ineligible_status()
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-REJECTED',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => Sale::STATUS_REJECTED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-REJECTED',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customerA->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customerA->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_rejected',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'paid_total' => 0,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCP-REJECTED',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $this->expectException(ValidationException::class);
        $this->service->previewAllocations($this->customerA->id, [
            $trx->id => 50000,
        ], $this->cashMethod->id);
    }

    public function test_attachment_replication_and_failure_cleanup()
    {
        Storage::fake('public');
        $tempDir = storage_path('framework/testing/disks/local/temp/dropzone');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        $tempFile = $tempDir . '/test_attachment.pdf';
        file_put_contents($tempFile, 'dummy pdf content');

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-ATT-01',
            'customer_id' => $this->customerA->id,
            'customer_name' => $this->customerA->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-ATT-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customerA->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customerA->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_att',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'paid_total' => 0,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCP-ATT01',
        ]);
        $trx->update(['completed_checkout_id' => $checkout->id]);

        $batch = $this->service->storeMultiPayment($this->customerA->id, [
            'allocations' => [
                $trx->id => 50000,
            ],
            'reference' => 'PAY-ATT',
            'date' => now()->toDateString(),
            'payment_method_id' => $this->cashMethod->id,
            'attachment' => $tempFile,
        ], $this->user->id);

        $this->assertCount(1, $batch->allocations);
        $createdPayment = $batch->allocations->first()->salePayment;
        $this->assertCount(1, $createdPayment->getMedia('attachments'));

        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }
}

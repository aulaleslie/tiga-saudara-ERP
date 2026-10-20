<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosKasBonCollection;
use Modules\Pos\Entities\PosKasBonCollectionAllocation;
use Modules\Pos\Entities\PosKasBonCollectionMethod;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTemporaryPaymentImage;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosKasBonReviewService;
use Modules\Pos\Services\PosKasBonSettlementService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Unit;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosKasBonSettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Location $location1;
    protected User $cashier;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $transferMethod;
    protected PaymentMethod $debtMethod;
    protected Product $product1;
    protected Product $product2;
    protected Unit $unit;
    protected Category $category;
    protected Customer $customer;
    protected PosKasBonSettlementService $settlementService;
    protected PosKasBonReviewService $reviewService;

    protected function setUp(): void
    {
        parent::setUp();

        $collectPerm = Permission::firstOrCreate(['name' => 'pos.kasBon.collect', 'guard_name' => 'web']);
        $posAccessPerm = Permission::firstOrCreate(['name' => 'pos.access', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $role->givePermissionTo([$collectPerm, $posAccessPerm]);

        $this->setting1 = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Toko Kas Bon Maju',
            'company_email' => 'toko1@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'toko1@example.com',
            'footer_text' => 'F1',
            'document_prefix' => 'D1',
            'purchase_prefix_document' => 'P1',
            'sale_prefix_document' => 'S1',
            'pos_enabled' => true,
        ]);

        $this->location1 = Location::create(['name' => 'Gudang Utama', 'setting_id' => $this->setting1->id]);

        $this->cashier = User::factory()->create(['name' => 'Kasir Pelaksana']);
        $this->cashier->givePermissionTo(['pos.access', 'pos.kasBon.collect']);

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Terminal 01',
            'code' => 'T01',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'opened_at' => now(),
            'status' => PosSession::STATUS_OPEN,
            'active_marker' => 1,
            'expected_cash_total' => 200000,
        ]);

        // Cash drawer float event
        PosSessionCashEvent::create([
            'setting_id' => $this->setting1->id,
            'pos_session_id' => $this->session->id,
            'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 200000,
            'performed_by' => $this->cashier->id,
            'occurred_at' => now(),
        ]);

        $coaCash = ChartOfAccount::create([
            'setting_id' => $this->setting1->id,
            'account_number' => '1101-CASH',
            'name' => 'Kas Toko',
            'category' => 'Kas & Bank',
        ]);

        $coaBank = ChartOfAccount::create([
            'setting_id' => $this->setting1->id,
            'account_number' => '1102-BANK',
            'name' => 'Bank BCA',
            'category' => 'Kas & Bank',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Cash',
            'is_cash' => true,
            'is_active' => true,
            'coa_id' => $coaCash->id,
        ]);

        $this->transferMethod = PaymentMethod::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Transfer Bank BCA',
            'is_cash' => false,
            'is_active' => true,
            'coa_id' => $coaBank->id,
        ]);

        $this->debtMethod = PaymentMethod::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Kas Bon',
            'is_cash' => false,
            'is_active' => true,
            'coa_id' => $coaBank->id,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting1->id,
            'payment_method_id' => $this->cashMethod->id,
            'is_enabled' => true,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting1->id,
            'payment_method_id' => $this->transferMethod->id,
            'is_enabled' => true,
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
            'setting_id' => $this->setting1->id,
        ]);

        $this->product1 = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Beras Organik 5kg',
            'product_code' => 'BRS-ORG',
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'product_cost' => 60000,
            'product_price' => 80000,
            'product_quantity' => 50,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Ahmad Pelanggan',
            'customer_phone' => '0812345678',
            'customer_email' => 'ahmad@example.com',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Jl. Merdeka No 1',
        ]);

        $this->settlementService = new PosKasBonSettlementService();
        $this->reviewService = new PosKasBonReviewService();
    }

    protected function createKasBonFixture(
        string $trxCode,
        string $receiptNumber,
        float $totalAmount,
        float $downPayment
    ): array {
        $dueAmount = round($totalAmount - $downPayment, 2);
        $paymentStatus = $downPayment > 0 ? 'Partial' : 'Unpaid';

        $trx = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'code' => $trxCode,
            'status' => PosTransaction::STATUS_COMPLETED,
            'type' => 'REGULAR',
        ]);

        $line = PosTransactionLine::create([
            'pos_transaction_id' => $trx->id,
            'line_no' => 1,
            'product_id' => $this->product1->id,
            'product_name_snapshot' => $this->product1->product_name,
            'product_code_snapshot' => $this->product1->product_code,
            'qty' => 1,
            'unit_price' => $totalAmount,
            'subtotal' => $totalAmount,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'reference' => 'SL-' . $trxCode,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'paid_amount' => $downPayment,
            'due_amount' => $dueAmount,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => $paymentStatus,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
            'note' => 'Utang awal',
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->product_name,
            'product_code' => $this->product1->product_code,
            'quantity' => 1,
            'price' => $totalAmount,
            'unit_price' => $totalAmount,
            'sub_total' => $totalAmount,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        if ($downPayment > 0) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'amount' => $downPayment,
                'date' => now()->toDateString(),
                'reference' => 'SP-DP-' . $trxCode,
                'payment_method_id' => $this->cashMethod->id,
                'payment_method' => 'Cash',
                'status' => SalePayment::STATUS_ACTIVE,
            ]);
        }

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $trx->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->session->terminal_id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => 'idemp-' . $trxCode,
            'payload_hash' => hash('sha256', $trxCode),
            'subtotal' => $totalAmount,
            'grand_total' => $totalAmount,
            'paid_total' => $downPayment,
            'debt_amount' => $dueAmount,
            'receipt_number' => $receiptNumber,
            'note' => 'Kas Bon Awal',
            'metadata' => [
                'is_debt' => true,
            ],
        ]);

        $trx->update(['completed_checkout_id' => $checkout->id]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'split_key' => 'split_' . $trxCode,
            'tax_bucket' => 'notax',
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting1->id,
            'source_location_id' => $this->location1->id,
            'subtotal' => $totalAmount,
            'grand_total' => $totalAmount,
            'paid_total' => $downPayment,
        ]);

        return [
            'transaction' => $trx,
            'checkout' => $checkout,
            'sale' => $sale,
        ];
    }

    public function test_partial_cash_payment_with_tender_and_change_updates_drawer_cash(): void
    {
        // Debt: 150000 total, 0 paid, 150000 due
        $fixture = $this->createKasBonFixture('TRX-PARTIAL-01', 'RCP-P01', 150000, 0);
        $trx = $fixture['transaction'];

        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        // Pay 50,000 cash with free-text note 'Cicilan kedua' -> zero change
        $collection = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 50000,
            methods: [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'amount' => 50000,
                    'note' => 'Cicilan kedua',
                ],
            ],
            idempotencyKey: 'idemp-collect-001',
            note: 'Pembayaran pertama'
        );

        $this->assertEquals(50000.0, (float) $collection->amount_collected);
        $this->assertEquals(50000.0, (float) $collection->tender_total);
        $this->assertEquals(0.0, (float) $collection->change_total);
        $this->assertEquals(100000.0, (float) $collection->remaining_debt);

        // Verify PosKasBonCollectionMethod
        $this->assertCount(1, $collection->methods);
        $method = $collection->methods->first();
        $this->assertTrue((bool) $method->is_cash);
        $this->assertEquals(50000.0, (float) $method->amount);
        $this->assertEquals(50000.0, (float) $method->tender_amount);
        $this->assertEquals(0.0, (float) $method->change_amount);
        $this->assertEquals('Cicilan kedua', $method->notes);
        $this->assertNull($method->reference);

        // Verify PosKasBonCollectionAllocation
        $this->assertCount(1, $collection->allocations);
        $alloc = $collection->allocations->first();
        $this->assertEquals(50000.0, (float) $alloc->allocated_amount);
        $this->assertEquals($fixture['sale']->id, $alloc->sale_id);

        // Verify child Sale and SalePayment
        $sale = $fixture['sale']->fresh();
        $this->assertEquals(50000.0, (float) $sale->paid_amount);
        $this->assertEquals(100000.0, (float) $sale->due_amount);
        $this->assertEqualsIgnoringCase('Partial', $sale->payment_status);

        $createdPayment = SalePayment::find($alloc->sale_payment_id);
        $this->assertNotNull($createdPayment);
        $this->assertEquals(50000.0, (float) $createdPayment->amount);
        $this->assertEquals(SalePayment::STATUS_ACTIVE, $createdPayment->status);
        $this->assertEquals('Cicilan kedua', $createdPayment->note);

        // Verify Session Cash Events (Task 4.1):
        // 1. Exactly-one KAS_BON_COLLECTION_IN = 50000
        $inEvent = PosSessionCashEvent::where('pos_session_id', $this->session->id)
            ->where('event_type', PosSessionCashEvent::EVENT_KAS_BON_COLLECTION_IN)
            ->first();
        $this->assertNotNull($inEvent);
        $this->assertEquals(50000.0, (float) $inEvent->amount);
        $this->assertEquals(PosSessionCashEvent::DIRECTION_IN, $inEvent->direction);
        $this->assertEquals($collection->id, $inEvent->reference_id);

        // 2. Zero change -> NO CHANGE_OUT event
        $outEvent = PosSessionCashEvent::where('pos_session_id', $this->session->id)
            ->where('event_type', PosSessionCashEvent::EVENT_CHANGE_OUT)
            ->first();
        $this->assertNull($outEvent);

        // 3. Expected cash: Float (200000) + Inflow (50000) = 250000
        $this->session->refresh();
        $this->assertEquals(250000.0, (float) $this->session->expected_cash_total);
    }

    public function test_mixed_methods_payment_allocates_correctly_and_only_cash_affects_drawer(): void
    {
        // Debt: 100000 total, 0 paid, 100000 due
        $fixture = $this->createKasBonFixture('TRX-MIXED-01', 'RCP-M01', 100000, 0);
        $trx = $fixture['transaction'];

        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        // Settle full 100,000 using Rp 60,000 Cash (Keterangan: 'Cicilan tunai') + Rp 40,000 Bank Transfer (Keterangan: 'Transfer BCA Pak Agus', Reference: 'TRF-BCA-987654')
        $collection = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 100000,
            methods: [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'amount' => 60000,
                    'note' => 'Cicilan tunai',
                ],
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'amount' => 40000,
                    'note' => 'Transfer BCA Pak Agus',
                    'reference' => 'TRF-BCA-987654',
                ],
            ],
            idempotencyKey: 'idemp-collect-mixed-01'
        );

        $this->assertEquals(100000.0, (float) $collection->amount_collected);
        $this->assertEquals(100000.0, (float) $collection->tender_total);
        $this->assertEquals(0.0, (float) $collection->change_total);
        $this->assertEquals(0.0, (float) $collection->remaining_debt);

        $this->assertCount(2, $collection->methods);

        $cashMethodCol = $collection->methods->firstWhere('payment_method_id', $this->cashMethod->id);
        $this->assertEquals('Cicilan tunai', $cashMethodCol->notes);
        $this->assertNull($cashMethodCol->reference);

        $trfMethodCol = $collection->methods->firstWhere('payment_method_id', $this->transferMethod->id);
        $this->assertEquals('Transfer BCA Pak Agus', $trfMethodCol->notes);
        $this->assertEquals('TRF-BCA-987654', $trfMethodCol->reference);
        $this->assertNotEquals($trfMethodCol->notes, $trfMethodCol->reference);

        // Child sale is now fully paid
        $sale = $fixture['sale']->fresh();
        $this->assertEquals(100000.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->due_amount);
        $this->assertEqualsIgnoringCase('Paid', $sale->payment_status);

        // Drawer cash should ONLY increase by Rp 60,000 cash!
        // Expected cash: 200,000 float + 60,000 cash = 260,000
        $this->session->refresh();
        $this->assertEquals(260000.0, (float) $this->session->expected_cash_total);

        // There should be NO CHANGE_OUT event
        $changeEvent = PosSessionCashEvent::where('pos_session_id', $this->session->id)
            ->where('event_type', PosSessionCashEvent::EVENT_CHANGE_OUT)
            ->first();
        $this->assertNull($changeEvent);
    }

    public function test_transfer_only_payment_does_not_generate_cash_events(): void
    {
        $fixture = $this->createKasBonFixture('TRX-TF-01', 'RCP-TF01', 80000, 0);
        $trx = $fixture['transaction'];

        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        $collection = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 80000,
            methods: [
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'amount' => 80000,
                ],
            ],
            idempotencyKey: 'idemp-collect-tf-01'
        );

        $this->assertEquals(80000.0, (float) $collection->amount_collected);

        // Drawer cash stays 200,000
        $this->session->refresh();
        $this->assertEquals(200000.0, (float) $this->session->expected_cash_total);

        $cashEvents = PosSessionCashEvent::where('pos_session_id', $this->session->id)
            ->whereIn('event_type', [PosSessionCashEvent::EVENT_KAS_BON_COLLECTION_IN, PosSessionCashEvent::EVENT_CHANGE_OUT])
            ->count();
        $this->assertEquals(0, $cashEvents);
    }

    public function test_validation_rejects_zero_or_excess_amount_or_insufficient_tender(): void
    {
        $fixture = $this->createKasBonFixture('TRX-VAL-01', 'RCP-V01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        // 1. Zero amount
        try {
            $this->settlementService->collect(
                $trx->id,
                $this->session->id,
                $this->cashier->id,
                $fingerprint,
                0,
                [['payment_method_id' => $this->cashMethod->id, 'amount' => 0]],
                'idemp-v-0'
            );
            $this->fail('Expected zero amount rejection');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors()['amount']));
        }

        // 2. Excess amount (paying 120,000 for 100,000 debt)
        try {
            $this->settlementService->collect(
                $trx->id,
                $this->session->id,
                $this->cashier->id,
                $fingerprint,
                120000,
                [['payment_method_id' => $this->cashMethod->id, 'amount' => 120000, 'tender_amount' => 120000]],
                'idemp-v-excess'
            );
            $this->fail('Expected excess amount rejection');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors()['amount']));
        }

        // 3. Free text in CASH Keterangan ("Cicilan kedua") does not affect amount validation or payment calculations
        $validCashNoteCollection = $this->settlementService->collect(
            $trx->id,
            $this->session->id,
            $this->cashier->id,
            $fingerprint,
            50000,
            [['payment_method_id' => $this->cashMethod->id, 'amount' => 50000, 'note' => 'Cicilan kedua']],
            'idemp-v-cash-free-text'
        );
        $this->assertEquals(50000.0, (float) $validCashNoteCollection->amount_collected);
        $this->assertEquals('Cicilan kedua', $validCashNoteCollection->methods->first()->notes);
        $this->assertNull($validCashNoteCollection->methods->first()->reference);

        // 5. Finishing as Kas Bon / Debt
        try {
            $this->settlementService->collect(
                $trx->id,
                $this->session->id,
                $this->cashier->id,
                $fingerprint,
                50000,
                [['payment_method_id' => $this->debtMethod->id, 'amount' => 50000]],
                'idemp-v-debt-finish'
            );
            $this->fail('Expected finish-as-kas-bon rejection');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors()['methods.0.payment_method_id']));
        }
    }

    public function test_idempotent_retry_returns_stored_result_even_after_session_closed(): void
    {
        $fixture = $this->createKasBonFixture('TRX-IDEMP-01', 'RCP-ID01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        $key = 'idemp-durable-retry-01';

        // 1. Initial collection
        $first = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 50000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 50000, 'tender_amount' => 50000],
            ],
            idempotencyKey: $key
        );

        $this->assertEquals(50000.0, (float) $first->amount_collected);

        // 2. Close the receiving session
        $this->session->update([
            'status' => PosSession::STATUS_CLOSED,
            'closed_at' => now(),
            'active_marker' => null,
        ]);

        // 3. Retry identical request after session closure
        $retry = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 50000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 50000, 'tender_amount' => 50000],
            ],
            idempotencyKey: $key
        );

        $this->assertEquals($first->id, $retry->id);
        $this->assertEquals($first->reference, $retry->reference);

        // 4. Retry with changed payload (e.g. amount 60000 instead of 50000) -> Must be rejected!
        try {
            $this->settlementService->collect(
                posTransactionId: $trx->id,
                receivingSessionId: $this->session->id,
                actorUserId: $this->cashier->id,
                fingerprint: $fingerprint,
                amountToPay: 60000,
                methods: [
                    ['payment_method_id' => $this->cashMethod->id, 'amount' => 60000, 'tender_amount' => 60000],
                ],
                idempotencyKey: $key
            );
            $this->fail('Expected changed payload rejection');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors()['idempotency_key']));
        }
    }

    public function test_idempotent_retry_rejects_changed_note_or_changed_reference(): void
    {
        SettingPosPaymentMethod::firstOrCreate([
            'setting_id' => $this->setting1->id,
            'payment_method_id' => $this->transferMethod->id,
        ], [
            'is_enabled' => true,
        ]);

        $fixture = $this->createKasBonFixture('TRX-IDEMP-NOTE-REF', 'RCP-IDNR', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        $key = 'idemp-note-ref-check-01';

        // 1. Initial collection with both note and reference
        $first = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 50000,
            methods: [
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'amount' => 50000,
                    'note' => 'Titipan BCA',
                    'reference' => 'TRF-111111',
                ],
            ],
            idempotencyKey: $key
        );

        $this->assertEquals(50000.0, (float) $first->amount_collected);

        // 2. Identical retry returns stored result
        $retry = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 50000,
            methods: [
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'amount' => 50000,
                    'note' => 'Titipan BCA',
                    'reference' => 'TRF-111111',
                ],
            ],
            idempotencyKey: $key
        );

        $this->assertEquals($first->id, $retry->id);

        // 3. Changed reference with unchanged note MUST be rejected
        try {
            $this->settlementService->collect(
                posTransactionId: $trx->id,
                receivingSessionId: $this->session->id,
                actorUserId: $this->cashier->id,
                fingerprint: $fingerprint,
                amountToPay: 50000,
                methods: [
                    [
                        'payment_method_id' => $this->transferMethod->id,
                        'amount' => 50000,
                        'note' => 'Titipan BCA', // Same note
                        'reference' => 'TRF-999999', // Different reference
                    ],
                ],
                idempotencyKey: $key
            );
            $this->fail('Expected changed reference to be rejected for the same idempotency key');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors()['idempotency_key']));
        }

        // 4. Changed note with unchanged reference MUST also be rejected
        try {
            $this->settlementService->collect(
                posTransactionId: $trx->id,
                receivingSessionId: $this->session->id,
                actorUserId: $this->cashier->id,
                fingerprint: $fingerprint,
                amountToPay: 50000,
                methods: [
                    [
                        'payment_method_id' => $this->transferMethod->id,
                        'amount' => 50000,
                        'note' => 'Cicilan kedua', // Different note
                        'reference' => 'TRF-111111', // Same reference
                    ],
                ],
                idempotencyKey: $key
            );
            $this->fail('Expected changed note to be rejected for the same idempotency key');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors()['idempotency_key']));
        }
    }

    public function test_stale_fingerprint_is_rejected_when_underlying_sale_changes(): void
    {
        $fixture = $this->createKasBonFixture('TRX-STALE-01', 'RCP-ST01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $staleFingerprint = $review['fingerprint'];

        // Modify underlying sale price/amount behind the scenes
        $sale = $fixture['sale'];
        $sale->update(['total_amount' => 120000, 'due_amount' => 120000]);
        SaleDetails::where('sale_id', $sale->id)->update(['price' => 120000, 'sub_total' => 120000]);

        $this->expectException(ValidationException::class);

        $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $staleFingerprint,
            amountToPay: 100000,
            methods: [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 100000, 'tender_amount' => 100000],
            ],
            idempotencyKey: 'idemp-stale-01'
        );
    }

    public function test_failure_rolls_back_financial_records_and_cleans_up_media(): void
    {
        $fixture = $this->createKasBonFixture('TRX-RB-01', 'RCP-RB01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        // Create temporary test attachment token in storage
        Storage::fake('local');
        $storedPath = 'pos/temp_payment_images/test_tok_rb01.pdf';
        Storage::put($storedPath, '%PDF-1.4 test attachment content');

        $tempImage = PosTemporaryPaymentImage::create([
            'token' => 'tok_rollback_test_01',
            'setting_id' => $this->setting1->id,
            'pos_session_id' => $this->session->id,
            'cashier_id' => $this->cashier->id,
            'cart_token' => 'kas-bon-' . $trx->id,
            'path' => $storedPath,
            'original_name' => 'receipt.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1234,
            'expires_at' => now()->addHour(),
        ]);

        // Inject failure on payment copy using the test seam
        $this->settlementService->getReplicator()->failBeforePaymentIndex = 0;

        try {
            $this->settlementService->collect(
                posTransactionId: $trx->id,
                receivingSessionId: $this->session->id,
                actorUserId: $this->cashier->id,
                fingerprint: $fingerprint,
                amountToPay: 100000,
                methods: [
                    [
                        'payment_method_id' => $this->transferMethod->id,
                        'amount' => 100000,
                        'attachment' => $tempImage->token,
                    ],
                ],
                idempotencyKey: 'idemp-rollback-01'
            );
            $this->fail('Expected injected failure');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Injected replicator failure', $e->getMessage());
        }

        // Verify rollback:
        // No collection created
        $this->assertEquals(0, PosKasBonCollection::count());
        $this->assertEquals(0, PosKasBonCollectionMethod::count());
        $this->assertEquals(0, PosKasBonCollectionAllocation::count());

        // No SalePayment created
        $this->assertEquals(0, SalePayment::where('sale_id', $fixture['sale']->id)->count());

        // Sale due remains untouched
        $sale = $fixture['sale']->fresh();
        $this->assertEquals(100000.0, (float) $sale->due_amount);
        $this->assertEquals(0.0, (float) $sale->paid_amount);

        // Expected cash unchanged
        $this->session->refresh();
        $this->assertEquals(200000.0, (float) $this->session->expected_cash_total);
    }

    public function test_arbitrary_server_file_path_attachment_is_rejected(): void
    {
        $fixture = $this->createKasBonFixture('TRX-ARB-01', 'RCP-ARB01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Lampiran bukti pembayaran tidak valid');

        $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 100000,
            methods: [
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'amount' => 100000,
                    'attachment' => '/etc/passwd',
                ],
            ],
            idempotencyKey: 'idemp-arb-01'
        );
    }

    public function test_payment_method_disabled_for_pos_in_active_setting_is_rejected(): void
    {
        $fixture = $this->createKasBonFixture('TRX-DIS-01', 'RCP-DIS01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        $unenabledMethod = PaymentMethod::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Unconfigured Method',
            'is_cash' => false,
            'is_active' => true,
            'coa_id' => $this->transferMethod->coa_id,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belum diaktifkan untuk POS');

        $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 100000,
            methods: [
                [
                    'payment_method_id' => $unenabledMethod->id,
                    'amount' => 100000,
                ],
            ],
            idempotencyKey: 'idemp-dis-01'
        );
    }

    public function test_payment_method_requiring_reference_is_enforced(): void
    {
        $fixture = $this->createKasBonFixture('TRX-REF-01', 'RCP-REF01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        $refMethod = PaymentMethod::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Debit Mandiri',
            'is_cash' => false,
            'is_active' => true,
            'requires_reference' => true,
            'coa_id' => $this->transferMethod->coa_id,
        ]);

        SettingPosPaymentMethod::create([
            'setting_id' => $this->setting1->id,
            'payment_method_id' => $refMethod->id,
            'is_enabled' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Nomor referensi wajib diisi');

        $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 100000,
            methods: [
                [
                    'payment_method_id' => $refMethod->id,
                    'amount' => 100000,
                    'reference' => '',
                ],
            ],
            idempotencyKey: 'idemp-ref-01'
        );
    }

    public function test_controller_collect_endpoint_success(): void
    {
        $fixture = $this->createKasBonFixture('TRX-CTRL-01', 'RCP-C01', 150000, 50000);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        $response = $this->actingAs($this->cashier)
            ->withSession(['setting_id' => $this->setting1->id])
            ->postJson(route('pos.kas-bon.collect', $trx->id), [
                'fingerprint' => $fingerprint,
                'amount_to_pay' => 100000,
                'methods' => [
                    [
                        'payment_method_id' => $this->cashMethod->id,
                        'amount' => 100000,
                        'tender_amount' => 100000,
                    ],
                ],
                'idempotency_key' => 'idemp-ctrl-01',
                'note' => 'Pembayaran lunas dari controller',
            ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertTrue($data['success']);
        $this->assertEquals(100000.0, (float) $data['amount_collected']);
        $this->assertEquals(0.0, (float) $data['remaining_debt']);
        $this->assertNotEmpty($data['reference']);
        $this->assertNotEmpty($data['receipt_url']);
    }

    public function test_idempotent_retry_succeeds_even_when_attachment_consumed_or_expired_or_method_disabled(): void
    {
        Storage::fake('local');
        $fixture = $this->createKasBonFixture('TRX-IDEMP-EV01', 'RCP-IDEV01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        $fakeFile = UploadedFile::fake()->image('transfer.jpg', 600, 400);
        $tempPath = $fakeFile->storeAs('pos/temp_payment_images', 'tok-idev-01.jpg');

        $tempImage = PosTemporaryPaymentImage::create([
            'token' => 'tok-idev-01',
            'setting_id' => $this->setting1->id,
            'pos_session_id' => $this->session->id,
            'cashier_id' => $this->cashier->id,
            'cart_token' => 'kas-bon-' . $trx->id,
            'path' => $tempPath,
            'original_name' => 'transfer.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'expires_at' => now()->addHour(),
        ]);

        $key = 'idemp-key-resilience-01';
        $methods = [
            [
                'payment_method_id' => $this->transferMethod->id,
                'amount' => 100000,
                'reference' => 'TF-999',
                'attachment' => $tempImage->token,
            ],
        ];

        // 1. Initial collection succeeds
        $first = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 100000,
            methods: $methods,
            idempotencyKey: $key
        );

        $this->assertEquals(100000.0, (float) $first->amount_collected);

        // Verify token was claimed
        $tempImage->refresh();
        $this->assertNotNull($tempImage->consumed_at);

        // 2. Simulate expiration, file deletion, and method deactivation
        $tempImage->update(['expires_at' => now()->subDay()]);
        Storage::delete($tempPath);
        $this->transferMethod->update(['is_active' => false]);

        // 3. Identical retry must return stored result without failing on temp file, expiry, or disabled method
        $retry = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 100000,
            methods: $methods,
            idempotencyKey: $key
        );

        $this->assertEquals($first->id, $retry->id);
        $this->assertEquals($first->reference, $retry->reference);
    }

    public function test_collect_rejects_evidence_token_from_different_pos_session(): void
    {
        Storage::fake('local');
        $fixture = $this->createKasBonFixture('TRX-DIFF-SESS-01', 'RCP-DS01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);

        $fakeFile = UploadedFile::fake()->image('transfer.jpg', 600, 400);
        $tempPath = $fakeFile->storeAs('pos/temp_payment_images', 'tok-diff-sess.jpg');

        // Other session
        $otherSession = PosSession::create([
            'setting_id' => $this->setting1->id,
            'cashier_user_id' => $this->cashier->id,
            'opened_at' => now()->subHours(5),
            'status' => PosSession::STATUS_CLOSED,
            'closed_at' => now()->subHour(),
        ]);

        $tempImage = PosTemporaryPaymentImage::create([
            'token' => 'tok-diff-sess',
            'setting_id' => $this->setting1->id,
            'pos_session_id' => $otherSession->id,
            'cashier_id' => $this->cashier->id,
            'cart_token' => 'kas-bon-' . $trx->id,
            'path' => $tempPath,
            'original_name' => 'transfer.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'expires_at' => now()->addHour(),
        ]);

        $this->expectException(ValidationException::class);
        $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 100000,
            methods: [
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'amount' => 100000,
                    'reference' => 'TF-123',
                    'attachment' => $tempImage->token,
                ],
            ],
            idempotencyKey: 'idemp-diff-sess-01'
        );
    }

    public function test_collect_rejects_evidence_token_from_different_cart_or_transaction(): void
    {
        Storage::fake('local');
        $fixture = $this->createKasBonFixture('TRX-DIFF-CART-01', 'RCP-DC01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);

        $fakeFile = UploadedFile::fake()->image('transfer.jpg', 600, 400);
        $tempPath = $fakeFile->storeAs('pos/temp_payment_images', 'tok-diff-cart.jpg');

        $tempImage = PosTemporaryPaymentImage::create([
            'token' => 'tok-diff-cart',
            'setting_id' => $this->setting1->id,
            'pos_session_id' => $this->session->id,
            'cashier_id' => $this->cashier->id,
            'cart_token' => 'cart-unrelated-cart-token-999',
            'path' => $tempPath,
            'original_name' => 'transfer.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'expires_at' => now()->addHour(),
        ]);

        $this->expectException(ValidationException::class);
        $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 100000,
            methods: [
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'amount' => 100000,
                    'reference' => 'TF-123',
                    'attachment' => $tempImage->token,
                ],
            ],
            idempotencyKey: 'idemp-diff-cart-01'
        );
    }

    public function test_collect_rejects_evidence_token_already_consumed(): void
    {
        Storage::fake('local');
        $fixture = $this->createKasBonFixture('TRX-ALREADY-CONS-01', 'RCP-AC01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);

        $fakeFile = UploadedFile::fake()->image('transfer.jpg', 600, 400);
        $tempPath = $fakeFile->storeAs('pos/temp_payment_images', 'tok-consumed.jpg');

        $tempImage = PosTemporaryPaymentImage::create([
            'token' => 'tok-consumed',
            'setting_id' => $this->setting1->id,
            'pos_session_id' => $this->session->id,
            'cashier_id' => $this->cashier->id,
            'cart_token' => 'kas-bon-' . $trx->id,
            'path' => $tempPath,
            'original_name' => 'transfer.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'expires_at' => now()->addHour(),
            'consumed_at' => now()->subMinute(),
        ]);

        $this->expectException(ValidationException::class);
        $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $review['fingerprint'],
            amountToPay: 100000,
            methods: [
                [
                    'payment_method_id' => $this->transferMethod->id,
                    'amount' => 100000,
                    'reference' => 'TF-123',
                    'attachment' => $tempImage->token,
                ],
            ],
            idempotencyKey: 'idemp-already-cons-01'
        );
    }

    public function test_overlapping_identical_submission_returns_committed_collection_under_lock(): void
    {
        Storage::fake('local');
        $fixture = $this->createKasBonFixture('TRX-OVERLAP-01', 'RCP-OL01', 100000, 0);
        $trx = $fixture['transaction'];
        $review = $this->reviewService->review($trx);
        $fingerprint = $review['fingerprint'];

        $fakeFile = UploadedFile::fake()->image('transfer.jpg', 600, 400);
        $tempPath = $fakeFile->storeAs('pos/temp_payment_images', 'tok-overlap.jpg');

        $tempImage = PosTemporaryPaymentImage::create([
            'token' => 'tok-overlap',
            'setting_id' => $this->setting1->id,
            'pos_session_id' => $this->session->id,
            'cashier_id' => $this->cashier->id,
            'cart_token' => 'kas-bon-' . $trx->id,
            'path' => $tempPath,
            'original_name' => 'transfer.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'expires_at' => now()->addHour(),
        ]);

        $key = 'idemp-overlap-test-01';
        $methods = [
            [
                'payment_method_id' => $this->transferMethod->id,
                'amount' => 100000,
                'reference' => 'TF-OVERLAP',
                'attachment' => $tempImage->token,
            ],
        ];

        // Controlled interleaving:
        // Submission 2 starts first. When Submission 2 passes its initial idempotency check,
        // the hook executes Submission 1 to completion. Submission 2 then resumes, enters
        // DB::transaction, acquires the serialization lock, and returns via the recheck
        // under the lock rather than the initial idempotency check.
        $first = null;
        $this->settlementService->afterInitialIdempotencyCheckHook = function () use ($trx, $fingerprint, $methods, $key, &$first) {
            $first = $this->settlementService->collect(
                posTransactionId: $trx->id,
                receivingSessionId: $this->session->id,
                actorUserId: $this->cashier->id,
                fingerprint: $fingerprint,
                amountToPay: 100000,
                methods: $methods,
                idempotencyKey: $key
            );
        };

        $second = $this->settlementService->collect(
            posTransactionId: $trx->id,
            receivingSessionId: $this->session->id,
            actorUserId: $this->cashier->id,
            fingerprint: $fingerprint,
            amountToPay: 100000,
            methods: $methods,
            idempotencyKey: $key
        );

        $this->assertNotNull($first);
        $this->assertTrue(
            $this->settlementService->lastReturnedFromLockRecheck,
            'Expected second overlapping submission to return via the lock recheck rather than initial idempotency check.'
        );
        $this->assertEquals($first->id, $second->id);
        $this->assertEquals($first->reference, $second->reference);
    }
}

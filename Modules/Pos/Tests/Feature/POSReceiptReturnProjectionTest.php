<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReceiptPrintLog;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Entities\PosTransactionLineSerial;
use Modules\Pos\Exceptions\PosReprintProjectionException;
use Modules\Pos\Services\PosReceiptReprintProjectionService;
use Modules\Pos\Services\PosReceiptService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;

class POSReceiptReturnProjectionTest extends PosLineageSchemaAndCheckoutTest
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('pos.receipts.reprint', 'web');
        Permission::findOrCreate('posPayments.global.access', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');
    }

    protected function createSetting(string $name): Setting
    {
        $setting = parent::createSetting($name);
        $setting->update([
            'pos_receipt_show_return_history' => true,
        ]);

        return $setting->fresh();
    }

    /**
     * Task 1.2: Verify debt-first execution and persisted refund evidence with
     * the agreed Rp300,000 total / Rp200,000 paid examples for Rp50,000 and Rp150,000 returns.
     */
    public function test_debt_first_execution_and_persisted_refund_evidence_examples(): void
    {
        $setting = $this->createSetting('DEBT FIRST STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC A', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        // Case A: Original total 300,000 / paid 200,000 / debt 100,000 -> 50,000 return
        // Expect: actual refund evidence is zero, source debt becomes 50,000.
        [$posReturnA, $saleA] = $this->createDebtFirstScenario($setting, $loc, $cashier, $customer, 300000, 200000, 50000);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturnA->id);

        $this->assertEquals(50000.0, (float) $saleA->fresh()->due_amount);
        $this->assertEquals(200000.0, (float) $saleA->fresh()->paid_amount);
        $this->assertEquals(250000.0, (float) $saleA->fresh()->total_amount);

        // Refund evidence (SaleReturnPayment) must be ZERO
        $refundPaymentsA = SaleReturnPayment::query()
            ->whereHas('saleReturn', fn ($q) => $q->where('pos_return_id', $posReturnA->id))
            ->get();
        $this->assertCount(0, $refundPaymentsA);

        // Case B: Original total 300,000 / paid 200,000 / debt 100,000 -> 150,000 return
        // Expect: actual refund evidence is 50,000, source debt becomes zero.
        [$posReturnB, $saleB] = $this->createDebtFirstScenario($setting, $loc, $cashier, $customer, 300000, 200000, 150000);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturnB->id);

        $posReturnB->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturnB->status);
        $this->assertEquals(0.0, (float) $saleB->fresh()->due_amount);
        $this->assertEquals(150000.0, (float) $saleB->fresh()->paid_amount);
        $this->assertEquals(150000.0, (float) $saleB->fresh()->total_amount);

        // Refund evidence (SaleReturnPayment) must be 50,000
        $refundPaymentsB = SaleReturnPayment::query()
            ->whereHas('saleReturn', fn ($q) => $q->where('pos_return_id', $posReturnB->id))
            ->get();
        $this->assertCount(1, $refundPaymentsB);
        $this->assertEquals(50000.0, (float) $refundPaymentsB->first()->amount);
    }

    /**
     * Task 1.1: Verify fixtures expose current stale quantity, serial, and paid-amount behavior
     * on standard base receipt data before return projection is applied.
     */
    public function test_fixtures_expose_stale_quantity_serial_and_paid_amount_on_base_receipt(): void
    {
        $setting = $this->createSetting('STALE EXPOSURE STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC STALE', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        // 3 items bought for 100,000 each = 300,000 paid in full
        [$posReturn, $sale, $checkout, $txnLine] = $this->createDebtFirstScenario(
            $setting, $loc, $cashier, $customer, 300000, 300000, 100000
        );

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        // Inspect base receipt data from PosReceiptService
        $baseReceiptData = app(PosReceiptService::class)->getReceiptData($checkout);

        // Expose stale behavior: base receipt still shows original quantity 6 and original paid 300,000
        $this->assertSame(6.0, (float) $baseReceiptData['lines'][0]['qty']);
        $this->assertEquals(300000.0, (float) $baseReceiptData['amount_paid']);
        $this->assertArrayNotHasKey('return_history', $baseReceiptData);
    }

    /**
     * Tasks 2.2, 2.6, 3.1, 4.3: Verify partial cash return reduces line quantity,
     * reconciles debt-first payment, excludes change, and populates return history in receipt.
     */
    public function test_partial_cash_return_reduces_quantity_and_adds_return_history_and_reconciles_debt_first(): void
    {
        $setting = $this->createSetting('PARTIAL RETURN STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC PARTIAL', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        // Buy 6 items @ 50,000 = 300,000. Paid 200,000. Debt 100,000.
        // Return 1 item @ 50,000 (50,000 cash return).
        [$posReturn, $sale, $checkout, $txnLine] = $this->createDebtFirstScenario(
            $setting, $loc, $cashier, $customer, 300000, 200000, 50000
        );

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $reprintService = app(PosReceiptReprintProjectionService::class);
        $projectedData = $reprintService->getCompletedReceiptData($checkout->fresh());

        // Line quantity is 5, subtotal is 250,000
        $this->assertCount(1, $projectedData['lines']);
        $this->assertEquals(5.0, (float) $projectedData['lines'][0]['qty']);
        $this->assertEquals(250000.0, (float) $projectedData['lines'][0]['sub_total']);
        $this->assertEquals(50000.0, (float) $projectedData['lines'][0]['price']);

        // Financials: total 250,000, paid 200,000, debt 50,000, change 0
        $this->assertEquals(250000.0, (float) $projectedData['grand_total']);
        $this->assertEquals(200000.0, (float) $projectedData['amount_paid']);
        $this->assertEquals(50000.0, (float) $projectedData['outstanding_debt']);
        $this->assertEquals(0.0, (float) $projectedData['change']);

        // Return history: 1 entry with debt reduction 50,000, actual refund 0
        $this->assertArrayHasKey('return_history', $projectedData);
        $this->assertCount(1, $projectedData['return_history']);
        $historyItem = $projectedData['return_history'][0];
        $this->assertSame($posReturn->reference, $historyItem['reference']);
        $this->assertSame('Selesai', $historyItem['status']);
        $this->assertEquals(50000.0, (float) $historyItem['total_amount']);
        $this->assertEquals(50000.0, (float) $historyItem['debt_reduction']);
        $this->assertEquals(0.0, (float) $historyItem['actual_refund']);
        $this->assertCount(1, $historyItem['items']);
        $this->assertSame('Retur Kas', $historyItem['items'][0]['resolution']);
        $this->assertEquals(1.0, (float) $historyItem['items'][0]['quantity']);

        // Render Blade view
        $html = view('pos::receipt', ['receiptData' => $projectedData])->render();
        $this->assertStringContainsString('RIWAYAT RETUR', $html);
        $this->assertStringContainsString('Retur Kas', $html);
        $this->assertStringContainsString('Potong Utang:', $html);
        $this->assertStringContainsString('50.000', $html);
        $this->assertStringContainsString('250.000', $html);
    }

    /**
     * Tasks 2.2, 4.3: All items returned produces empty main lines and zero financials while retaining return details.
     */
    public function test_all_items_cash_returned_produces_empty_lines_and_zero_financials(): void
    {
        $setting = $this->createSetting('ALL RETURN STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC ALL', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        // Buy 2 items @ 50,000 = 100,000 paid in full. Return 2 items (100,000 return).
        [$posReturn, $sale, $checkout, $txnLine] = $this->createDebtFirstScenario(
            $setting, $loc, $cashier, $customer, 100000, 100000, 100000
        );

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $projectedData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());

        // Main lines must be empty!
        $this->assertEmpty($projectedData['lines']);
        $this->assertEquals(0.0, (float) $projectedData['grand_total']);
        $this->assertEquals(0.0, (float) $projectedData['amount_paid']);
        $this->assertEquals(0.0, (float) $projectedData['outstanding_debt']);
        $this->assertEquals(0.0, (float) $projectedData['change']);

        // Return history contains the returned item
        $this->assertCount(1, $projectedData['return_history']);
        $this->assertEquals(100000.0, (float) $projectedData['return_history'][0]['actual_refund']);

        // Blade view renders empty indicator
        $html = view('pos::receipt', ['receiptData' => $projectedData])->render();
        $this->assertStringContainsString('(Semua barang telah diretur)', $html);
        $this->assertStringContainsString('RIWAYAT RETUR', $html);
        $this->assertStringContainsString('Kembali Kas:', $html);
    }

    /**
     * Task 2.4: Effective serials resolve through replacement chains (A -> B -> C) and later cash return of C.
     */
    public function test_serial_replacement_chain_and_subsequent_cash_return(): void
    {
        $setting = $this->createSetting('SERIAL CHAIN STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC SERIAL', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $product = $this->createStockedProduct($setting, $loc, 'SERIAL-PROD', 50000, 10);
        if (\Modules\Setting\Entities\PaymentMethod::query()->where('name', 'CASH')->doesntExist()) {
            $this->seedPaymentMethods($setting, true);
        }

        $terminal = PosTerminal::where('setting_id', $setting->id)->first();
        $this->openSession($setting, $terminal, $cashier);
        $session = \Modules\Pos\Entities\PosSession::where('terminal_id', $terminal->id)->latest('id')->first();

        // Serial numbers: SN-A, SN-B, SN-C, SN-D
        $snA = ProductSerialNumber::create([
            'product_id' => $product->id, 'serial_number' => 'SN-A', 'status' => 'sold', 'location_id' => $loc->id,
        ]);
        $snB = ProductSerialNumber::create([
            'product_id' => $product->id, 'serial_number' => 'SN-B', 'status' => 'available', 'location_id' => $loc->id,
        ]);
        $snC = ProductSerialNumber::create([
            'product_id' => $product->id, 'serial_number' => 'SN-C', 'status' => 'available', 'location_id' => $loc->id,
        ]);
        $snD = ProductSerialNumber::create([
            'product_id' => $product->id, 'serial_number' => 'SN-D', 'status' => 'sold', 'location_id' => $loc->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'RCP-SER-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_total' => 100000,
            'change_total' => 0,
            'cashier_user_id' => $cashier->id,
            'customer_id' => $customer->id,
            'payment_method_id' => \Modules\Setting\Entities\PaymentMethod::where('name', 'CASH')->value('id'),
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'TXN-SER-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $checkout->id,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'source_pos_session_id' => $session->id,
        ]);
        $checkout->update(['pos_transaction_id' => $transaction->id]);

        $txnLine = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'qty' => 2,
            'unit_price' => 50000,
            'line_total' => 100000,
            'line_total_minor' => 10000000,
            'line_meta' => ['price_source' => 'BASE', 'assigned_serials' => ['SN-A', 'SN-D']],
        ]);

        PosTransactionLineSerial::create(['pos_transaction_line_id' => $txnLine->id, 'serial_number' => 'SN-A']);
        PosTransactionLineSerial::create(['pos_transaction_line_id' => $txnLine->id, 'serial_number' => 'SN-D']);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . uniqid(),
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'pos_transaction_line_id' => $txnLine->id,
            'quantity' => 2,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 100000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'grand_total' => 100000,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 2,
            'location_id' => $loc->id,
        ]);

        // Step 1: Replace SN-A with SN-B
        $return1 = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 0,
            'settled_at' => now()->subMinutes(10),
            'approved_at' => now()->subMinutes(10),
            'reference' => 'PR-REP-1',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'h1',
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $return1->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $txnLine->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 50000,
            'line_total' => 0,
            'returned_serial_id' => $snA->id,
            'replacement_serial_id' => $snB->id,
        ]);

        // Step 2: Replace SN-B with SN-C
        $return2 = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 0,
            'settled_at' => now()->subMinutes(5),
            'approved_at' => now()->subMinutes(5),
            'reference' => 'PR-REP-2',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'h2',
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $return2->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $txnLine->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 50000,
            'line_total' => 0,
            'returned_serial_id' => $snB->id,
            'replacement_serial_id' => $snC->id,
        ]);

        $projectedAfterReplacements = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());
        // Main line shows effective serials: SN-C and unaffected SN-D!
        $this->assertEquals(['SN-C', 'SN-D'], $projectedAfterReplacements['lines'][0]['assigned_serials']);
        $this->assertEquals(2.0, (float) $projectedAfterReplacements['lines'][0]['qty']);

        // Step 3: Cash return of SN-C
        $saleDetail->update(['quantity' => 1, 'sub_total' => 50000]);
        $sale->update(['total_amount' => 50000, 'paid_amount' => 50000]);

        $return3 = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 50000,
            'settled_at' => now(),
            'approved_at' => now(),
            'reference' => 'PR-CASH-3',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'h3',
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $return3->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $txnLine->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 50000,
            'line_total' => 50000,
            'returned_serial_id' => $snC->id,
        ]);

        $projectedAfterCashReturn = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());
        // Remaining qty 1 with only unaffected SN-D!
        $this->assertEquals(1.0, (float) $projectedAfterCashReturn['lines'][0]['qty']);
        $this->assertEquals(['SN-D'], $projectedAfterCashReturn['lines'][0]['assigned_serials']);
        $this->assertCount(3, $projectedAfterCashReturn['return_history']);
    }

    /**
     * Task 2.1: Non-completed or reversed returns do NOT contribute to item adjustments or return history.
     */
    public function test_non_completed_and_reversed_returns_do_not_affect_receipt(): void
    {
        $setting = $this->createSetting('EXCLUDED RETURNS STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC EXCL', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        // Buy 6 items @ 50,000 = 300,000
        [$posReturn, $sale, $checkout, $txnLine] = $this->createDebtFirstScenario(
            $setting, $loc, $cashier, $customer, 300000, 300000, 50000
        );
        // Keep status PENDING_APPROVAL (not completed!)
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->status);

        $projected = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());
        $this->assertEquals(6.0, (float) $projected['lines'][0]['qty']);
        $this->assertEmpty($projected['return_history']);

        // Mark as completed, but reversed!
        $posReturn->update([
            'status' => PosReturn::STATUS_COMPLETED,
            'is_reversed' => true,
        ]);

        $projectedReversed = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());
        $this->assertEquals(6.0, (float) $projectedReversed['lines'][0]['qty']);
        $this->assertEmpty($projectedReversed['return_history']);
    }

    /**
     * Tasks 4.1 & 4.2: Verify endpoint parity across checkout print, checkout reprint,
     * transaction print, transaction reprint, and global reprint.
     */
    public function test_print_and_reprint_endpoints_unified_parity(): void
    {
        $setting = $this->createSetting('PARITY STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint', 'pos.transactions.view',
        ]);
        $loc = Location::create(['name' => 'LOC PARITY', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        [$posReturn, $sale, $checkout, $txnLine] = $this->createDebtFirstScenario(
            $setting, $loc, $cashier, $customer, 300000, 200000, 50000
        );
        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $transaction = PosTransaction::find($txnLine->pos_transaction_id);

        // 1. Checkout Print
        $checkoutPrintRes = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell.checkout.receipt', $checkout));
        $checkoutPrintRes->assertStatus(200);
        $checkoutPrintData = $checkoutPrintRes->viewData('receiptData');

        // 2. Checkout Reprint
        $checkoutReprintRes = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell.checkout.receipt.reprint', $checkout));
        $checkoutReprintRes->assertStatus(200);
        $checkoutReprintData = $checkoutReprintRes->viewData('receiptData');

        // 3. Transaction Print
        $txnPrintRes = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.transactions.receipt', $transaction));
        $txnPrintRes->assertStatus(200);
        $txnPrintData = $txnPrintRes->viewData('receiptData');

        // 4. Transaction Reprint
        $txnReprintRes = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->post(route('pos.transactions.receipt.reprint', $transaction));
        $txnReprintRes->assertStatus(200);
        $txnReprintData = $txnReprintRes->viewData('receiptData');

        // Parity check across all 4 ordinary endpoints!
        $this->assertEquals($checkoutPrintData['lines'], $checkoutReprintData['lines']);
        $this->assertEquals($checkoutPrintData['lines'], $txnPrintData['lines']);
        $this->assertEquals($checkoutPrintData['lines'], $txnReprintData['lines']);
        $this->assertEquals(250000.0, (float) $checkoutPrintData['grand_total']);
        $this->assertEquals(250000.0, (float) $txnPrintData['grand_total']);
        $this->assertEquals(5.0, (float) $checkoutPrintData['lines'][0]['qty']);

        // Explicit historical receipt
        $historicalRes = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell.checkout.receipt', ['checkout' => $checkout, 'historical' => 1]));
        $historicalRes->assertStatus(200);
        $historicalData = $historicalRes->viewData('receiptData');
        $this->assertTrue($historicalData['is_historical_checkout']);
        $this->assertEquals(6.0, (float) $historicalData['lines'][0]['qty']);
        $this->assertArrayNotHasKey('return_history', $historicalData);
        $historicalRes->assertSee('STRUK HISTORIS CHECKOUT');
    }

    private function createDebtFirstScenario(
        $setting,
        $loc,
        $cashier,
        $customer,
        float $totalAmount,
        float $paidAmount,
        float $returnAmount
    ): array {
        $product = $this->createStockedProduct($setting, $loc, 'PROD-' . uniqid(), 100000, 20);
        if (\Modules\Setting\Entities\PaymentMethod::query()->where('name', 'CASH')->doesntExist()) {
            $this->seedPaymentMethods($setting, true);
        }

        $terminal = PosTerminal::where('setting_id', $setting->id)->first();
        $this->openSession($setting, $terminal, $cashier);
        $session = \Modules\Pos\Entities\PosSession::where('terminal_id', $terminal->id)->latest('id')->first();

        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'TXN-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'source_pos_session_id' => $session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => $totalAmount,
            'paid_total' => $paidAmount,
            'change_total' => 0,
            'debt_amount' => max(0, $totalAmount - $paidAmount),
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $unitPrice = 50000;
        $qtyBought = (int) round($totalAmount / $unitPrice);
        $txnLine = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'qty' => $qtyBought,
            'unit_price' => $unitPrice,
            'line_total' => $totalAmount,
            'line_total_minor' => (int) round($totalAmount * 100),
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'due_amount' => max(0, $totalAmount - $paidAmount),
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => $paidAmount >= $totalAmount ? 'PAID' : ($paidAmount > 0 ? 'PARTIAL' : 'UNPAID'),
            'payment_method' => 'CASH',
            'reference' => 'SO-' . uniqid(),
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'pos_transaction_line_id' => $txnLine->id,
            'quantity' => $qtyBought,
            'price' => $unitPrice,
            'unit_price' => $unitPrice,
            'sub_total' => $totalAmount,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'grand_total' => $totalAmount,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        if ($paidAmount > 0) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'amount' => $paidAmount,
                'date' => now()->toDateString(),
                'reference' => 'PAY-' . uniqid(),
                'payment_method' => 'CASH',
                'status' => SalePayment::STATUS_ACTIVE,
            ]);
        }

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => $qtyBought,
            'location_id' => $loc->id,
            'tax_id' => null,
            'pos_return_line_id' => null,
        ]);

        $qtyToReturn = (int) round($returnAmount / $unitPrice);

        $posReturn = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => $returnAmount,
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        $posReturnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $txnLine->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $qtyToReturn,
            'unit_price' => $unitPrice,
            'line_total' => $returnAmount,
            'expected_cash_amount' => $returnAmount,
        ]);

        $saleReturn = SaleReturn::create([
            'setting_id' => $setting->id,
            'location_id' => $loc->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Cash Return',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'reference' => 'SR-' . uniqid(),
            'total_amount' => $returnAmount,
            'paid_amount' => 0,
            'due_amount' => $returnAmount,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $posReturnLine->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $loc->id,
            'quantity' => $qtyToReturn,
            'price' => $unitPrice,
            'unit_price' => $unitPrice,
            'sub_total' => $returnAmount,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        return [$posReturn, $sale, $checkout, $txnLine];
    }

    /**
     * Finding [P1]: Cross-owner replacements maintain receipt value and totals.
     */
    public function test_cross_owner_replacement_maintains_line_value_and_totals_on_reprinted_receipt(): void
    {
        $settingA = $this->createSetting('OWNER A STORE');
        $settingB = $this->createSetting('OWNER B STORE');
        $cashierA = $this->createUserForSetting($settingA, 'cashierA', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $locA = Location::create(['name' => 'LOC A', 'setting_id' => $settingA->id]);
        $locB = Location::create(['name' => 'LOC B', 'setting_id' => $settingB->id]);
        $this->createTerminalAndSaleLocations($settingA, [$locA]);
        $this->createTerminalAndSaleLocations($settingB, [$locB]);
        $customerA = $this->assignDefaultWalkInCustomer($settingA);
        $customerB = $this->assignDefaultWalkInCustomer($settingB);

        $product = $this->createStockedProduct($settingA, $locA, 'PROD-CROSS', 100000, 5);

        if (\Modules\Setting\Entities\PaymentMethod::query()->where('name', 'CASH')->doesntExist()) {
            $this->seedPaymentMethods($settingA, true);
        }

        $terminalA = PosTerminal::where('setting_id', $settingA->id)->first();
        $this->openSession($settingA, $terminalA, $cashierA);
        $sessionA = \Modules\Pos\Entities\PosSession::where('terminal_id', $terminalA->id)->latest('id')->first();

        $snOld = ProductSerialNumber::create([
            'product_id' => $product->id, 'serial_number' => 'SN-OLD-01', 'status' => 'sold', 'location_id' => $locA->id,
        ]);
        $snNew = ProductSerialNumber::create([
            'product_id' => $product->id, 'serial_number' => 'SN-NEW-02', 'status' => 'available', 'location_id' => $locB->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $settingA->id,
            'pos_session_id' => $sessionA->id,
            'terminal_id' => $terminalA->id,
            'receipt_number' => 'RCP-CROSS-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_total' => 100000,
            'change_total' => 0,
            'cashier_user_id' => $cashierA->id,
            'customer_id' => $customerA->id,
            'payment_method_id' => \Modules\Setting\Entities\PaymentMethod::where('name', 'CASH')->value('id'),
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $settingA->id,
            'code' => 'TXN-CROSS-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $checkout->id,
            'created_by' => $cashierA->id,
            'owner_user_id' => $cashierA->id,
            'last_saved_by' => $cashierA->id,
            'source_pos_session_id' => $sessionA->id,
        ]);
        $checkout->update(['pos_transaction_id' => $transaction->id]);

        $txnLine = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'qty' => 1,
            'unit_price' => 100000,
            'line_total' => 100000,
            'line_total_minor' => 10000000,
            'line_meta' => ['price_source' => 'BASE', 'assigned_serials' => ['SN-OLD-01']],
        ]);
        PosTransactionLineSerial::create(['pos_transaction_line_id' => $txnLine->id, 'serial_number' => 'SN-OLD-01']);

        $saleA = Sale::create([
            'setting_id' => $settingA->id,
            'customer_id' => $customerA->id,
            'customer_name' => $customerA->customer_name,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-A-' . uniqid(),
        ]);

        $saleDetailA = SaleDetails::create([
            'sale_id' => $saleA->id,
            'product_id' => $product->id,
            'pos_transaction_line_id' => $txnLine->id,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $saleA->id,
            'source_setting_id' => $settingA->id,
            'source_location_id' => $locA->id,
            'grand_total' => 100000,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $dispatchA = Dispatch::create(['sale_id' => $saleA->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $dispatchDetailA = DispatchDetail::create([
            'dispatch_id' => $dispatchA->id,
            'sale_id' => $saleA->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $locA->id,
        ]);

        // Completed return for replacement
        $posReturn = PosReturn::create([
            'setting_id' => $settingA->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 0,
            'settled_at' => now(),
            'approved_at' => now(),
            'reference' => 'PR-CROSS-REP',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'h-cross',
            'created_by' => $cashierA->id,
            'updated_by' => $cashierA->id,
        ]);

        $returnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $txnLine->id,
            'source_setting_id' => $settingA->id,
            'source_location_id' => $locA->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $saleA->id,
            'sale_detail_id' => $saleDetailA->id,
            'dispatch_detail_id' => $dispatchDetailA->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 100000,
            'line_total' => 0,
            'returned_serial_id' => $snOld->id,
            'replacement_serial_id' => $snNew->id,
        ]);

        // Cross-owner execution effects:
        // 1. SaleA is cash-corrected / reduced to 0
        $saleDetailA->update(['quantity' => 0, 'sub_total' => 0]);
        $saleA->update(['total_amount' => 0, 'paid_amount' => 0, 'due_amount' => 0]);

        // 2. Replacement SaleB is created in settingB
        $saleB = Sale::create([
            'setting_id' => $settingB->id,
            'customer_id' => $customerB->id,
            'customer_name' => $customerB->customer_name,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'reference' => 'SO-B-REPL',
        ]);

        SaleDetails::create([
            'sale_id' => $saleB->id,
            'pos_transaction_line_id' => $txnLine->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SalePayment::create([
            'sale_id' => $saleB->id,
            'amount' => 100000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-B-REPL',
            'payment_method' => 'CASH',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $dispatchB = Dispatch::create(['sale_id' => $saleB->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $dispatchB->id,
            'sale_id' => $saleB->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $locB->id,
            'pos_return_line_id' => $returnLine->id,
            'serial_numbers' => json_encode(['SN-NEW-02']),
        ]);

        // Projection
        $projectedData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());

        // The line must retain its quantity 1, subtotal 100,000, and replacement serial SN-NEW-02
        $this->assertCount(1, $projectedData['lines']);
        $this->assertEquals(1.0, (float) $projectedData['lines'][0]['qty']);
        $this->assertEquals(100000.0, (float) $projectedData['lines'][0]['sub_total']);
        $this->assertEquals(100000.0, (float) $projectedData['grand_total']);
        $this->assertEquals(100000.0, (float) $projectedData['amount_paid']);
        $this->assertEquals(0.0, (float) $projectedData['outstanding_debt']);
        $this->assertEquals(['SN-NEW-02'], $projectedData['lines'][0]['assigned_serials']);
    }

    /**
     * Finding [P2]: Payment reconciliation discrepancy safely refuses projection with exception.
     */
    public function test_payment_reconciliation_discrepancy_safely_refuses_projection_with_exception(): void
    {
        $setting = $this->createSetting('PAY RECON REFUSAL STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC RECON', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        // Buy 6 items @ 50,000 = 300,000. Paid 200,000. Debt 100,000.
        // Return 1 item @ 50,000 cash return. Retained paid is 200,000.
        [$posReturn, $sale, $checkout, $txnLine] = $this->createDebtFirstScenario(
            $setting, $loc, $cashier, $customer, 300000, 200000, 50000
        );

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $service = app(PosReceiptReprintProjectionService::class);
        $method = new \ReflectionMethod(PosReceiptReprintProjectionService::class, 'buildReconciledPaymentBreakdown');
        $method->setAccessible(true);

        // Active payment on sale is 200,000. If we update payment amount to 150,000 (discrepancy with 200,000 target):
        $payment = SalePayment::query()->where('sale_id', $sale->id)->first();
        $payment->update(['amount' => 150000]);

        $saleWithDiscrepancy = $sale->fresh(['salePayments.paymentMethod']);

        try {
            $method->invoke($service, [$saleWithDiscrepancy], $checkout, 200000.0);
            $this->fail('Expected PosReprintProjectionException was not thrown on payment amount discrepancy.');
        } catch (PosReprintProjectionException $e) {
            $this->assertStringContainsString('Rekonsiliasi rincian pembayaran aktif', $e->getMessage());
        }

        // Test empty active payments throws exception instead of fabricating tender allocation
        $emptySale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 200000,
            'paid_amount' => 200000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-EMPTY-' . uniqid(),
        ]);

        try {
            $method->invoke($service, [$emptySale->fresh(['salePayments'])], $checkout, 200000.0);
            $this->fail('Expected PosReprintProjectionException was not thrown on empty active payments.');
        } catch (PosReprintProjectionException $e) {
            $this->assertStringContainsString('Tidak ditemukan bukti pembayaran aktif', $e->getMessage());
        }
    }

    /**
     * Finding [P2]: Bundle component serial replacement updates bundle_composition[*].serials.
     */
    public function test_bundle_component_serial_replacement_updates_bundle_composition_serials(): void
    {
        $setting = $this->createSetting('BUNDLE REPL STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC BUNDLE', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $bundleProduct = $this->createStockedProduct($setting, $loc, 'BUNDLE-PC', 200000, 5);
        $compProduct = $this->createStockedProduct($setting, $loc, 'COMP-GPU', 150000, 5);

        if (\Modules\Setting\Entities\PaymentMethod::query()->where('name', 'CASH')->doesntExist()) {
            $this->seedPaymentMethods($setting, true);
        }

        $terminal = PosTerminal::where('setting_id', $setting->id)->first();
        $this->openSession($setting, $terminal, $cashier);
        $session = \Modules\Pos\Entities\PosSession::where('terminal_id', $terminal->id)->latest('id')->first();

        $snCompOld = ProductSerialNumber::create([
            'product_id' => $compProduct->id, 'serial_number' => 'SN-GPU-OLD', 'status' => 'sold', 'location_id' => $loc->id,
        ]);
        $snCompNew = ProductSerialNumber::create([
            'product_id' => $compProduct->id, 'serial_number' => 'SN-GPU-NEW', 'status' => 'available', 'location_id' => $loc->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'RCP-BUN-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
            'subtotal' => 200000,
            'grand_total' => 200000,
            'paid_total' => 200000,
            'change_total' => 0,
            'cashier_user_id' => $cashier->id,
            'customer_id' => $customer->id,
            'payment_method_id' => \Modules\Setting\Entities\PaymentMethod::where('name', 'CASH')->value('id'),
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'TXN-BUN-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $checkout->id,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'source_pos_session_id' => $session->id,
        ]);
        $checkout->update(['pos_transaction_id' => $transaction->id]);

        $txnLine = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $bundleProduct->id,
            'product_name_snapshot' => $bundleProduct->product_name,
            'qty' => 1,
            'unit_price' => 200000,
            'line_total' => 200000,
            'line_total_minor' => 20000000,
            'line_meta' => [
                'price_source' => 'BUNDLE',
            ],
        ]);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 200000,
            'paid_amount' => 200000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-BUN-' . uniqid(),
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleProduct->id,
            'pos_transaction_line_id' => $txnLine->id,
            'quantity' => 1,
            'price' => 200000,
            'unit_price' => 200000,
            'sub_total' => 200000,
            'product_name' => $bundleProduct->product_name,
            'product_code' => $bundleProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $compProduct->id,
            'name' => $compProduct->product_name,
            'quantity' => 1,
            'price' => 150000,
            'sub_total' => 150000,
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'grand_total' => 200000,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 200000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-BUN',
            'payment_method' => 'CASH',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'bundle_id' => 1,
            'product_id' => $compProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $loc->id,
            'serial_numbers' => json_encode(['SN-GPU-OLD']),
        ]);

        // Synthesized bundle component return: pos_transaction_line_id is null, bundle_parent_sale_detail_id is set
        $posReturn = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 0,
            'settled_at' => now(),
            'approved_at' => now(),
            'reference' => 'PR-BUN-REP',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'h-bun',
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => null, // Synthesized component return has null pos_transaction_line_id
            'bundle_parent_sale_detail_id' => $saleDetail->id, // Linked to parent sale detail
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $compProduct->id,
            'product_name' => $compProduct->product_name,
            'product_code' => $compProduct->product_code,
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
            'returned_serial_id' => $snCompOld->id,
            'replacement_serial_id' => $snCompNew->id,
        ]);

        $projectedData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());

        $this->assertCount(1, $projectedData['lines']);
        $bundleComp = $projectedData['lines'][0]['bundle_composition'] ?? [];
        $this->assertNotEmpty($bundleComp);
        $this->assertContains('SN-GPU-NEW', $bundleComp[0]['serials']);
        $this->assertNotContains('SN-GPU-OLD', $bundleComp[0]['serials']);

        // Verify HTML renders the updated component serial in main items, and audit trail records the transition
        $html = view('pos::receipt', ['receiptData' => $projectedData])->render();
        $parts = explode('RIWAYAT RETUR', $html);
        $mainReceiptHtml = $parts[0];
        $returnHistoryHtml = $parts[1] ?? '';

        $this->assertStringContainsString('SN-GPU-NEW', $mainReceiptHtml);
        $this->assertStringNotContainsString('SN-GPU-OLD', $mainReceiptHtml);
        $this->assertStringContainsString('SN-GPU-OLD', $returnHistoryHtml);
        $this->assertStringContainsString('SN-GPU-NEW', $returnHistoryHtml);
    }

    /**
     * Finding [P1]: Legacy receipts with same-owner replacement preserve unrelated rows.
     */
    public function test_legacy_receipt_same_owner_replacement_preserves_unrelated_rows(): void
    {
        $setting = $this->createSetting('LEGACY SAME OWNER STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC LEGACY', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $mouseProduct = $this->createStockedProduct($setting, $loc, 'PROD-MOUSE', 50000, 5);
        $keyboardProduct = $this->createStockedProduct($setting, $loc, 'PROD-KEYBOARD', 100000, 5);

        if (\Modules\Setting\Entities\PaymentMethod::query()->where('name', 'CASH')->doesntExist()) {
            $this->seedPaymentMethods($setting, true);
        }

        $terminal = PosTerminal::where('setting_id', $setting->id)->first();
        $this->openSession($setting, $terminal, $cashier);
        $session = \Modules\Pos\Entities\PosSession::where('terminal_id', $terminal->id)->latest('id')->first();

        $snMouseOld = ProductSerialNumber::create([
            'product_id' => $mouseProduct->id, 'serial_number' => 'SN-M-OLD', 'status' => 'sold', 'location_id' => $loc->id,
        ]);
        $snMouseNew = ProductSerialNumber::create([
            'product_id' => $mouseProduct->id, 'serial_number' => 'SN-M-NEW', 'status' => 'available', 'location_id' => $loc->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'RCP-LEG-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
            'subtotal' => 150000,
            'grand_total' => 150000,
            'paid_total' => 150000,
            'change_total' => 0,
            'cashier_user_id' => $cashier->id,
            'customer_id' => $customer->id,
            'payment_method_id' => \Modules\Setting\Entities\PaymentMethod::where('name', 'CASH')->value('id'),
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'TXN-LEG-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $checkout->id,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'source_pos_session_id' => $session->id,
        ]);
        $checkout->update(['pos_transaction_id' => $transaction->id]);

        $line1 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $mouseProduct->id,
            'product_name_snapshot' => $mouseProduct->product_name,
            'qty' => 1,
            'unit_price' => 50000,
            'line_total' => 50000,
            'line_total_minor' => 5000000,
            'line_meta' => ['price_source' => 'BASE', 'assigned_serials' => ['SN-M-OLD']],
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $line1->id,
            'serial_number' => 'SN-M-OLD',
        ]);

        $line2 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $keyboardProduct->id,
            'product_name_snapshot' => $keyboardProduct->product_name,
            'qty' => 1,
            'unit_price' => 100000,
            'line_total' => 100000,
            'line_total_minor' => 10000000,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 150000,
            'paid_amount' => 150000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-LEG-' . uniqid(),
        ]);

        // Legacy details: pos_transaction_line_id is NULL!
        $saleDetail1 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $mouseProduct->id,
            'pos_transaction_line_id' => null, // LEGACY
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_name' => $mouseProduct->product_name,
            'product_code' => $mouseProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $saleDetail2 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $keyboardProduct->id,
            'pos_transaction_line_id' => null, // LEGACY
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_name' => $keyboardProduct->product_name,
            'product_code' => $keyboardProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'grand_total' => 150000,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 150000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-LEG',
            'payment_method' => 'CASH',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $origMouseDispatch = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail1->id,
            'product_id' => $mouseProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $loc->id,
            'serial_numbers' => json_encode(['SN-M-OLD']),
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail2->id,
            'product_id' => $keyboardProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $loc->id,
        ]);

        // Same-owner replacement for Mouse
        $posReturn = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 0,
            'settled_at' => now(),
            'approved_at' => now(),
            'reference' => 'PR-LEG-REP',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'h-leg',
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        $posReturnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $line1->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail1->id,
            'dispatch_detail_id' => $origMouseDispatch->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $mouseProduct->id,
            'product_name' => $mouseProduct->product_name,
            'product_code' => $mouseProduct->product_code,
            'quantity' => 1,
            'unit_price' => 50000,
            'line_total' => 0,
            'returned_serial_id' => $snMouseOld->id,
            'replacement_serial_id' => $snMouseNew->id,
        ]);

        $replDispatch = Dispatch::create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $replDispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail1->id,
            'product_id' => $mouseProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $loc->id,
            'serial_numbers' => json_encode(['SN-M-NEW']),
            'pos_return_line_id' => $posReturnLine->id,
            'replacement_of_dispatch_detail_id' => $origMouseDispatch->id,
            'replacement_returned_serial_id' => $snMouseOld->id,
        ]);

        $projectedData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());

        // Verify both lines are present and correctly mapped
        $this->assertCount(2, $projectedData['lines']);
        $this->assertEquals(50000.0, (float) $projectedData['lines'][0]['sub_total']);
        $this->assertEquals(['SN-M-NEW'], $projectedData['lines'][0]['assigned_serials']);
        $this->assertEquals(100000.0, (float) $projectedData['lines'][1]['sub_total']);
        $this->assertEquals(150000.0, (float) $projectedData['grand_total']);
    }

    /**
     * Finding [P2]: Cross-owner bundle component replacement with multiple receipt lines.
     */
    public function test_cross_owner_bundle_component_replacement_with_multiple_receipt_lines(): void
    {
        $settingA = $this->createSetting('OWNER A BUNDLE');
        $settingB = $this->createSetting('OWNER B REPLACEMENT');
        $cashierA = $this->createUserForSetting($settingA, 'cashierA', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $locA = Location::create(['name' => 'LOC A', 'setting_id' => $settingA->id]);
        $locB = Location::create(['name' => 'LOC B', 'setting_id' => $settingB->id]);
        $this->createTerminalAndSaleLocations($settingA, [$locA]);
        $this->createTerminalAndSaleLocations($settingB, [$locB]);
        $customerA = $this->assignDefaultWalkInCustomer($settingA);

        $bundleProduct = $this->createStockedProduct($settingA, $locA, 'PROD-BUNDLE-PC', 200000, 5);
        $compProduct = $this->createStockedProduct($settingA, $locA, 'PROD-GPU-COMP', 150000, 5);
        $monitorProduct = $this->createStockedProduct($settingA, $locA, 'PROD-MONITOR', 100000, 5);

        if (\Modules\Setting\Entities\PaymentMethod::query()->where('name', 'CASH')->doesntExist()) {
            $this->seedPaymentMethods($settingA, true);
        }

        $terminalA = PosTerminal::where('setting_id', $settingA->id)->first();
        $this->openSession($settingA, $terminalA, $cashierA);
        $sessionA = \Modules\Pos\Entities\PosSession::where('terminal_id', $terminalA->id)->latest('id')->first();

        $snCompOld = ProductSerialNumber::create([
            'product_id' => $compProduct->id, 'serial_number' => 'SN-GPU-A', 'status' => 'sold', 'location_id' => $locA->id,
        ]);
        $snCompNew = ProductSerialNumber::create([
            'product_id' => $compProduct->id, 'serial_number' => 'SN-GPU-B', 'status' => 'available', 'location_id' => $locB->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $settingA->id,
            'pos_session_id' => $sessionA->id,
            'terminal_id' => $terminalA->id,
            'receipt_number' => 'RCP-BUN-MULTI-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
            'subtotal' => 300000,
            'grand_total' => 300000,
            'paid_total' => 300000,
            'change_total' => 0,
            'cashier_user_id' => $cashierA->id,
            'customer_id' => $customerA->id,
            'payment_method_id' => \Modules\Setting\Entities\PaymentMethod::where('name', 'CASH')->value('id'),
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $settingA->id,
            'code' => 'TXN-BUN-MULTI-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $checkout->id,
            'created_by' => $cashierA->id,
            'owner_user_id' => $cashierA->id,
            'last_saved_by' => $cashierA->id,
            'source_pos_session_id' => $sessionA->id,
        ]);
        $checkout->update(['pos_transaction_id' => $transaction->id]);

        // Line 1: Bundle PC
        $line1 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $bundleProduct->id,
            'product_name_snapshot' => $bundleProduct->product_name,
            'qty' => 1,
            'unit_price' => 200000,
            'line_total' => 200000,
            'line_total_minor' => 20000000,
            'line_meta' => ['price_source' => 'BUNDLE'],
        ]);

        // Line 2: Monitor
        $line2 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $monitorProduct->id,
            'product_name_snapshot' => $monitorProduct->product_name,
            'qty' => 1,
            'unit_price' => 100000,
            'line_total' => 100000,
            'line_total_minor' => 10000000,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $saleA = Sale::create([
            'setting_id' => $settingA->id,
            'customer_id' => $customerA->id,
            'customer_name' => $customerA->customer_name,
            'total_amount' => 300000,
            'paid_amount' => 300000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-ORIG-' . uniqid(),
        ]);

        $saleDetailBundle = SaleDetails::create([
            'sale_id' => $saleA->id,
            'product_id' => $bundleProduct->id,
            'pos_transaction_line_id' => $line1->id,
            'quantity' => 1,
            'price' => 200000,
            'unit_price' => 200000,
            'sub_total' => 200000,
            'product_name' => $bundleProduct->product_name,
            'product_code' => $bundleProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $saleDetailMonitor = SaleDetails::create([
            'sale_id' => $saleA->id,
            'product_id' => $monitorProduct->id,
            'pos_transaction_line_id' => $line2->id,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
            'product_name' => $monitorProduct->product_name,
            'product_code' => $monitorProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_id' => $saleA->id,
            'sale_detail_id' => $saleDetailBundle->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $compProduct->id,
            'name' => $compProduct->product_name,
            'quantity' => 1,
            'price' => 150000,
            'sub_total' => 150000,
        ]);

        $checkoutSaleA = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $saleA->id,
            'source_setting_id' => $settingA->id,
            'source_location_id' => $locA->id,
            'grand_total' => 300000,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        SalePayment::create([
            'sale_id' => $saleA->id,
            'amount' => 300000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-ORIG',
            'payment_method' => 'CASH',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $dispatchA = Dispatch::create(['sale_id' => $saleA->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $origCompDispatch = DispatchDetail::create([
            'dispatch_id' => $dispatchA->id,
            'sale_id' => $saleA->id,
            'sale_detail_id' => $saleDetailBundle->id,
            'bundle_id' => 1,
            'product_id' => $compProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $locA->id,
            'serial_numbers' => json_encode(['SN-GPU-A']),
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatchA->id,
            'sale_id' => $saleA->id,
            'sale_detail_id' => $saleDetailMonitor->id,
            'product_id' => $monitorProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $locA->id,
        ]);

        // Synthesized component return line with null pos_transaction_line_id
        $posReturn = PosReturn::create([
            'setting_id' => $settingA->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 0,
            'settled_at' => now(),
            'approved_at' => now(),
            'reference' => 'PR-BUN-CROSS',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'h-buncross',
            'created_by' => $cashierA->id,
            'updated_by' => $cashierA->id,
        ]);

        $posReturnLine = PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSaleA->id,
            'pos_transaction_line_id' => null, // Synthesized component return line has NULL!
            'bundle_parent_sale_detail_id' => $saleDetailBundle->id, // Linked to parent sale detail
            'source_setting_id' => $settingA->id,
            'source_location_id' => $locA->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $saleA->id,
            'sale_detail_id' => $saleDetailBundle->id,
            'dispatch_detail_id' => $origCompDispatch->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $compProduct->id,
            'product_name' => $compProduct->product_name,
            'product_code' => $compProduct->product_code,
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
            'returned_serial_id' => $snCompOld->id,
            'replacement_serial_id' => $snCompNew->id,
        ]);

        // Cross-owner replacement sale in Setting B
        $saleB = Sale::create([
            'setting_id' => $settingB->id,
            'customer_id' => $customerA->id,
            'customer_name' => $customerA->customer_name,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-REPL-B-' . uniqid(),
        ]);

        $replSaleDetail = SaleDetails::create([
            'sale_id' => $saleB->id,
            'product_id' => $compProduct->id,
            'pos_transaction_line_id' => null, // Tests fallback resolution via bundle_parent_sale_detail_id
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $compProduct->product_name,
            'product_code' => $compProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatchB = Dispatch::create(['sale_id' => $saleB->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $dispatchB->id,
            'sale_id' => $saleB->id,
            'sale_detail_id' => $replSaleDetail->id,
            'product_id' => $compProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $locB->id,
            'serial_numbers' => json_encode(['SN-GPU-B']),
            'pos_return_line_id' => $posReturnLine->id,
        ]);

        $projectedData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());

        // Multi-line receipt succeeds with bundle component updated and monitor intact
        $this->assertCount(2, $projectedData['lines']);
        $bundleComp = $projectedData['lines'][0]['bundle_composition'] ?? [];
        $this->assertNotEmpty($bundleComp);
        $this->assertContains('SN-GPU-B', $bundleComp[0]['serials']);
        $this->assertNotContains('SN-GPU-A', $bundleComp[0]['serials']);
        $this->assertEquals(200000.0, (float) $projectedData['lines'][0]['sub_total']);
        $this->assertEquals(100000.0, (float) $projectedData['lines'][1]['sub_total']);
        $this->assertEquals(300000.0, (float) $projectedData['grand_total']);
    }

    /**
     * Finding [P2]: Duplicate bundle products are not mapped by guesswork; requires unique match.
     */
    public function test_duplicate_bundle_products_refuses_when_parent_provenance_is_ambiguous(): void
    {
        $service = app(PosReceiptReprintProjectionService::class);
        $method = new \ReflectionMethod($service, 'resolveTransactionLineIdFromReturnLine');
        $method->setAccessible(true);

        $line1 = new PosTransactionLine(['id' => 101, 'product_id' => 50]);
        $line1->id = 101;
        $line2 = new PosTransactionLine(['id' => 102, 'product_id' => 50]);
        $line2->id = 102;
        $lines = collect([$line1, $line2]);

        $parentDetail = new SaleDetails(['id' => 500, 'product_id' => 50, 'pos_transaction_line_id' => null]);
        $parentDetail->id = 500;

        $returnLine = new PosReturnLine([
            'pos_transaction_line_id' => null,
            'bundle_parent_sale_detail_id' => 500,
        ]);

        $this->expectException(\Modules\Pos\Exceptions\PosReprintAmbiguityException::class);
        $method->invoke($service, $returnLine, $lines, [$parentDetail]);
    }

    /**
     * Finding [P2]: Unresolved sale details refuse projection instead of being silently discarded.
     */
    public function test_unresolved_sale_details_refuse_projection(): void
    {
        $service = app(PosReceiptReprintProjectionService::class);
        $method = new \ReflectionMethod($service, 'resolveHistoricalMapping');
        $method->setAccessible(true);

        $detail = new SaleDetails(['id' => 999, 'product_id' => 10]);

        $this->expectException(\Modules\Pos\Exceptions\PosReprintAmbiguityException::class);
        $method->invoke($service, collect(), [], [$detail]);
    }

    /**
     * Finding [P2]: A single remaining unmapped line does not accept unrelated details for multi-line receipts.
     */
    public function test_single_remaining_unmapped_line_does_not_accept_unrelated_details(): void
    {
        $service = app(PosReceiptReprintProjectionService::class);
        $method = new \ReflectionMethod($service, 'resolveHistoricalMapping');
        $method->setAccessible(true);

        $remainingLine = new PosTransactionLine(['id' => 200, 'product_id' => 20]);
        $remainingLine->id = 200;

        $unrelatedDetail = new SaleDetails(['id' => 100, 'product_id' => 10]);
        $unrelatedDetail->id = 100;

        // When isSingleLineReceipt is false (the receipt originally contained multiple lines),
        // product 10 detail cannot map to remaining product 20 line.
        $this->expectException(\Modules\Pos\Exceptions\PosReprintAmbiguityException::class);
        $method->invoke($service, collect([$remainingLine]), [], [$unrelatedDetail], false);
    }

    /**
     * Regression for Transaction 6878: A laptop replacement followed by a photo-paper cash return
     * with an uninvolved serialized 'none' context row must omit the no-action row from receipt return history.
     */
    public function test_receipt_return_history_omits_uninvolved_none_resolution_rows_while_retaining_effective_returns(): void
    {
        $setting = $this->createSetting('NONE CONTEXT STORE');
        $cashier = $this->createUserForSetting($setting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.receipts.reprint',
        ]);
        $loc = Location::create(['name' => 'LOC NONE CTX', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        if (\Modules\Setting\Entities\PaymentMethod::query()->where('name', 'CASH')->doesntExist()) {
            $this->seedPaymentMethods($setting, true);
        }

        $terminal = PosTerminal::where('setting_id', $setting->id)->first();
        $this->openSession($setting, $terminal, $cashier);
        $session = \Modules\Pos\Entities\PosSession::where('terminal_id', $terminal->id)->latest('id')->first();

        // 1. Create products: Serialized Laptop and Non-serialized Photo Paper
        $laptopProduct = $this->createStockedProduct($setting, $loc, 'LAPTOP-ACER', 10000000, 5, null, true);
        $laptopProduct->update(['product_name' => 'Acer Aspire Laptop']);

        $paperProduct = $this->createStockedProduct($setting, $loc, 'PHOTO-PAPER', 20000, 20, null, false);
        $paperProduct->update(['product_name' => 'Photo Paper Glossy']);

        $snLaptopOrig = ProductSerialNumber::create([
            'product_id' => $laptopProduct->id,
            'serial_number' => 'SN-ACER-001',
            'status' => 'sold',
            'location_id' => $loc->id,
        ]);
        $snLaptopRepl = ProductSerialNumber::create([
            'product_id' => $laptopProduct->id,
            'serial_number' => 'SN-ACER-002',
            'status' => 'available',
            'location_id' => $loc->id,
        ]);

        // 2. Checkout & Transaction: 1 Laptop (10,000,000) + 5 Photo Paper (5 x 20,000 = 100,000) = 10,100,000
        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'RCP-CTX-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
            'subtotal' => 10100000,
            'grand_total' => 10100000,
            'paid_total' => 10100000,
            'change_total' => 0,
            'cashier_user_id' => $cashier->id,
            'customer_id' => $customer->id,
            'payment_method_id' => \Modules\Setting\Entities\PaymentMethod::where('name', 'CASH')->value('id'),
            'status' => PosCheckout::STATUS_POSTED,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'TXN-CTX-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'completed_checkout_id' => $checkout->id,
            'created_by' => $cashier->id,
            'owner_user_id' => $cashier->id,
            'last_saved_by' => $cashier->id,
            'source_pos_session_id' => $session->id,
        ]);
        $checkout->update(['pos_transaction_id' => $transaction->id]);

        $txnLineLaptop = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $laptopProduct->id,
            'product_name_snapshot' => $laptopProduct->product_name,
            'qty' => 1,
            'unit_price' => 10000000,
            'line_total' => 10000000,
            'line_total_minor' => 1000000000,
            'line_meta' => ['price_source' => 'BASE', 'assigned_serials' => ['SN-ACER-001']],
        ]);
        PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $txnLineLaptop->id,
            'serial_number' => 'SN-ACER-001',
        ]);

        $txnLinePaper = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $paperProduct->id,
            'product_name_snapshot' => $paperProduct->product_name,
            'qty' => 5,
            'unit_price' => 20000,
            'line_total' => 100000,
            'line_total_minor' => 10000000,
            'line_meta' => ['price_source' => 'BASE'],
        ]);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 10100000,
            'paid_amount' => 10100000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . uniqid(),
        ]);

        $saleDetailLaptop = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $laptopProduct->id,
            'pos_transaction_line_id' => $txnLineLaptop->id,
            'quantity' => 1,
            'price' => 10000000,
            'unit_price' => 10000000,
            'sub_total' => 10000000,
            'product_name' => $laptopProduct->product_name,
            'product_code' => $laptopProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $saleDetailPaper = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $paperProduct->id,
            'pos_transaction_line_id' => $txnLinePaper->id,
            'quantity' => 5,
            'price' => 20000,
            'unit_price' => 20000,
            'sub_total' => 100000,
            'product_name' => $paperProduct->product_name,
            'product_code' => $paperProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'grand_total' => 10100000,
            'split_key' => 'SPLIT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $salePayment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 10100000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-CTX',
            'payment_method' => 'CASH',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $dispatchDetailLaptop = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetailLaptop->id,
            'product_id' => $laptopProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $loc->id,
            'serial_numbers' => json_encode(['SN-ACER-001']),
        ]);
        $dispatchDetailPaper = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetailPaper->id,
            'product_id' => $paperProduct->id,
            'dispatched_quantity' => 5,
            'location_id' => $loc->id,
        ]);

        // 3. Return 1: Replacement of Laptop (SN-ACER-001 -> SN-ACER-002)
        $return1 = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 0,
            'settled_at' => now()->subMinutes(15),
            'approved_at' => now()->subMinutes(15),
            'reference' => 'PR-RET-REPL-01',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'h-repl-1',
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $return1->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $txnLineLaptop->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetailLaptop->id,
            'dispatch_detail_id' => $dispatchDetailLaptop->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'product_id' => $laptopProduct->id,
            'product_name' => $laptopProduct->product_name,
            'product_code' => $laptopProduct->product_code,
            'quantity' => 1,
            'unit_price' => 10000000,
            'line_total' => 0,
            'returned_serial_id' => $snLaptopOrig->id,
            'replacement_serial_id' => $snLaptopRepl->id,
        ]);

        // 4. Return 2: Cash return of 1 Photo Paper (20,000) containing an uninvolved serialized 'none' laptop row
        $return2 = PosReturn::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 20000,
            'settled_at' => now()->subMinutes(5),
            'approved_at' => now()->subMinutes(5),
            'reference' => 'PR-RET-CASH-02',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'h-cash-2',
            'created_by' => $cashier->id,
            'updated_by' => $cashier->id,
        ]);

        // Effective cash return line: 1 photo paper unit
        $paperReturnLine = PosReturnLine::create([
            'pos_return_id' => $return2->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $txnLinePaper->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetailPaper->id,
            'dispatch_detail_id' => $dispatchDetailPaper->id,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'product_id' => $paperProduct->id,
            'product_name' => $paperProduct->product_name,
            'product_code' => $paperProduct->product_code,
            'quantity' => 1,
            'unit_price' => 20000,
            'line_total' => 20000,
            'expected_cash_amount' => 20000,
        ]);

        // Uninvolved context row: Laptop with resolution 'none', quantity 1, serialized with replacement serial
        $contextReturnLine = PosReturnLine::create([
            'pos_return_id' => $return2->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $txnLineLaptop->id,
            'source_setting_id' => $setting->id,
            'source_location_id' => $loc->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetailLaptop->id,
            'dispatch_detail_id' => $dispatchDetailLaptop->id,
            'resolution' => PosReturnLine::RESOLUTION_NONE,
            'product_id' => $laptopProduct->id,
            'product_name' => $laptopProduct->product_name,
            'product_code' => $laptopProduct->product_code,
            'quantity' => 1,
            'unit_price' => 10000000,
            'line_total' => 0,
            'returned_serial_id' => $snLaptopRepl->id,
        ]);

        // SalesReturn & payment evidence for the 20,000 cash return
        $saleReturn = SaleReturn::create([
            'setting_id' => $setting->id,
            'location_id' => $loc->id,
            'pos_return_id' => $return2->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Cash Return',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'reference' => 'SR-RET-02',
            'total_amount' => 20000,
            'paid_amount' => 20000,
            'due_amount' => 0,
            'status' => 'Completed',
            'approval_status' => 'Approved',
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $paperReturnLine->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetailPaper->id,
            'dispatch_detail_id' => $dispatchDetailPaper->id,
            'product_id' => $paperProduct->id,
            'product_name' => $paperProduct->product_name,
            'product_code' => $paperProduct->product_code,
            'location_id' => $loc->id,
            'quantity' => 1,
            'price' => 20000,
            'unit_price' => 20000,
            'sub_total' => 20000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        SaleReturnPayment::create([
            'sale_return_id' => $saleReturn->id,
            'amount' => 20000,
            'date' => now()->toDateString(),
            'reference' => 'SRPAY-02',
            'payment_method' => 'CASH',
        ]);

        // Reflect cash return on active sale & sale detail (5 bought - 1 returned = 4 remaining)
        $saleDetailPaper->update(['quantity' => 4, 'sub_total' => 80000]);
        $salePayment->update(['amount' => 10080000]);
        $sale->update(['total_amount' => 10080000, 'paid_amount' => 10080000]);

        // 5. Project completed receipt data
        $projectedData = app(PosReceiptReprintProjectionService::class)->getCompletedReceiptData($checkout->fresh());

        // Verify main purchase list shows four photo-paper units and the laptop with replacement serial
        $this->assertCount(2, $projectedData['lines']);
        $projectedLaptop = collect($projectedData['lines'])->firstWhere('product_name', 'Acer Aspire Laptop');
        $this->assertNotNull($projectedLaptop);
        $this->assertEquals(1.0, (float) $projectedLaptop['qty']);
        $this->assertEquals(['SN-ACER-002'], $projectedLaptop['assigned_serials']);
        $this->assertEquals(10000000.0, (float) $projectedLaptop['sub_total']);

        $projectedPaper = collect($projectedData['lines'])->firstWhere('product_name', 'Photo Paper Glossy');
        $this->assertNotNull($projectedPaper);
        $this->assertEquals(4.0, (float) $projectedPaper['qty']);
        $this->assertEquals(80000.0, (float) $paperProduct->product_price * 4);
        $this->assertEquals(80000.0, (float) $projectedPaper['sub_total']);

        $this->assertEquals(10080000.0, (float) $projectedData['grand_total']);
        $this->assertEquals(10080000.0, (float) $projectedData['subtotal']);
        $this->assertEquals(10080000.0, (float) $projectedData['amount_paid']);
        $this->assertEquals(0.0, (float) $projectedData['outstanding_debt']);

        // Return history assertions
        $this->assertCount(2, $projectedData['return_history']);

        // Return 1: Laptop replacement preserved with serial lineage
        $returnHistory1 = $projectedData['return_history'][0];
        $this->assertSame('PR-RET-REPL-01', $returnHistory1['reference']);
        $this->assertSame('Selesai', $returnHistory1['status']);
        $this->assertCount(1, $returnHistory1['items']);
        $this->assertSame('Acer Aspire Laptop', $returnHistory1['items'][0]['product_name']);
        $this->assertSame('Ganti Barang', $returnHistory1['items'][0]['resolution']);
        $this->assertSame('SN-ACER-001', $returnHistory1['items'][0]['returned_serial']);
        $this->assertSame('SN-ACER-002', $returnHistory1['items'][0]['replacement_serial']);

        // Return 2: Photo Paper cash return (WITHOUT the uninvolved laptop context row)
        $returnHistory2 = $projectedData['return_history'][1];
        $this->assertSame('PR-RET-CASH-02', $returnHistory2['reference']);
        $this->assertSame('Selesai', $returnHistory2['status']);
        $this->assertEquals(20000.0, (float) $returnHistory2['total_amount']);
        $this->assertEquals(20000.0, (float) $returnHistory2['actual_refund']);
        $this->assertEquals(0.0, (float) $returnHistory2['debt_reduction']);

        // Core regression assertion: only 1 item (Photo paper) in second return entry!
        $this->assertCount(1, $returnHistory2['items']);
        $this->assertSame('Photo Paper Glossy', $returnHistory2['items'][0]['product_name']);
        $this->assertSame('Retur Kas', $returnHistory2['items'][0]['resolution']);
        $this->assertEquals(1.0, (float) $returnHistory2['items'][0]['quantity']);
        $this->assertEquals(20000.0, (float) $returnHistory2['items'][0]['line_total']);

        // Stored database records remain intact (no-action context line is unchanged)
        $persistedContextLine = PosReturnLine::query()->find($contextReturnLine->id);
        $this->assertNotNull($persistedContextLine);
        $this->assertSame(PosReturnLine::RESOLUTION_NONE, $persistedContextLine->resolution);
        $this->assertEquals(1.0, (float) $persistedContextLine->quantity);
        $this->assertEquals($snLaptopRepl->id, $persistedContextLine->returned_serial_id);

        // Render Blade view and assert second return block
        $html = view('pos::receipt', ['receiptData' => $projectedData])->render();

        // Main purchase list has laptop
        $this->assertStringContainsString('Acer Aspire Laptop', $html);
        $this->assertStringContainsString('SN-ACER-002', $html);

        // First return has laptop replacement
        $this->assertStringContainsString('PR-RET-REPL-01', $html);
        $this->assertStringContainsString('SN-ACER-001 &rarr; SN-ACER-002', $html);

        // Second return block: isolate the block for PR-RET-CASH-02
        $this->assertStringContainsString('PR-RET-CASH-02', $html);
        $secondReturnBlock = substr($html, strpos($html, 'PR-RET-CASH-02'));
        $this->assertStringContainsString('Photo Paper Glossy', $secondReturnBlock);
        $this->assertStringContainsString('Retur Kas', $secondReturnBlock);
        $this->assertStringNotContainsString('Acer Aspire Laptop', $secondReturnBlock);
        $this->assertStringNotContainsString('Lainnya', $secondReturnBlock);
    }
}

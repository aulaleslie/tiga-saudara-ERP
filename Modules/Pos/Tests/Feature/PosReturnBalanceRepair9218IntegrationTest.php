<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnBalanceRepairAudit;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosReceiptReprintProjectionService;
use Modules\Pos\Services\PosReturnBalanceRepairService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnPayment;

class PosReturnBalanceRepair9218IntegrationTest extends PosTransactionFeatureTestCase
{
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;
    protected $customer;
    protected $cashier;
    protected User $actor;
    protected PosReceiptReprintProjectionService $reprintService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('Toko Tiga Saudara 9218 Synthetic');
        $this->cashier = $this->createUserForSetting($this->setting, 'Cashier User', ['pos.access']);
        $this->actor = User::factory()->create();
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->cashier);
        $this->reprintService = app(PosReceiptReprintProjectionService::class);
    }

    /**
     * Task 3.1: Complete synthetic reproduction of incident transaction 9218.
     *
     * Invariants verified:
     * 1. 3 owner sales (Rp3,840,000; Rp4,300,000; Rp21,964,999.60 with 0.40 deficit on 120 markers).
     * 2. Completed return of 10 Brother ink bottles (Rp1,200,000 split 2 and 8 across owners).
     * 3. Rp375,000 payment preserved.
     * 4. Real reprint projection before repair produces decimal row totals.
     * 5. After repair:
     *    - All remaining commercial row totals are clean multiples of Rp100.
     *    - Grand total is Rp30,105,000.
     *    - Paid amount is Rp375,000.
     *    - Due balance is Rp29,730,000.
     *    - Fully returned ink bottles are omitted from current items and preserved in return history.
     */
    public function test_synthetic_9218_reproduction_reconciles_receipt_projection_and_debt(): void
    {
        $fixture = $this->createSynthetic9218Fixture();
        $checkout = $fixture['checkout'];
        $transaction = $fixture['transaction'];
        $sale3 = $fixture['sale3'];

        // --- STEP 1: Verify Before Repair ---
        // Stored rows on Sale 3 already total clean 21,965,000.00
        $storedDetailsSum = (float) $sale3->saleDetails()->where('quantity', '>', 0)->sum('sub_total');
        $this->assertEquals(21965000.00, $storedDetailsSum);

        // Header has the 0.40 drift
        $this->assertEquals(21964999.60, (float) $sale3->total_amount);
        $this->assertEquals(375000.00, (float) $sale3->paid_amount);
        $this->assertEquals(21589999.60, (float) $sale3->due_amount);

        // Receipt projection before repair reflects the 0.40 deficit
        $projectionBefore = $this->reprintService->getCompletedReceiptData($checkout);
        $this->assertEquals(30104999.60, (float) $projectionBefore['grand_total']);
        $this->assertEquals(29729999.60, (float) $projectionBefore['outstanding_debt']);

        // Because header was 0.40 short, reprint allocation introduced fractional cents
        $hasFractionalRowBefore = false;
        foreach ($projectionBefore['lines'] as $line) {
            if (fmod((float) $line['sub_total'], 1.0) > 0.001) {
                $hasFractionalRowBefore = true;
                break;
            }
        }
        $this->assertTrue($hasFractionalRowBefore, 'Reprint projection before repair should distribute header shortage into decimal row totals.');

        // --- STEP 2: Execute Administrative Repair Command ---
        $this->artisan('pos:repair-return-balances', [
            '--setting' => $this->setting->id,
            '--transaction' => $transaction->id,
            '--apply' => true,
            '--actor' => $this->actor->id,
            '--reason' => 'Synthetic 9218 balance repair verification',
        ])
            ->expectsOutputToContain('[REPAIRED]')
            ->assertExitCode(0)
            ->run();

        // --- STEP 3: Verify Database Post-Repair State ---
        $refreshedSale3 = $sale3->fresh();
        $this->assertEquals(21965000.00, (float) $refreshedSale3->total_amount);
        $this->assertEquals(375000.00, (float) $refreshedSale3->paid_amount); // Preserved exactly!
        $this->assertEquals(21590000.00, (float) $refreshedSale3->due_amount);
        $this->assertSame('PARTIAL', $refreshedSale3->payment_status);

        // Audit record persisted
        $audit = PosReturnBalanceRepairAudit::where('pos_transaction_id', $transaction->id)->first();
        $this->assertNotNull($audit);
        $this->assertEquals(21964999.60, (float) $audit->before_values['total_amount']);
        $this->assertEquals(21965000.00, (float) $audit->after_values['total_amount']);
        $this->assertSame($this->actor->id, $audit->actor_id);

        // --- STEP 4: Verify Receipt Reprint Projection Post-Repair ---
        $projectionAfter = $this->reprintService->getCompletedReceiptData($checkout->fresh());

        // Grand total is exactly Rp30,105,000.00
        $this->assertEquals(30105000.00, (float) $projectionAfter['grand_total']);
        // Retained payment is exactly Rp375,000.00
        $this->assertEquals(375000.00, (float) $projectionAfter['amount_paid']);
        // Outstanding debt is exactly Rp29,730,000.00
        $this->assertEquals(29730000.00, (float) $projectionAfter['outstanding_debt']);

        // Fully returned Brother ink bottles are omitted from current commercial lines
        $currentProductCodes = collect($projectionAfter['lines'])->pluck('product_code')->all();
        $this->assertNotContains('INK-9218', $currentProductCodes);

        // Fully returned Brother ink bottles are preserved in return history
        $this->assertNotEmpty($projectionAfter['return_history']);
        $totalRefundInHistory = collect($projectionAfter['return_history'])->sum(fn ($h) => (float) ($h['actual_refund'] ?? 0));
        $this->assertEquals(1200000.00, $totalRefundInHistory);

        // Invariant: ALL remaining commercial row totals are multiples of Rp100
        $this->assertNotEmpty($projectionAfter['lines']);
        foreach ($projectionAfter['lines'] as $line) {
            $rowTotal = (float) $line['sub_total'];
            $remainder = fmod($rowTotal, 100);
            $this->assertEquals(
                0.0,
                $remainder,
                sprintf("Commercial row '%s' sub_total %.2f must be a multiple of Rp100", $line['product_name'] ?? 'item', $rowTotal)
            );
        }
    }

    protected function createSynthetic9218Fixture(): array
    {
        // 1. Products
        $notebook = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Notebook Folio Hardcover',
            'product_code' => 'NB-9218',
            'sale_price' => 3840000,
        ]);

        $toner = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'LaserJet Toner Cartridge Black',
            'product_code' => 'TN-9218',
            'sale_price' => 4300000,
        ]);

        $inkBottle = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Brother BT-D60BK Black Ink Bottle',
            'product_code' => 'INK-9218',
            'sale_price' => 120000,
        ]);

        $marker = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Marker Whiteboard Snowman 120 Pcs',
            'product_code' => 'MK-9218',
            'sale_price' => 6583.33,
        ]);

        $equipment = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Commercial Office Laminator Pro',
            'product_code' => 'EQ-9218',
            'sale_price' => 21175000,
        ]);

        // 2. PosTransaction
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-9218-SYNTHETIC',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->cashier->id,
            'owner_user_id' => $this->cashier->id,
            'last_saved_by' => $this->cashier->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        // Lines:
        // Line 1: Notebook (Owner 1) - 3,840,000
        $line1 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $notebook->id,
            'product_name_snapshot' => $notebook->product_name,
            'qty' => 1,
            'unit_price' => 3840000,
            'line_total' => 3840000,
            'line_total_minor' => 384000000,
        ]);

        // Line 2: Toner (Owner 2) - 4,300,000
        $line2 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $toner->id,
            'product_name_snapshot' => $toner->product_name,
            'qty' => 1,
            'unit_price' => 4300000,
            'line_total' => 4300000,
            'line_total_minor' => 430000000,
        ]);

        // Line 3: Ink 2 bottles (Owner 2) - 240,000
        $line3 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 3,
            'product_id' => $inkBottle->id,
            'product_name_snapshot' => $inkBottle->product_name,
            'qty' => 2,
            'unit_price' => 120000,
            'line_total' => 240000,
            'line_total_minor' => 24000000,
        ]);

        // Line 4: Ink 8 bottles (Owner 3) - 960,000
        $line4 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 4,
            'product_id' => $inkBottle->id,
            'product_name_snapshot' => $inkBottle->product_name,
            'qty' => 8,
            'unit_price' => 120000,
            'line_total' => 960000,
            'line_total_minor' => 96000000,
        ]);

        // Line 5: Marker 120 pcs (Owner 3) - 790,000
        $line5 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 5,
            'product_id' => $marker->id,
            'product_name_snapshot' => $marker->product_name,
            'qty' => 120,
            'unit_price' => 6583.33,
            'line_total' => 790000,
            'line_total_minor' => 79000000,
        ]);

        // Line 6: Equipment (Owner 3) - 21,175,000
        $line6 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 6,
            'product_id' => $equipment->id,
            'product_name_snapshot' => $equipment->product_name,
            'qty' => 1,
            'unit_price' => 21175000,
            'line_total' => 21175000,
            'line_total_minor' => 2117500000,
        ]);

        // 3. PosCheckout
        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->cashier->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 30105000,
            'paid_total' => 375000,
            'debt_amount' => 29730000,
            'receipt_number' => 'RCP-9218',
            'idempotency_key' => 'IDEM-9218',
            'payload_hash' => 'HASH-9218',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        // 4. Sales & PosCheckoutSales
        // Sale 1: Owner 1 (3,840,000)
        $sale1 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 3840000.00,
            'paid_amount' => 0.00,
            'due_amount' => 3840000.00,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-9218-1',
        ]);
        $checkoutSale1 = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 3840000,
            'split_key' => 'SPLIT-1',
            'tax_bucket' => 'NON_TAX',
        ]);
        $detailNotebook = SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $notebook->id,
            'pos_transaction_line_id' => $line1->id,
            'quantity' => 1,
            'price' => 3840000,
            'unit_price' => 3840000,
            'sub_total' => 3840000,
            'product_name' => $notebook->product_name,
            'product_code' => $notebook->product_code,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // Sale 2: Owner 2 (4,300,000 after 240,000 return of 2 ink bottles)
        $sale2 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 4300000.00,
            'paid_amount' => 0.00,
            'due_amount' => 4300000.00,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-9218-2',
        ]);
        $checkoutSale2 = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 4540000, // Captured charge before return
            'split_key' => 'SPLIT-2',
            'tax_bucket' => 'NON_TAX',
        ]);
        $detailToner = SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $toner->id,
            'pos_transaction_line_id' => $line2->id,
            'quantity' => 1,
            'price' => 4300000,
            'unit_price' => 4300000,
            'sub_total' => 4300000,
            'product_name' => $toner->product_name,
            'product_code' => $toner->product_code,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $detailInk2 = SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $inkBottle->id,
            'pos_transaction_line_id' => $line3->id,
            'quantity' => 0, // Fully returned
            'price' => 120000,
            'unit_price' => 120000,
            'sub_total' => 0,
            'product_name' => $inkBottle->product_name,
            'product_code' => $inkBottle->product_code,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // Sale 3: Owner 3 (21,964,999.60 after 960,000 return of 8 ink bottles - 0.40 drift!)
        $sale3 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 21964999.60,
            'paid_amount' => 375000.00,
            'due_amount' => 21589999.60,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PARTIAL',
            'payment_method' => 'CASH',
            'reference' => 'SO-9218-3',
        ]);
        $checkoutSale3 = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale3->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 22925000, // Captured charge before return
            'split_key' => 'SPLIT-3',
            'tax_bucket' => 'NON_TAX',
        ]);
        SalePayment::create([
            'sale_id' => $sale3->id,
            'amount' => 375000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-9218',
            'payment_method' => 'CASH',
        ]);
        $detailInk8 = SaleDetails::create([
            'sale_id' => $sale3->id,
            'product_id' => $inkBottle->id,
            'pos_transaction_line_id' => $line4->id,
            'quantity' => 0, // Fully returned
            'price' => 120000,
            'unit_price' => 120000,
            'sub_total' => 0,
            'product_name' => $inkBottle->product_name,
            'product_code' => $inkBottle->product_code,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $detailMarker = SaleDetails::create([
            'sale_id' => $sale3->id,
            'product_id' => $marker->id,
            'pos_transaction_line_id' => $line5->id,
            'quantity' => 120,
            'price' => 6583.33,
            'unit_price' => 6583.33,
            'sub_total' => 790000,
            'product_name' => $marker->product_name,
            'product_code' => $marker->product_code,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $detailEquipment = SaleDetails::create([
            'sale_id' => $sale3->id,
            'product_id' => $equipment->id,
            'pos_transaction_line_id' => $line6->id,
            'quantity' => 1,
            'price' => 21175000,
            'unit_price' => 21175000,
            'sub_total' => 21175000,
            'product_name' => $equipment->product_name,
            'product_code' => $equipment->product_code,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // 5. Returns (10 Brother ink bottles: 2 from Owner 2, 8 from Owner 3 = Rp1,200,000)
        $posReturn = PosReturn::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'reference' => 'RET-9218-REF',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 1200000,
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash-9218',
            'is_reversed' => false,
            'settled_at' => now(),
            'approved_at' => now(),
        ]);

        $saleReturn2 = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'SR-9218-2',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 240000,
            'paid_amount' => 240000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'sale_id' => $sale2->id,
            'pos_return_id' => $posReturn->id,
        ]);
        SaleReturnDetail::create([
            'sale_return_id' => $saleReturn2->id,
            'product_id' => $inkBottle->id,
            'product_name' => $inkBottle->product_name,
            'product_code' => $inkBottle->product_code,
            'quantity' => 2,
            'price' => 120000,
            'unit_price' => 120000,
            'sub_total' => 240000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        SaleReturnPayment::create([
            'sale_return_id' => $saleReturn2->id,
            'amount' => 240000,
            'reference' => 'SRPAY-9218-2',
            'date' => now()->toDateString(),
            'payment_method' => 'Cash',
        ]);

        $saleReturn3 = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'SR-9218-3',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 960000,
            'paid_amount' => 960000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'sale_id' => $sale3->id,
            'pos_return_id' => $posReturn->id,
        ]);
        SaleReturnDetail::create([
            'sale_return_id' => $saleReturn3->id,
            'product_id' => $inkBottle->id,
            'product_name' => $inkBottle->product_name,
            'product_code' => $inkBottle->product_code,
            'quantity' => 8,
            'price' => 120000,
            'unit_price' => 120000,
            'sub_total' => 960000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        SaleReturnPayment::create([
            'sale_return_id' => $saleReturn3->id,
            'amount' => 960000,
            'reference' => 'SRPAY-9218-3',
            'date' => now()->toDateString(),
            'payment_method' => 'Cash',
        ]);

        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale2->id,
            'pos_transaction_line_id' => $line3->id,
            'product_id' => $inkBottle->id,
            'product_name' => $inkBottle->product_name,
            'product_code' => $inkBottle->product_code,
            'quantity' => 2,
            'unit_price' => 120000,
            'line_total' => 240000,
            'expected_cash_amount' => 240000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale2->id,
            'sale_detail_id' => $detailInk2->id,
            'sale_return_id' => $saleReturn2->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale3->id,
            'pos_transaction_line_id' => $line4->id,
            'product_id' => $inkBottle->id,
            'product_name' => $inkBottle->product_name,
            'product_code' => $inkBottle->product_code,
            'quantity' => 8,
            'unit_price' => 120000,
            'line_total' => 960000,
            'expected_cash_amount' => 960000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale3->id,
            'sale_detail_id' => $detailInk8->id,
            'sale_return_id' => $saleReturn3->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
        ]);

        // Retained / unchanged products mapped on the PosReturn with resolution = RESOLUTION_NONE:
        // 1. Notebook Folio Hardcover (Line 1, Owner 1)
        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale1->id,
            'pos_transaction_line_id' => $line1->id,
            'product_id' => $notebook->id,
            'product_name' => $notebook->product_name,
            'product_code' => $notebook->product_code,
            'quantity' => 1,
            'unit_price' => 3840000,
            'line_total' => 0,
            'expected_cash_amount' => 0,
            'resolution' => PosReturnLine::RESOLUTION_NONE,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale1->id,
            'sale_detail_id' => $detailNotebook->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
        ]);

        // 2. LaserJet Toner Cartridge Black (Line 2, Owner 2)
        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale2->id,
            'pos_transaction_line_id' => $line2->id,
            'product_id' => $toner->id,
            'product_name' => $toner->product_name,
            'product_code' => $toner->product_code,
            'quantity' => 1,
            'unit_price' => 4300000,
            'line_total' => 0,
            'expected_cash_amount' => 0,
            'resolution' => PosReturnLine::RESOLUTION_NONE,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale2->id,
            'sale_detail_id' => $detailToner->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
        ]);

        // 3. Marker Whiteboard Snowman 120 Pcs (Line 5, Owner 3)
        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale3->id,
            'pos_transaction_line_id' => $line5->id,
            'product_id' => $marker->id,
            'product_name' => $marker->product_name,
            'product_code' => $marker->product_code,
            'quantity' => 120,
            'unit_price' => 6583.33,
            'line_total' => 0,
            'expected_cash_amount' => 0,
            'resolution' => PosReturnLine::RESOLUTION_NONE,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale3->id,
            'sale_detail_id' => $detailMarker->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
        ]);

        // 4. Commercial Office Laminator Pro / Equipment (Line 6, Owner 3)
        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale3->id,
            'pos_transaction_line_id' => $line6->id,
            'product_id' => $equipment->id,
            'product_name' => $equipment->product_name,
            'product_code' => $equipment->product_code,
            'quantity' => 1,
            'unit_price' => 21175000,
            'line_total' => 0,
            'expected_cash_amount' => 0,
            'resolution' => PosReturnLine::RESOLUTION_NONE,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale3->id,
            'sale_detail_id' => $detailEquipment->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
        ]);

        return [
            'transaction' => $transaction,
            'checkout' => $checkout,
            'sale1' => $sale1,
            'sale2' => $sale2,
            'sale3' => $sale3,
            'posReturn' => $posReturn,
        ];
    }
}

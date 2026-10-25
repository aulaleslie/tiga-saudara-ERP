<?php

namespace Modules\Pos\Tests\Feature;

use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosReturnBalanceRepairService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class PosReturnBalanceRepairServiceTest extends PosTransactionFeatureTestCase
{
    protected $setting;
    protected $setting2;
    protected $location;
    protected $terminal;
    protected $session;
    protected $customer;
    protected $user;
    protected PosReturnBalanceRepairService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PosReturnBalanceRepairService::class);
        $this->setting = $this->createSetting('Balance Repair Test Store');
        $this->setting2 = $this->createSetting('Second Test Store');

        $this->user = $this->createUserForSetting($this->setting, 'Cashier User', ['pos.access']);
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    protected function createSaleDetail(array $attributes): SaleDetails
    {
        return SaleDetails::create(array_merge([
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ], $attributes));
    }

    protected function createPosReturn(array $attributes): PosReturn
    {
        return PosReturn::create(array_merge([
            'source_snapshot' => ['lines' => []],
            'source_snapshot_hash' => 'hash_default',
            'is_reversed' => false,
        ], $attributes));
    }

    protected function createSaleReturn(array $attributes): SaleReturn
    {
        return SaleReturn::create(array_merge([
            'date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'paid_amount' => $attributes['total_amount'] ?? 0,
            'due_amount' => 0,
            'payment_method' => 'CASH',
            'payment_status' => 'Paid',
        ], $attributes));
    }

    public function test_it_detects_proven_unit_price_header_drift_and_proposes_repair(): void
    {
        $fixture = $this->createDriftFixture();
        $plan = $this->service->plan($fixture['transaction']->id, $this->setting->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_REPAIRABLE, $plan['classification']);
        $this->assertNull($plan['reason']);
        $this->assertCount(1, $plan['sales']);

        $salePlan = $plan['sales'][0];
        $this->assertTrue($salePlan['has_drift']);
        $this->assertSame(40, $salePlan['drift_cents']);
        $this->assertEquals(0.40, $salePlan['drift_amount']);

        $this->assertEquals(804999.60, $salePlan['current']['total_amount']);
        $this->assertEquals(805000.00, $salePlan['proposed']['total_amount']);
        $this->assertEquals(375000.00, $salePlan['proposed']['paid_amount']);
        $this->assertEquals(430000.00, $salePlan['proposed']['due_amount']);
        $this->assertSame('PARTIAL', $salePlan['proposed']['payment_status']);

        $this->assertEquals(805000.00, $plan['summary']['proposed_grand_total']);
        $this->assertEquals(430000.00, $plan['summary']['proposed_due_total']);
    }

    public function test_it_preserves_authoritative_correct_rows(): void
    {
        $fixture = $this->createDriftFixture();
        $plan = $this->service->plan($fixture['transaction']->id);

        $details = $plan['sales'][0]['details'];
        $this->assertCount(2, $details);

        $markerRow = collect($details)->firstWhere('product_id', $fixture['marker']->id);
        $this->assertNotNull($markerRow);
        $this->assertEquals(120.0, $markerRow['quantity']);
        $this->assertEquals(790000.00, $markerRow['sub_total']);
        $this->assertEquals(6583.33, $markerRow['unit_price']);

        $notebookRow = collect($details)->firstWhere('product_id', $fixture['notebook']->id);
        $this->assertNotNull($notebookRow);
        $this->assertEquals(1.0, $notebookRow['quantity']);
        $this->assertEquals(15000.00, $notebookRow['sub_total']);
    }

    public function test_it_handles_owner_splits_reconciling_each_sale_independently(): void
    {
        $notebook = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Notebook Owner 1',
            'product_code' => 'NB-O1',
            'sale_price' => 3840000,
        ]);
        $marker = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Marker 120 Pcs Owner 2',
            'product_code' => 'MK-O2',
            'sale_price' => 6583.33,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SPLIT-TEST',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 25805000,
            'receipt_number' => 'RCP-SPLIT',
            'idempotency_key' => 'IDEM-SPLIT',
            'payload_hash' => 'HASH-SPLIT',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $otherGoods = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Equipment Other Goods',
            'product_code' => 'EQ-O2',
            'sale_price' => 21175000,
        ]);

        $line1 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $notebook->id,
            'product_name_snapshot' => $notebook->product_name,
            'qty' => 1,
            'unit_price' => 3840000,
        ]);

        $line2 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $marker->id,
            'product_name_snapshot' => $marker->product_name,
            'qty' => 120,
            'unit_price' => 6583.33,
            'line_meta' => [
                'line_total_minor' => 79000000,
                'line_gross_minor' => 79000000,
            ],
        ]);

        $line3 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 3,
            'product_id' => $otherGoods->id,
            'product_name_snapshot' => $otherGoods->product_name,
            'qty' => 1,
            'unit_price' => 21175000,
        ]);

        // Sale 1: Rp3,840,000 (No drift)
        $sale1 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 3840000,
            'paid_amount' => 0,
            'due_amount' => 3840000,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-O1',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 3840000,
            'split_key' => 'SPLIT-1',
            'tax_bucket' => 'NON_TAX',
        ]);
        $this->createSaleDetail([
            'sale_id' => $sale1->id,
            'product_id' => $notebook->id,
            'product_name' => 'Notebook Owner 1',
            'product_code' => 'NB-O1',
            'pos_transaction_line_id' => $line1->id,
            'quantity' => 1,
            'price' => 3840000,
            'unit_price' => 3840000,
            'sub_total' => 3840000,
        ]);

        // Sale 2: Rp21,965,000 with Rp0.40 drift (stored 21,964,999.60)
        $sale2 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 21964999.60,
            'paid_amount' => 375000,
            'due_amount' => 21589999.60,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PARTIAL',
            'payment_method' => 'CASH',
            'reference' => 'SO-O2',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 21965000,
            'split_key' => 'SPLIT-2',
            'tax_bucket' => 'NON_TAX',
        ]);
        // Row 1 of Sale 2: remaining marker row 120 pcs @ 6583.33 = 790,000
        $this->createSaleDetail([
            'sale_id' => $sale2->id,
            'product_id' => $marker->id,
            'product_name' => 'Marker 120 Pcs Owner 2',
            'product_code' => 'MK-O2',
            'pos_transaction_line_id' => $line2->id,
            'quantity' => 120,
            'price' => 6583.33,
            'unit_price' => 6583.33,
            'sub_total' => 790000,
        ]);
        // Row 2 of Sale 2: other items totaling 21,175,000
        $this->createSaleDetail([
            'sale_id' => $sale2->id,
            'product_id' => $otherGoods->id,
            'product_name' => 'Equipment Other Goods',
            'product_code' => 'EQ-O2',
            'pos_transaction_line_id' => $line3->id,
            'quantity' => 1,
            'price' => 21175000,
            'unit_price' => 21175000,
            'sub_total' => 21175000,
        ]);

        $plan = $this->service->plan($transaction->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_REPAIRABLE, $plan['classification']);
        $this->assertCount(2, $plan['sales']);

        $salePlan1 = collect($plan['sales'])->firstWhere('sale_id', $sale1->id);
        $this->assertFalse($salePlan1['has_drift']);
        $this->assertEquals(3840000.0, $salePlan1['proposed']['total_amount']);

        $salePlan2 = collect($plan['sales'])->firstWhere('sale_id', $sale2->id);
        $this->assertTrue($salePlan2['has_drift']);
        $this->assertSame(40, $salePlan2['drift_cents']);
        $this->assertEquals(21965000.0, $salePlan2['proposed']['total_amount']);
        $this->assertEquals(21590000.0, $salePlan2['proposed']['due_amount']);
    }

    public function test_it_supports_completed_partial_cash_returns(): void
    {
        $pen = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Gel Pen',
            'sale_price' => 10000,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-PARTIAL-RETURN',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 100000,
            'receipt_number' => 'RCP-PR',
            'idempotency_key' => 'IDEM-PR',
            'payload_hash' => 'HASH-PR',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 70000,
            'paid_amount' => 70000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-PR',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 100000,
            'split_key' => 'SPLIT-PR',
            'tax_bucket' => 'NON_TAX',
        ]);

        $line = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $pen->id,
            'product_name_snapshot' => $pen->product_name,
            'qty' => 10,
            'unit_price' => 10000,
        ]);

        // Stored remaining: 7 units @ 10,000 = 70,000
        $saleDetail = $this->createSaleDetail([
            'sale_id' => $sale->id,
            'product_id' => $pen->id,
            'product_name' => 'Gel Pen',
            'product_code' => 'PEN-01',
            'pos_transaction_line_id' => $line->id,
            'quantity' => 7,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 70000,
        ]);

        // Completed return of 3 units = 30,000
        $posReturn = $this->createPosReturn([
            'reference' => 'RET-PR-01',
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $checkout->id,
            'transaction_code' => $transaction->code,
            'receipt_number' => $checkout->receipt_number,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => ['lines' => []],
            'total_amount' => 30000,
        ]);

        $checkoutSale = PosCheckoutSale::where('pos_checkout_id', $checkout->id)->first();

        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'pos_transaction_line_id' => $line->id,
            'product_id' => $pen->id,
            'product_name' => $pen->product_name,
            'product_code' => 'PEN-01',
            'quantity' => 3,
            'unit_price' => 10000,
            'line_total' => 30000,
            'expected_cash_amount' => 30000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
        ]);

        $this->createSaleReturn([
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'total_amount' => 30000,
            'status' => SaleReturn::STATUS_COMPLETED,
            'reference' => 'SR-PR-01',
        ]);

        $plan = $this->service->plan($transaction->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_UNCHANGED, $plan['classification']);
        $this->assertEmpty($plan['block_reasons']);
        $this->assertEquals(70000.0, $plan['sales'][0]['proposed']['total_amount']);
    }

    public function test_it_preserves_legitimate_decimal_values_without_forced_rounding(): void
    {
        $cable = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Custom Cable',
            'sale_price' => 10.05,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-DECIMAL',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 100.50,
            'receipt_number' => 'RCP-DEC',
            'idempotency_key' => 'IDEM-DEC',
            'payload_hash' => 'HASH-DEC',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 100.50,
            'paid_amount' => 100.50,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-DEC',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 100.50,
            'split_key' => 'SPLIT-DEC',
            'tax_bucket' => 'NON_TAX',
        ]);

        $line = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $cable->id,
            'product_name_snapshot' => $cable->product_name,
            'qty' => 10,
            'unit_price' => 10.05,
        ]);

        $this->createSaleDetail([
            'sale_id' => $sale->id,
            'product_id' => $cable->id,
            'product_name' => 'Custom Cable',
            'product_code' => 'CBL-01',
            'pos_transaction_line_id' => $line->id,
            'quantity' => 10,
            'price' => 10.05,
            'unit_price' => 10.05,
            'sub_total' => 100.50,
        ]);

        $plan = $this->service->plan($transaction->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_UNCHANGED, $plan['classification']);
        $this->assertEquals(100.50, $plan['sales'][0]['proposed']['total_amount']);
    }

    public function test_it_blocks_ambiguous_header_difference_not_explained_by_reconstruction(): void
    {
        $fixture = $this->createDriftFixture();

        // Mutate sale header to an arbitrary unexplainable number (e.g. 800,000 instead of 804,999.60)
        $fixture['sale']->update(['total_amount' => 800000.00]);

        $plan = $this->service->plan($fixture['transaction']->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_BLOCKED, $plan['classification']);
        $this->assertNotEmpty($plan['block_reasons']);
        $this->assertStringContainsString('tidak dapat dibuktikan', $plan['reason']);
    }

    public function test_it_blocks_when_active_overlapping_return_in_progress(): void
    {
        $fixture = $this->createDriftFixture();

        // Create active/pending return
        $this->createPosReturn([
            'reference' => 'RET-ACTIVE-01',
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'transaction_code' => $fixture['transaction']->code,
            'receipt_number' => $fixture['checkout']->receipt_number,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'source_snapshot' => ['lines' => []],
            'total_amount' => 15000,
        ]);

        $plan = $this->service->plan($fixture['transaction']->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_BLOCKED, $plan['classification']);
        $this->assertStringContainsString('Terdapat retur aktif', $plan['reason']);
    }

    public function test_it_blocks_mismatched_business_scope_setting(): void
    {
        $fixture = $this->createDriftFixture();

        $plan = $this->service->plan($fixture['transaction']->id, $this->setting2->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_BLOCKED, $plan['classification']);
        $this->assertStringContainsString('bukan milik unit usaha setting', $plan['reason']);
    }

    /**
     * Create synthetic fixture with Rp0.40 header drift from 120 markers @ 6583.33.
     */
    protected function createDriftFixture(): array
    {
        $notebook = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Notebook',
            'product_code' => 'NB-01',
            'sale_price' => 15000,
        ]);

        $marker = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Marker Whiteboard 120 Pcs',
            'product_code' => 'MK-120',
            'sale_price' => 6583.33,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-DRIFT-TEST',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 805000,
            'receipt_number' => 'RCP-DRIFT',
            'idempotency_key' => 'IDEM-DRIFT',
            'payload_hash' => 'HASH-DRIFT',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 804999.60, // 805,000 - 0.40 drift
            'paid_amount' => 375000,
            'due_amount' => 429999.60,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PARTIAL',
            'payment_method' => 'CASH',
            'reference' => 'SO-DRIFT',
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 375000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-DRIFT',
            'payment_method' => 'CASH',
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 805000,
            'split_key' => 'SPLIT-DRIFT',
            'tax_bucket' => 'NON_TAX',
        ]);

        $line1 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $notebook->id,
            'product_name_snapshot' => $notebook->product_name,
            'qty' => 1,
            'unit_price' => 15000,
        ]);

        $line2 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $marker->id,
            'product_name_snapshot' => $marker->product_name,
            'qty' => 120,
            'unit_price' => 6583.33,
            'line_meta' => [
                'line_total_minor' => 79000000,
                'line_gross_minor' => 79000000,
            ],
        ]);

        $this->createSaleDetail([
            'sale_id' => $sale->id,
            'product_id' => $notebook->id,
            'product_name' => 'Notebook',
            'product_code' => 'NB-01',
            'pos_transaction_line_id' => $line1->id,
            'quantity' => 1,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 15000,
        ]);

        $this->createSaleDetail([
            'sale_id' => $sale->id,
            'product_id' => $marker->id,
            'product_name' => 'Marker Whiteboard 120 Pcs',
            'product_code' => 'MK-120',
            'pos_transaction_line_id' => $line2->id,
            'quantity' => 120,
            'price' => 6583.33,
            'unit_price' => 6583.33,
            'sub_total' => 790000,
        ]);

        return [
            'transaction' => $transaction,
            'checkout' => $checkout,
            'sale' => $sale,
            'notebook' => $notebook,
            'marker' => $marker,
        ];
    }

    public function test_it_blocks_offsetting_row_errors(): void
    {
        $fixture = $this->createDriftFixture();
        $sale = $fixture['sale'];

        // Detail 1 (Notebook): original qty 1, price 15,000 -> artificially set stored sub_total to 25,000 (+10,000)
        // Detail 2 (Marker): original 120 pcs, subtotal 790,000 -> artificially set stored sub_total to 780,000 (-10,000)
        // Total remains 805,000 (aggregate sum passes!), but each row has an offsetting error
        $details = $sale->saleDetails;
        $details[0]->update(['sub_total' => 25000]);
        $details[1]->update(['sub_total' => 780000]);

        $plan = $this->service->plan($fixture['transaction']->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_BLOCKED, $plan['classification']);
        $this->assertTrue(collect($plan['block_reasons'])->contains(fn ($r) => str_contains($r, 'nilai awal')));
    }

    public function test_it_blocks_sales_returns_with_invalid_or_missing_pos_return(): void
    {
        $fixture = $this->createDriftFixture();
        $sale = $fixture['sale'];

        // Create a foreign / unlinked SaleReturn
        SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'SR-FOREIGN',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'sale_id' => $sale->id,
            'pos_return_id' => 999999, // Unlinked to any valid completed PosReturn
        ]);

        $plan = $this->service->plan($fixture['transaction']->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_BLOCKED, $plan['classification']);
        $this->assertTrue(collect($plan['block_reasons'])->contains(fn ($r) => str_contains($r, 'tidak terhubung dengan PosReturn tunai')));
    }

    public function test_it_blocks_when_receipt_reprint_projection_is_ambiguous(): void
    {
        $fixture = $this->createDriftFixture();
        $sale = $fixture['sale'];

        // Remove pos_transaction_line_id from details, and add a second line with the same product
        // This triggers PosReprintAmbiguityException
        SaleDetails::where('sale_id', $sale->id)->update(['pos_transaction_line_id' => null]);
        PosTransactionLine::create([
            'pos_transaction_id' => $fixture['transaction']->id,
            'line_no' => 3,
            'product_id' => $fixture['notebook']->id, // Duplicate product in transaction lines
            'product_name_snapshot' => 'Duplicate Notebook Line',
            'qty' => 1,
            'unit_price' => 15000,
        ]);

        $plan = $this->service->plan($fixture['transaction']->id);

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_BLOCKED, $plan['classification']);
        $this->assertTrue(collect($plan['block_reasons'])->contains(fn ($r) => str_contains($r, 'Proyeksi cetak ulang struk')));
    }
}

<?php

namespace Modules\Pos\Tests\Feature;

use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Spatie\Permission\Models\Permission;

class POSReturnAuditTrailTest extends PosTransactionFeatureTestCase
{
    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

    protected PosReturnLifecycleService $lifecycleService;

    protected $setting;

    protected $location;

    protected $terminal;

    protected $session;

    protected $customer;

    protected $creator;

    protected $editor;

    protected $approver;

    protected $receiver;

    protected $settler;

    protected $dispatcher;

    protected $archiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->lifecycleService = app(PosReturnLifecycleService::class);

        $this->setting = $this->createSetting('POS Return Audit Trail Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        foreach ([
            'pos.returns.create',
            'pos.returns.edit',
            'pos.returns.approve',
            'pos.returns.receive',
            'pos.returns.settle',
            'pos.returns.dispatch',
            'pos.returns.delete',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->creator = $this->createUserForSetting($this->setting, 'POS Return Audit Creator', [
            'pos.access',
            'pos.returns.create',
        ]);

        $this->editor = $this->createUserForSetting($this->setting, 'POS Return Audit Editor', [
            'pos.access',
            'pos.returns.edit',
        ]);

        $this->approver = $this->createUserForSetting($this->setting, 'POS Return Audit Approver', [
            'pos.access',
            'pos.returns.approve',
        ]);

        $this->receiver = $this->createUserForSetting($this->setting, 'POS Return Audit Receiver', [
            'pos.access',
            'pos.returns.receive',
        ]);

        $this->settler = $this->createUserForSetting($this->setting, 'POS Return Audit Settler', [
            'pos.access',
            'pos.returns.settle',
        ]);

        $this->dispatcher = $this->createUserForSetting($this->setting, 'POS Return Audit Dispatcher', [
            'pos.access',
            'pos.returns.dispatch',
        ]);

        $this->archiver = $this->createUserForSetting($this->setting, 'POS Return Audit Archiver', [
            'pos.access',
            'pos.returns.delete',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->creator);
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
    }

    /** @test */
    public function it_records_create_and_edit_audit_fields_on_the_pos_return(): void
    {
        [$posReturn, $saleDetail] = $this->createPendingReturnWithSubmission();

        $this->assertSame($this->creator->id, $posReturn->created_by);
        $this->assertNull($posReturn->updated_by);
        $this->assertTrue($posReturn->saleReturns()->exists());

        $this->actingAsInSetting($this->editor, $this->setting);
        $updated = $this->submissionService->update($posReturn->fresh(), [
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $this->assertSame($this->editor->id, $updated->updated_by);
        $this->assertSame('1000.00', $updated->total_amount);
    }

    /** @test */
    public function it_records_approval_and_rejection_audit_fields(): void
    {
        [$approvalReturn] = $this->createPendingReturnWithSubmission();

        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->approve($approvalReturn->id);

        $approvalReturn->refresh();
        $this->assertSame($this->approver->id, $approvalReturn->approved_by);
        $this->assertNotNull($approvalReturn->approved_at);
        $this->assertTrue($approvalReturn->saleReturns()->where('approved_by', $this->approver->id)->exists());

        [$rejectionReturn] = $this->createPendingReturnWithSubmission('reject');

        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->reject($rejectionReturn->id, 'Audit rejection');

        $rejectionReturn->refresh();
        $this->assertSame($this->approver->id, $rejectionReturn->rejected_by);
        $this->assertNotNull($rejectionReturn->rejected_at);
        $this->assertSame('Audit rejection', $rejectionReturn->rejection_reason);
        $this->assertTrue($rejectionReturn->saleReturns()->where('rejected_by', $this->approver->id)->exists());
    }

    /** @test */
    public function it_records_receive_audit_fields(): void
    {
        [$posReturn] = $this->createApprovedReturnWithSubmission();

        $this->actingAsInSetting($this->receiver, $this->setting);
        $this->lifecycleService->receive($posReturn->id);

        $posReturn->refresh();
        $this->assertSame($this->receiver->id, $posReturn->received_by);
        $this->assertNotNull($posReturn->received_at);
        $this->assertTrue($posReturn->saleReturns()->where('received_by', $this->receiver->id)->exists());
    }

    /** @test */
    public function it_records_cash_refund_settlement_audit_fields(): void
    {
        [$posReturn, $saleReturn] = $this->createAwaitingSettlementReturn();

        $this->actingAsInSetting($this->settler, $this->setting);
        $this->lifecycleService->settlePaymentReturn($posReturn->id);

        $posReturn->refresh();
        $saleReturn->refresh();

        $this->assertSame($this->settler->id, $posReturn->settled_by);
        $this->assertNotNull($posReturn->settled_at);
        $this->assertSame($this->settler->id, $saleReturn->settled_by);
        $this->assertNotNull($saleReturn->settled_at);
        $this->assertTrue(SaleReturnPayment::query()->where('sale_return_id', $saleReturn->id)->exists());
    }

    /** @test */
    public function it_records_replacement_dispatch_audit_fields(): void
    {
        [$posReturn, $saleReturn] = $this->createAwaitingDispatchReturn();

        $this->actingAsInSetting($this->dispatcher, $this->setting);
        $this->lifecycleService->dispatchReplacement($posReturn->id);

        $posReturn->refresh();
        $saleReturn->refresh();

        $this->assertSame($this->dispatcher->id, $posReturn->settled_by);
        $this->assertNotNull($posReturn->settled_at);
        $this->assertSame($this->dispatcher->id, $saleReturn->settled_by);
        $this->assertNotNull($saleReturn->settled_at);
        $this->assertTrue(Dispatch::query()->where('sale_id', $saleReturn->sale_id)->where('approved_by', $this->dispatcher->id)->exists());
    }

    /** @test */
    public function it_records_archive_audit_fields_for_the_audited_reversal_path(): void
    {
        [$posReturn, $saleReturn] = $this->createApprovedReturnFixture();

        $this->actingAsInSetting($this->archiver, $this->setting);
        $this->lifecycleService->archive($posReturn->id, 'Audit archive');

        $posReturn->refresh();
        $saleReturn->refresh();

        $this->assertSame($this->archiver->id, $posReturn->archived_by);
        $this->assertNotNull($posReturn->archived_at);
        $this->assertSame('Audit archive', $posReturn->archive_reason);
        $this->assertNotNull($saleReturn->archived_at);
        $this->assertSame($this->archiver->id, $saleReturn->archived_by);
    }

    /**
     * @return array{0: PosReturn, 1: SaleDetails}
     */
    protected function createPendingReturnWithSubmission(string $suffix = 'base'): array
    {
        $this->actingAsInSetting($this->creator, $this->setting);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-' . $suffix . '-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->creator->id,
            'owner_user_id' => $this->creator->id,
            'last_saved_by' => $this->creator->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->creator->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1000,
            'receipt_number' => 'RCP-' . $suffix . '-' . uniqid(),
            'idempotency_key' => 'IDEM-' . $suffix . '-' . uniqid(),
            'payload_hash' => 'HASH-' . $suffix . '-' . uniqid(),
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . strtoupper($suffix) . '-' . uniqid(),
            'sale_price' => 500,
            'stock_qty' => 10,
        ]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . $suffix . '-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-' . $suffix . '-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
            'approved_by' => $this->creator->id,
            'approved_at' => now(),
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        return [$posReturn->fresh(['saleReturns']), $saleDetail];
    }

    /**
     * @return array{0: PosReturn, 1: SaleDetails}
     */
    protected function createApprovedReturnWithSubmission(): array
    {
        [$posReturn, $saleDetail] = $this->createPendingReturnWithSubmission('approved');

        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->approve($posReturn->id);

        return [$posReturn->fresh(['saleReturns']), $saleDetail];
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn}
     */
    protected function createAwaitingSettlementReturn(): array
    {
        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-SETTLE-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 600,
            'paid_amount' => 600,
            'due_amount' => 0,
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-SETTLE-' . uniqid(),
            'receipt_number' => 'RCP-SETTLE-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-settle-' . uniqid(),
            'reference' => 'PR-SETTLE-' . uniqid(),
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_AWAITING_SETTLEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 600,
            'received_by' => $this->receiver->id,
            'received_at' => now(),
            'created_by' => $this->creator->id,
            'updated_by' => $this->receiver->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Cash Return',
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SR-SETTLE-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 600,
            'paid_amount' => 0,
            'due_amount' => 600,
            'status' => 'AWAITING SETTLEMENT',
            'approval_status' => 'APPROVED',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
            'received_by' => $this->receiver->id,
            'received_at' => now(),
        ]);

        return [$posReturn, $saleReturn];
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn}
     */
    protected function createAwaitingDispatchReturn(): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-DISPATCH-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 10,
        ]);

        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-DISPATCH-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2000,
            'paid_amount' => 2000,
            'due_amount' => 0,
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-DISPATCH-' . uniqid(),
            'receipt_number' => 'RCP-DISPATCH-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-dispatch-' . uniqid(),
            'reference' => 'PR-DISPATCH-' . uniqid(),
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_AWAITING_DISPATCH,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 2000,
            'received_by' => $this->receiver->id,
            'received_at' => now(),
            'created_by' => $this->creator->id,
            'updated_by' => $this->receiver->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SR-DISPATCH-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'status' => 'AWAITING DISPATCH',
            'approval_status' => 'APPROVED',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
            'received_by' => $this->receiver->id,
            'received_at' => now(),
        ]);

        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => 1,
            'dispatch_detail_id' => null,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'unit_price' => 1000,
            'line_total' => 2000,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 2,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => 1,
            'dispatch_detail_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        return [$posReturn, $saleReturn];
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn}
     */
    protected function createApprovedReturnFixture(): array
    {
        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-ARCHIVE-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 600,
            'paid_amount' => 600,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-ARCHIVE-' . uniqid(),
            'receipt_number' => 'RCP-ARCHIVE-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-archive-' . uniqid(),
            'reference' => 'PR-ARCHIVE-' . uniqid(),
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 600,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'created_by' => $this->creator->id,
            'updated_by' => $this->approver->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Cash Return',
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SR-ARCHIVE-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 600,
            'paid_amount' => 0,
            'due_amount' => 600,
            'status' => 'AWAITING RECEIVING',
            'approval_status' => 'APPROVED',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
        ]);

        return [$posReturn, $saleReturn];
    }
}
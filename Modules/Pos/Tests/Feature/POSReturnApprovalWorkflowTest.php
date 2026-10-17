<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Pos\Entities\PosCheckoutSale;
use Spatie\Permission\Models\Permission;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Entities\PosTransactionLineSerial;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;

class POSReturnApprovalWorkflowTest extends PosTransactionFeatureTestCase
{
    protected $user;
    protected $approver;
    protected $submissionService;
    protected $snapshotService;
    protected $lifecycleService;
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->lifecycleService = app(PosReturnLifecycleService::class);
        
        $this->setting = $this->createSetting('POS Return Approval Test');
        
        Permission::findOrCreate('pos.returns.view', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');
        
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Clerk', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.create',
        ]);

        $this->approver = $this->createUserForSetting($this->setting, 'POS Return Approver', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.approve',
        ]);
        
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    protected function createDraftReturnContext(): array
    {
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-' . uniqid(),
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
            'grand_total' => 1000,
            'receipt_number' => 'RCP-' . uniqid(),
            'idempotency_key' => 'IDEM-' . uniqid(),
            'payload_hash' => 'HASH-' . uniqid(),
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . uniqid(),
            'sale_price' => 500,
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
            'reference' => 'SO-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-' . uniqid(),
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

        $snapshot = $this->snapshotService->build($transaction->id);

        $data = [
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                ]
            ]
        ];

        $this->actingAsInSetting($this->user, $this->setting);
        $draftReturn = $this->submissionService->store($data);

        return [$draftReturn->fresh('lines'), $saleDetail, $product, $transaction];
    }

    protected function createPendingReturn()
    {
        [$draftReturn] = $this->createDraftReturnContext();

        $this->actingAsInSetting($this->approver, $this->setting);

        return $this->submissionService->submitDraftForApproval($draftReturn);
    }

    /** @test */
    public function it_can_approve_a_pending_pos_return()
    {
        $posReturn = $this->createPendingReturn();
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->status);

        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->approve($posReturn->id);

        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_APPROVED, $posReturn->status);
        $this->assertEquals(PosReturn::APPROVAL_STATUS_APPROVED, $posReturn->approval_status);
        $this->assertEquals($this->approver->id, $posReturn->approved_by);
        $this->assertNotNull($posReturn->approved_at);
        
        // Linked Sales Returns should also be approved
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->assertEquals('AWAITING RECEIVING', $saleReturn->status);
            $this->assertEquals('APPROVED', $saleReturn->approval_status);
        }
    }

    /** @test */
    public function it_can_reject_a_pending_pos_return_with_reason()
    {
        $posReturn = $this->createPendingReturn();
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->status);

        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->reject($posReturn->id, 'Damaged product not eligible');

        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_REJECTED, $posReturn->status);
        $this->assertEquals(PosReturn::APPROVAL_STATUS_REJECTED, $posReturn->approval_status);
        $this->assertEquals($this->approver->id, $posReturn->rejected_by);
        $this->assertNotNull($posReturn->rejected_at);
        $this->assertEquals('Damaged product not eligible', $posReturn->rejection_reason);

        // Linked Sales Returns should also be rejected
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->assertEquals('REJECTED', $saleReturn->status);
            $this->assertEquals('REJECTED', $saleReturn->approval_status);
        }
    }

    /** @test */
    public function it_can_submit_a_valid_draft_for_approval_without_execution_side_effects()
    {
        [$posReturn, $saleDetail, $product] = $this->createDraftReturnContext();

        $initialProductQuantity = (int) $product->fresh()->product_quantity;
        $initialLocationQuantity = (int) DB::table('product_stocks')
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');
        $initialSaleDetailQuantity = (float) $saleDetail->fresh()->quantity;
        $sideEffectCounts = [
            'sale_returns' => DB::table('sale_returns')->count(),
            'sale_return_details' => DB::table('sale_return_details')->count(),
            'sale_return_payments' => DB::table('sale_return_payments')->count(),
            'dispatches' => DB::table('dispatches')->count(),
            'dispatch_details' => DB::table('dispatch_details')->count(),
            'serial_number_histories' => DB::table('serial_number_histories')->count(),
        ];

        $this->actingAsInSetting($this->user, $this->setting);
        $submittedReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $submittedReturn->status);
        $this->assertEquals(PosReturn::APPROVAL_STATUS_PENDING, $submittedReturn->approval_status);
        $this->assertEquals($this->user->id, $submittedReturn->updated_by);
        $this->assertDatabaseCount('sale_returns', $sideEffectCounts['sale_returns']);
        $this->assertDatabaseCount('sale_return_details', $sideEffectCounts['sale_return_details']);
        $this->assertDatabaseCount('sale_return_payments', $sideEffectCounts['sale_return_payments']);
        $this->assertDatabaseCount('dispatches', $sideEffectCounts['dispatches']);
        $this->assertDatabaseCount('dispatch_details', $sideEffectCounts['dispatch_details']);
        $this->assertDatabaseCount('serial_number_histories', $sideEffectCounts['serial_number_histories']);
        $this->assertSame($initialProductQuantity, (int) $product->fresh()->product_quantity);
        $this->assertSame($initialLocationQuantity, (int) DB::table('product_stocks')
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
        $this->assertSame($initialSaleDetailQuantity, (float) $saleDetail->fresh()->quantity);
    }

    /** @test */
    public function it_blocks_draft_submit_when_the_source_snapshot_is_stale()
    {
        [$posReturn] = $this->createDraftReturnContext();

        $posReturn->update(['source_snapshot_hash' => 'stale-hash']);

        $this->actingAsInSetting($this->approver, $this->setting);

        try {
            $this->submissionService->submitDraftForApproval($posReturn->fresh());
            $this->fail('Expected stale draft submission to be rejected.');
        } catch (\Exception $exception) {
            $this->assertSame('Source snapshot is stale. Please refresh the page.', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_DRAFT, $posReturn->fresh()->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_DRAFT, $posReturn->fresh()->approval_status);
    }

    /** @test */
    public function it_can_edit_a_rejected_return_and_reset_it_to_draft_while_preserving_rejection_audit_fields()
    {
        [$rejectedReturn, $saleDetail] = $this->createRejectedReturnContext();
        $this->user->givePermissionTo('pos.returns.edit');

        $rejectedAt = $rejectedReturn->rejected_at;
        $rejectedBy = $rejectedReturn->rejected_by;
        $rejectionReason = $rejectedReturn->rejection_reason;

        $this->actingAsInSetting($this->user, $this->setting);

        $updatedReturn = $this->submissionService->update($rejectedReturn->fresh(), [
            'source_snapshot_hash' => $rejectedReturn->source_snapshot_hash,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 2,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);

        $updatedReturn->refresh();

        $this->assertSame(PosReturn::STATUS_DRAFT, $updatedReturn->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_DRAFT, $updatedReturn->approval_status);
        $this->assertSame($rejectedBy, $updatedReturn->rejected_by);
        $this->assertEquals($rejectedAt?->toISOString(), $updatedReturn->rejected_at?->toISOString());
        $this->assertSame($rejectionReason, $updatedReturn->rejection_reason);
        $this->assertSame(1, $updatedReturn->lines()->count());
        $this->assertSame('2.0000', (string) $updatedReturn->lines()->first()->quantity);
    }

    /** @test */
    public function it_keeps_rejected_return_status_lines_and_audit_fields_when_rejected_revision_fails_validation()
    {
        [$rejectedReturn, $saleDetail] = $this->createRejectedReturnContext();
        $this->user->givePermissionTo('pos.returns.edit');

        $originalLine = $rejectedReturn->lines()->firstOrFail();
        $originalRejectedAt = $rejectedReturn->rejected_at;

        $this->actingAsInSetting($this->user, $this->setting);

        try {
            $this->submissionService->update($rejectedReturn->fresh(), [
                'source_snapshot_hash' => $rejectedReturn->source_snapshot_hash,
                'return_option' => PosReturn::OPTION_CASH_RETURN,
                'lines' => [
                    [
                        'sale_detail_id' => $saleDetail->id,
                        'quantity' => 999,
                        'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    ],
                ],
            ]);

            $this->fail('Expected invalid rejected revision to be rejected.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Kuantitas retur melebihi batas yang diizinkan', $exception->getMessage());
        }

        $rejectedReturn->refresh();
        $originalLine->refresh();

        $this->assertSame(PosReturn::STATUS_REJECTED, $rejectedReturn->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_REJECTED, $rejectedReturn->approval_status);
        $this->assertEquals($originalRejectedAt?->toISOString(), $rejectedReturn->rejected_at?->toISOString());
        $this->assertSame('Rejected by approver', $rejectedReturn->rejection_reason);
        $this->assertSame(1, $rejectedReturn->lines()->count());
        $this->assertSame((string) $originalLine->quantity, (string) $rejectedReturn->lines()->first()->quantity);
    }

    /** @test */
    public function it_blocks_draft_submit_when_the_persisted_draft_has_no_actionable_lines()
    {
        [$posReturn] = $this->createDraftReturnContext();

        $posReturn->lines()->update([
            'resolution' => PosReturnLine::RESOLUTION_NONE,
            'quantity' => 0,
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);

        try {
            $this->submissionService->submitDraftForApproval($posReturn->fresh());
            $this->fail('Expected empty draft submission to be rejected.');
        } catch (\Exception $exception) {
            $this->assertSame('Minimal satu item harus dipilih untuk retur (ganti produk atau uang kembali).', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_DRAFT, $posReturn->fresh()->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_DRAFT, $posReturn->fresh()->approval_status);
    }

    /** @test */
    public function it_blocks_draft_submit_when_the_persisted_draft_line_is_invalid()
    {
        [$posReturn] = $this->createDraftReturnContext();

        $posReturn->lines()->update([
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'quantity' => 999,
        ]);

        $this->actingAsInSetting($this->approver, $this->setting);

        try {
            $this->submissionService->submitDraftForApproval($posReturn->fresh());
            $this->fail('Expected invalid draft submission to be rejected.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Kuantitas retur melebihi batas yang diizinkan', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_DRAFT, $posReturn->fresh()->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_DRAFT, $posReturn->fresh()->approval_status);
    }

    /** @test */
    public function it_blocks_approval_if_not_pending_approval()
    {
        $posReturn = $this->createPendingReturn();
        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->approve($posReturn->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Only pending approval returns can be approved.");
        
        $this->lifecycleService->approve($posReturn->id);
    }

    /** @test */
    public function it_blocks_rejection_if_not_pending_approval()
    {
        $posReturn = $this->createPendingReturn();
        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->reject($posReturn->id, 'Rejected');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Only pending approval returns can be rejected.");
        
        $this->lifecycleService->reject($posReturn->id, 'Rejected again');
    }

    /** @test */
    public function it_enforces_permissions_for_approval_and_rejection()
    {
        $this->assertFalse($this->user->can('pos.returns.approve'));
        $this->assertTrue($this->approver->can('pos.returns.approve'));
    }

    protected function createRejectedReturnContext(): array
    {
        [$draftReturn, $saleDetail, $product, $transaction] = $this->createDraftReturnContext();

        $this->actingAsInSetting($this->user, $this->setting);
        $submittedReturn = $this->submissionService->submitDraftForApproval($draftReturn->fresh());

        $this->actingAsInSetting($this->approver, $this->setting);
        $this->lifecycleService->reject($submittedReturn->id, 'Rejected by approver');

        return [$submittedReturn->fresh('lines'), $saleDetail, $product, $transaction];
    }

    /** @test */
    public function it_allows_cash_return_of_non_serial_item_after_unrelated_serial_replacement_completed(): void
    {
        $this->actingAsInSetting($this->approver, $this->setting);

        // 1. Transaction with 5 non-serial pcs and 1 serialized pc (serial A)
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-MIX-' . uniqid(),
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
            'grand_total' => 6000,
            'receipt_number' => 'RCP-MIX-' . uniqid(),
            'idempotency_key' => 'IDEM-MIX-' . uniqid(),
            'payload_hash' => 'HASH-MIX-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 6000,
            'paid_amount' => 6000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-MIX-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 6000,
            'split_key' => 'SPLIT-MIX-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        // Non-serial product (5 pcs @ 1000 = 5000)
        $nonSerialProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Non-serial Widget',
            'product_code' => 'WIDGET-5',
            'sale_price' => 1000,
            'stock_qty' => 10,
            'serial_number_required' => false,
        ]);

        $nonSerialDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $nonSerialProduct->id,
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_name' => $nonSerialProduct->product_name,
            'product_code' => $nonSerialProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Serialized product (1 pc @ 1000)
        $serialProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Gadget',
            'product_code' => 'GADGET-1',
            'sale_price' => 1000,
            'stock_qty' => 5,
            'serial_number_required' => true,
        ]);

        $serialA = $this->createSerialNumber($serialProduct, $this->location, 'SN-GADGET-A');
        $serialB = $this->createSerialNumber($serialProduct, $this->location, 'SN-GADGET-B');

        $serialDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $serialProduct->id,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_name' => $serialProduct->product_name,
            'product_code' => $serialProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serialA->id],
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $nonSerialProduct->id,
            'dispatched_quantity' => 5,
            'location_id' => $this->location->id,
        ]);

        $dispatchSerial = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $serialProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$serialA->serial_number]),
        ]);

        $serialA->update([
            'dispatch_detail_id' => $dispatchSerial->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $nonSerialProduct->id,
            'product_name_snapshot' => $nonSerialProduct->product_name,
            'product_code_snapshot' => $nonSerialProduct->product_code,
            'qty' => 5,
            'unit_price' => 1000,
            'line_no' => 1,
        ]);

        $ptlSerial = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $serialProduct->id,
            'product_name_snapshot' => $serialProduct->product_name,
            'product_code_snapshot' => $serialProduct->product_code,
            'qty' => 1,
            'unit_price' => 1000,
            'line_no' => 2,
        ]);

        PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptlSerial->id,
            'serial_number' => $serialA->serial_number,
        ]);

        // Build initial snapshot
        $snapshot = $this->snapshotService->build($transaction->id);

        // Step 1: Return serial A with replacement B
        $return1 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $serialDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $serialA->id,
                    'replacement_serial_id' => $serialB->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                ],
            ],
        ]);
        $return1 = $this->submissionService->submitDraftForApproval($return1->fresh());

        $planner = app(PosReturnApprovalPreviewPlannerService::class);
        $plan1 = $planner->plan($return1->fresh());
        $this->assertFalse($plan1['is_blocked']);

        $this->lifecycleService->executeApprovalFromPreview($return1->id, PosReturn::OPTION_PRODUCT_REPLACEMENT, $plan1);

        $return1->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $return1->status);

        $serialA->refresh();
        $serialB->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $serialA->status);
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $serialB->status);

        // Step 2: Customer requests cash return of 1 pc non-serial widget
        $return2 = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $nonSerialDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $return2 = $this->submissionService->submitDraftForApproval($return2->fresh());

        $plan2 = $planner->plan($return2->fresh());
        $this->assertFalse($plan2['is_blocked'], 'Approval preview should not be blocked by unrelated serial replacement drift');

        $this->lifecycleService->executeApprovalFromPreview($return2->id, PosReturn::OPTION_CASH_RETURN, $plan2);

        $return2->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $return2->status);

        // Verify remaining quantity is 4 and serial B is unaffected
        $nonSerialDetail->refresh();
        $this->assertEquals(4, (float) $nonSerialDetail->quantity);

        $serialB->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $serialB->status);
        $this->assertNotNull($serialB->dispatch_detail_id);
    }

    /** @test */
    public function it_requires_renewed_review_when_requested_item_price_changes_and_allows_approval_after_acceptance(): void
    {
        $this->actingAsInSetting($this->approver, $this->setting);

        // 1. Setup Transaction with single product (5 pcs @ 1000)
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-RR-' . uniqid(),
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
            'grand_total' => 5000,
            'receipt_number' => 'RCP-RR-' . uniqid(),
            'idempotency_key' => 'IDEM-RR-' . uniqid(),
            'payload_hash' => 'HASH-RR-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-RR-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 10,
        ]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-RR-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 5000,
            'split_key' => 'SPLIT-RR-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 5,
            'location_id' => $this->location->id,
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'qty' => 5,
            'unit_price' => 1000,
            'line_no' => 1,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        // 2. Submit return for 1 pc @ 1000
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $planner = app(PosReturnApprovalPreviewPlannerService::class);
        $planBefore = $planner->plan($posReturn->fresh());
        $this->assertFalse($planBefore['is_blocked']);

        // 3. Monetary edit on source sale: price changes from 1000 to 1200
        $saleDetail->update([
            'unit_price' => 1200,
            'price' => 1200,
            'sub_total' => 6000,
        ]);
        $sale->update(['total_amount' => 6000]);

        // 4. Preview now reports renewed review required / price drift blocker
        $planDuring = $planner->plan($posReturn->fresh());
        $this->assertTrue($planDuring['is_blocked']);
        $driftBlocker = collect($planDuring['blockers'])->firstWhere('code', 'snapshot_drift');
        $this->assertNotNull($driftBlocker);
        $this->assertTrue($driftBlocker['renewed_review_required'] ?? false);
        $this->assertStringContainsString('peninjauan ulang', $driftBlocker['message']);

        // 5. Stale approval confirmation fails under lock
        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $planBefore);
            $this->fail('Expected execution to fail on stale monetary basis');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nilai moneter pada dokumen sumber telah berubah', $e->getMessage());
        }

        // 6. Preview route shows renewed review card and remains read-only
        $response = $this->get(route('pos.returns.approval-preview', $posReturn));
        $response->assertOk();
        $response->assertSee('Peninjauan Ulang Nilai Moneter Diperlukan');
        $this->assertSame(1000.0, (float) $posReturn->fresh()->lines->first()->unit_price);

        // 7. Explicitly accept renewed review
        $acceptResponse = $this->post(route('pos.returns.accept-monetary-review', $posReturn));
        $acceptResponse->assertRedirect(route('pos.returns.approval-preview', $posReturn));

        $posReturn->refresh();
        $lineAfterReview = $posReturn->lines->first();
        $this->assertSame(1200.0, (float) $lineAfterReview->unit_price);
        $this->assertSame(1200.0, (float) $lineAfterReview->line_total);
        $this->assertSame(1000.0, (float) data_get($lineAfterReview->line_meta, 'previous_unit_price'));
        $this->assertSame(1200.0, (float) data_get($lineAfterReview->line_meta, 'reviewed_unit_price'));
        $this->assertNotNull(data_get($lineAfterReview->line_meta, 'monetary_review_accepted_at'));

        // Original snapshot evidence remains untouched
        $this->assertSame($snapshot['hash'], $posReturn->source_snapshot_hash);

        // 8. Re-plan: blocker is cleared and approval becomes available
        $planAfter = $planner->plan($posReturn->fresh());
        $this->assertFalse($planAfter['is_blocked']);

        // 9. Final approval executes successfully
        $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $planAfter);

        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->status);
        $this->assertSame(4, (int) $saleDetail->fresh()->quantity);
    }

    /** @test */
    public function it_allows_approval_when_unrelated_item_price_is_edited(): void
    {
        $this->actingAsInSetting($this->approver, $this->setting);

        // 1. Setup Transaction with two products: Item 1 (5 pcs @ 1000), Item 2 (5 pcs @ 2000)
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-UNREL-' . uniqid(),
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
            'grand_total' => 15000,
            'receipt_number' => 'RCP-UNREL-' . uniqid(),
            'idempotency_key' => 'IDEM-UNREL-' . uniqid(),
            'payload_hash' => 'HASH-UNREL-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product1 = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD1-UNREL-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 10,
        ]);
        $product2 = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD2-UNREL-' . uniqid(),
            'sale_price' => 2000,
            'stock_qty' => 10,
        ]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 15000,
            'paid_amount' => 15000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-UNREL-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 15000,
            'split_key' => 'SPLIT-UNREL-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $detail1 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product1->id,
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_name' => $product1->product_name,
            'product_code' => $product1->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $detail2 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product2->id,
            'quantity' => 5,
            'price' => 2000,
            'unit_price' => 2000,
            'sub_total' => 10000,
            'product_name' => $product2->product_name,
            'product_code' => $product2->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product1->id,
            'dispatched_quantity' => 5,
            'location_id' => $this->location->id,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product2->id,
            'dispatched_quantity' => 5,
            'location_id' => $this->location->id,
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $product1->id,
            'product_name_snapshot' => $product1->product_name,
            'product_code_snapshot' => $product1->product_code,
            'qty' => 5,
            'unit_price' => 1000,
            'line_no' => 1,
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $product2->id,
            'product_name_snapshot' => $product2->product_name,
            'product_code_snapshot' => $product2->product_code,
            'qty' => 5,
            'unit_price' => 2000,
            'line_no' => 2,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        // 2. Submit return for 1 pc of Item 1 @ 1000
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $detail1->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        // 3. Edit Item 2 price from 2000 to 2500 on source sale
        $detail2->update([
            'unit_price' => 2500,
            'price' => 2500,
            'sub_total' => 12500,
        ]);
        $sale->update(['total_amount' => 17500]);

        // 4. Approval preview for Item 1 must NOT be blocked by unrelated Item 2 price change
        $planner = app(PosReturnApprovalPreviewPlannerService::class);
        $plan = $planner->plan($posReturn->fresh());
        $this->assertFalse($plan['is_blocked']);

        // 5. Final approval executes successfully without renewed review
        $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);

        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->status);
        $this->assertSame(4, (int) $detail1->fresh()->quantity);
        $this->assertSame(5, (int) $detail2->fresh()->quantity);
    }

    /** @test */
    public function it_requires_renewed_review_when_monetary_edit_changes_discount_or_header_allocation(): void
    {
        $this->actingAsInSetting($this->approver, $this->setting);

        // 1. Setup Transaction with Item (5 pcs @ 1000 = 5000)
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-DISC-' . uniqid(),
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
            'grand_total' => 5000,
            'receipt_number' => 'RCP-DISC-' . uniqid(),
            'idempotency_key' => 'IDEM-DISC-' . uniqid(),
            'payload_hash' => 'HASH-DISC-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-DISC-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 10,
        ]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-DISC-' . uniqid(),
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 5000,
            'split_key' => 'SPLIT-DISC-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 5,
            'location_id' => $this->location->id,
        ]);

        PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'qty' => 5,
            'unit_price' => 1000,
            'line_no' => 1,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        // 2. Submit return for 1 pc @ 1000
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $planner = app(PosReturnApprovalPreviewPlannerService::class);
        $planBefore = $planner->plan($posReturn->fresh());
        $this->assertFalse($planBefore['is_blocked']);

        // 3. Edit source sale: add header discount of 1000 (total_amount becomes 4000)
        // Note: unit_price remains 1000, but effective basis is (5000 - 1000) / 5 = 800!
        $sale->update([
            'discount_amount' => 1000,
            'total_amount' => 4000,
        ]);

        // 4. Preview planner must report renewed review required / snapshot drift blocker
        $planDuring = $planner->plan($posReturn->fresh());
        $this->assertTrue($planDuring['is_blocked']);
        $driftBlocker = collect($planDuring['blockers'])->firstWhere('code', 'snapshot_drift');
        $this->assertNotNull($driftBlocker);
        $this->assertTrue($driftBlocker['renewed_review_required'] ?? false);
        $this->assertSame(800.0, (float) ($driftBlocker['current_unit_price'] ?? 0));

        // 5. Stale approval confirmation fails under lock
        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $planBefore);
            $this->fail('Expected execution to fail on stale monetary basis');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nilai moneter pada dokumen sumber telah berubah', $e->getMessage());
        }

        // 6. Explicitly accept renewed review
        $acceptResponse = $this->post(route('pos.returns.accept-monetary-review', $posReturn));
        $acceptResponse->assertRedirect(route('pos.returns.approval-preview', $posReturn));

        $posReturn->refresh();
        $lineAfterReview = $posReturn->lines->first();
        $this->assertSame(800.0, (float) $lineAfterReview->unit_price);
        $this->assertSame(800.0, (float) $lineAfterReview->line_total);
        $this->assertSame(800.0, (float) $lineAfterReview->expected_cash_amount);
        $this->assertSame(1000.0, (float) data_get($lineAfterReview->line_meta, 'previous_unit_price'));
        $this->assertSame(800.0, (float) data_get($lineAfterReview->line_meta, 'reviewed_unit_price'));

        // 7. Re-plan: blocker cleared and approval succeeds
        $planAfter = $planner->plan($posReturn->fresh());
        $this->assertFalse($planAfter['is_blocked']);

        $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $planAfter);

        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->status);
        $this->assertSame(4, (int) $saleDetail->fresh()->quantity);
        $this->assertSame(4000.0, (float) $saleDetail->fresh()->sub_total);
        $this->assertSame(3200.0, (float) $sale->fresh()->total_amount);
        $this->assertSame(800.0, (float) $sale->fresh()->discount_amount);
        $this->assertSame(3200.0, (float) $sale->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $sale->fresh()->due_amount);
    }
}

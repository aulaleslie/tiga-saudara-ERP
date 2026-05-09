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
        
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');
        
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Clerk', [
            'pos.access',
            'pos.returns.create',
        ]);

        $this->approver = $this->createUserForSetting($this->setting, 'POS Return Approver', [
            'pos.access',
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
}

<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnLegacyPendingScopeBlockerTest extends PosTransactionFeatureTestCase
{
    protected $submissionService;
    protected $snapshotService;
    protected $lifecycleService;
    protected $previewPlanner;
    protected $user;
    protected $approver;
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->lifecycleService = app(PosReturnLifecycleService::class);
        $this->previewPlanner = app(PosReturnApprovalPreviewPlannerService::class);
        $this->setting = $this->createSetting('Legacy Scope Blocker Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.view', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'Scope Clerk', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.create',
            'pos.returns.edit',
        ]);

        $this->approver = $this->createUserForSetting($this->setting, 'Scope Approver', [
            'pos.access',
            'pos.returns.view',
            'pos.returns.approve',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function missing_scope_on_pending_bundle_return_blocks_approval_preview_and_execution(): void
    {
        $fixture = $this->createBundleContext();
        $posReturn = $fixture['pos_return'];

        // Simulate legacy row without scope (set bundle_return_scope to null)
        $parentLine = $posReturn->lines()->where('sale_detail_id', $fixture['parent_detail']->id)->first();
        $this->assertNotNull($parentLine);
        $parentLine->update(['bundle_return_scope' => null]);
        $this->assertNull($parentLine->fresh()->bundle_return_scope);

        // 1. Approval preview must report blocked status and bundle_scope_missing blocker
        $plan = $this->previewPlanner->plan($posReturn->fresh());
        $this->assertTrue($plan['is_blocked']);
        $this->assertSame('blocked', $plan['status']);
        $blockerCodes = collect($plan['blockers'])->pluck('code')->all();
        $this->assertContains('bundle_scope_missing', $blockerCodes);

        // 2. Lifecycle execution must reject approval with exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retur bundel memerlukan penentuan lingkup retur');

        $this->actingAs($this->approver);
        $this->lifecycleService->executeApprovalFromPreview($posReturn->id, null, null);
    }

    /** @test */
    public function rejected_legacy_draft_can_be_edited_with_explicit_scope_and_resubmitted_for_approval(): void
    {
        $fixture = $this->createBundleContext();
        $posReturn = $fixture['pos_return'];

        // Simulate legacy row without scope
        $parentLine = $posReturn->lines()->where('sale_detail_id', $fixture['parent_detail']->id)->first();
        $parentLine->update(['bundle_return_scope' => null]);

        $this->actingAs($this->approver);
        // Approver rejects the draft with audit reason
        $this->lifecycleService->reject($posReturn->id, 'Harap tentukan lingkup retur bundel (seluruh paket atau produk utama saja).');

        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_REJECTED, $posReturn->status);
        $this->assertSame('Harap tentukan lingkup retur bundel (seluruh paket atau produk utama saja).', $posReturn->rejection_reason);

        // User edits the draft to supply explicit whole_bundle scope
        $this->actingAs($this->user);
        $this->submissionService->update($posReturn, [
            'source_snapshot_hash' => $fixture['snapshot']['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['parent_detail']->id,
                    'sale_id' => $fixture['sale']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);

        $this->submissionService->submitDraftForApproval($posReturn);
        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->status);

        $newParentLine = $posReturn->lines()->where('sale_detail_id', $fixture['parent_detail']->id)->first();
        $this->assertSame(PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE, $newParentLine->bundle_return_scope);

        // Planner now reports ready (no bundle_scope_missing blocker)
        $newPlan = $this->previewPlanner->plan($posReturn);
        $this->assertFalse($newPlan['is_blocked']);
        $blockerCodes = collect($newPlan['blockers'])->pluck('code')->all();
        $this->assertNotContains('bundle_scope_missing', $blockerCodes);
    }

    /** @test */
    public function completed_historical_returns_with_null_scope_remain_unchanged(): void
    {
        $fixture = $this->createBundleContext();
        $posReturn = $fixture['pos_return'];

        // Mark as historically completed with null scope
        $posReturn->update([
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);
        $parentLine = $posReturn->lines()->where('sale_detail_id', $fixture['parent_detail']->id)->first();
        $parentLine->update(['bundle_return_scope' => null]);

        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->status);
        $this->assertNull($posReturn->lines()->first()->bundle_return_scope);

        // Attempting to run approve() on completed return throws because it is completed
        $this->expectException(\Exception::class);
        $this->lifecycleService->approve($posReturn->id);
    }

    protected function createBundleContext(): array
    {
        $comp = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Legacy Comp',
            'product_code' => 'LEG-C',
            'stock_qty' => 10,
        ]);
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Legacy Parent',
            'product_code' => 'LEG-P',
            'sale_price' => 500000,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Legacy Bundle',
            'is_active' => true,
        ]);
        $bi = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp->id, 'quantity' => 1]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-LEG-TEST',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 500000,
            'receipt_number' => 'RCP-LEG',
            'idempotency_key' => 'IDEM-LEG',
            'payload_hash' => 'HASH-LEG',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-LEG',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 500000,
            'split_key' => 'SPLIT-LEG',
            'tax_bucket' => 'NON_TAX',
        ]);

        $parentDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'quantity' => 1,
            'price' => 500000,
            'unit_price' => 500000,
            'sub_total' => 500000,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bi->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        $compDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $comp->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $comp->product_name,
            'product_code' => $comp->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'status' => 'APPROVED',
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'dispatched_quantity' => 1,
            'serial_numbers' => json_encode([]),
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $comp->id,
            'dispatched_quantity' => 1,
            'serial_numbers' => json_encode([]),
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentDetail->id,
                    'sale_id' => $sale->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);

        $this->submissionService->submitDraftForApproval($posReturn);

        return [
            'transaction' => $transaction,
            'sale' => $sale,
            'parent_detail' => $parentDetail,
            'comp_detail' => $compDetail,
            'pos_return' => $posReturn->fresh(),
            'snapshot' => $snapshot,
        ];
    }
}

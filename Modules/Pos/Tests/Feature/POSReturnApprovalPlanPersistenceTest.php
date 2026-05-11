<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnApprovalPlanPersistenceService;
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
use Spatie\Permission\Models\Permission;

class POSReturnApprovalPlanPersistenceTest extends PosTransactionFeatureTestCase
{
    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

    protected PosReturnApprovalPreviewPlannerService $planner;

    protected PosReturnLifecycleService $lifecycleService;

    protected $setting;

    protected $location;

    protected $terminal;

    protected $session;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->planner = app(PosReturnApprovalPreviewPlannerService::class);
        $this->lifecycleService = app(PosReturnLifecycleService::class);
        $this->setting = $this->createSetting('POS Return Approval Plan Persistence Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        foreach (['pos.returns.create', 'pos.returns.approve'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Approval Plan Persistence User', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.approve',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_creates_linked_sales_returns_from_ready_preview_during_final_approval(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [$sale, $saleDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'CRT');
        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $saleDetail->id,
            'quantity' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]]);
        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertSame([], $posReturn->fresh()->saleReturns->all());
        $this->assertSame([], $plan['blockers']);
        $this->assertSame([], $plan['warnings']);

        $this->lifecycleService->approve($posReturn->id, null, $plan);

        $posReturn = $posReturn->fresh(['lines', 'saleReturns.saleReturnDetails']);
        $this->assertSame(PosReturn::STATUS_APPROVED, $posReturn->status);
        $this->assertCount(1, $posReturn->saleReturns);

        $linkedSaleReturn = $posReturn->saleReturns->first();
        $this->assertSame($sale->id, (int) $linkedSaleReturn->sale_id);
        $this->assertSame($this->setting->id, (int) $linkedSaleReturn->setting_id);
        $this->assertSame($this->location->id, (int) $linkedSaleReturn->location_id);
        $this->assertSame('AWAITING RECEIVING', $linkedSaleReturn->status);
        $this->assertSame('APPROVED', $linkedSaleReturn->approval_status);
        $this->assertCount(1, $linkedSaleReturn->saleReturnDetails);

        $linkedDetail = $linkedSaleReturn->saleReturnDetails->first();
        $line = $posReturn->lines->first();
        $this->assertSame($line->id, (int) $linkedDetail->pos_return_line_id);
        $this->assertSame($linkedSaleReturn->id, (int) $line->sale_return_id);
        $this->assertSame($linkedDetail->id, (int) $line->sale_return_detail_id);
        $this->assertSame($this->location->id, (int) $linkedDetail->location_id);
    }

    /** @test */
    public function it_reuses_matching_linked_sales_returns_when_the_latest_plan_still_matches(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [, $saleDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'REUSE');
        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $saleDetail->id,
            'quantity' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]]);
        $plan = $this->planner->plan($posReturn->fresh());

        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($posReturn->fresh(), $plan);
        $seededPosReturn = $posReturn->fresh(['lines', 'saleReturns.saleReturnDetails']);
        $saleReturn = $seededPosReturn->saleReturns->first();
        $detail = $saleReturn->saleReturnDetails->first();

        $this->lifecycleService->approve($posReturn->id, null, $plan);

        $posReturn = $posReturn->fresh(['lines', 'saleReturns.saleReturnDetails']);
        $this->assertCount(1, $posReturn->saleReturns);
        $this->assertSame($saleReturn->id, (int) $posReturn->saleReturns->first()->id);
        $this->assertSame($detail->id, (int) $posReturn->lines->first()->sale_return_detail_id);
        $this->assertSame(PosReturn::STATUS_APPROVED, $posReturn->status);
    }

    /** @test */
    public function it_blocks_final_approval_when_existing_linked_sales_returns_conflict_with_the_latest_preview_plan(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [$sale, $saleDetail, $dispatchDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'MM');
        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $saleDetail->id,
            'quantity' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]]);
        $line = $posReturn->fresh('lines')->lines->first();
        $plan = $this->planner->plan($posReturn->fresh());

        $saleReturn = SaleReturn::query()->create([
            'date' => now()->toDateString(),
            'reference' => 'SR-MISMATCH-' . uniqid(),
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'customer_id' => $sale->customer_id,
            'customer_name' => $sale->customer_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 600,
            'paid_amount' => 0,
            'due_amount' => 600,
            'status' => 'PENDING APPROVAL',
            'payment_status' => 'PENDING',
            'payment_method' => $sale->payment_method,
            'note' => 'Pre-linked mismatch from test',
            'pos_return_id' => $posReturn->id,
            'approval_status' => 'PENDING',
            'return_type' => 'Cash Return',
        ]);
        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'product_id' => $saleDetail->product_id,
            'product_name' => $saleDetail->product_name,
            'product_code' => $saleDetail->product_code,
            'quantity' => 2,
            'price' => 600,
            'unit_price' => 600,
            'sub_total' => 1200,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'serial_number_ids' => [],
            'bundle_group_key' => null,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        try {
            $this->lifecycleService->approve($posReturn->id, null, $plan);
            $this->fail('Expected conflicting linked Sales Returns to block approval.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Linked Sales Returns conflict with the latest approval preview plan.', $exception->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->fresh()->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_PENDING, $posReturn->fresh()->approval_status);
    }

    /** @test */
    public function it_persists_component_execution_context_metadata_from_the_approval_preview_plan(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [$parentSale, $parentSaleDetail, $parentDispatchDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'CTXP');

        $componentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-CTXC-' . uniqid(),
            'product_name' => 'Persistence Component',
            'sale_price' => 60,
        ]);
        $componentSale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 120,
            'paid_amount' => 120,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-CTXC-' . uniqid(),
        ]);

        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $componentSale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 120,
            'split_key' => 'SPLIT-CTXC-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $componentPlaceholderDetail = SaleDetails::query()->create([
            'sale_id' => $componentSale->id,
            'product_id' => $componentProduct->id,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $componentBundleItem = \Modules\Sale\Entities\SaleBundleItem::query()->create([
            'sale_id' => $componentSale->id,
            'sale_detail_id' => $componentPlaceholderDetail->id,
            'bundle_id' => 9400,
            'bundle_item_id' => 9401,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 2,
            'price' => 60,
            'sub_total' => 120,
            'tax_amount' => 0,
            'line_group_key' => 'plan-component-0',
        ]);
        $componentDispatch = Dispatch::query()->create([
            'sale_id' => $componentSale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $componentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $componentDispatch->id,
            'sale_id' => $componentSale->id,
            'product_id' => $componentProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'tax_id' => null,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $parentSaleDetail->id,
            'quantity' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]]);
        $line = $posReturn->fresh('lines')->lines->firstOrFail();

        $plan = [
            'blockers' => [],
            'warnings' => [],
            'groups' => [
                [
                    'planned_header' => [
                        'sale_id' => $parentSale->id,
                        'setting_id' => $this->setting->id,
                        'location_id' => $this->location->id,
                        'return_type' => PosReturnLine::RESOLUTION_CASH_RETURN,
                        'total_amount' => 600,
                    ],
                    'tax_context' => ['tax_id' => null],
                    'planned_details' => [[
                        'row_type' => 'parent',
                        'pos_return_line_id' => $line->id,
                        'sale_detail_id' => $parentSaleDetail->id,
                        'dispatch_detail_id' => $parentDispatchDetail->id,
                        'source_location_id' => $this->location->id,
                        'tax_id' => null,
                        'product_id' => $parentSaleDetail->product_id,
                        'product_name' => $parentSaleDetail->product_name,
                        'product_code' => $parentSaleDetail->product_code,
                        'quantity' => 1,
                        'amount' => 600,
                        'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                        'dispatch_resolution' => 'pos_return_line.dispatch_detail_id',
                        'stock_movement_intent' => 'stok_sumber_akan_bertambah_saat_receiving',
                        'source_pos_sale_detail_id' => $parentSaleDetail->id,
                    ]],
                ],
                [
                    'planned_header' => [
                        'sale_id' => $componentSale->id,
                        'setting_id' => $this->setting->id,
                        'location_id' => $this->location->id,
                        'return_type' => PosReturnLine::RESOLUTION_CASH_RETURN,
                        'total_amount' => 120,
                    ],
                    'tax_context' => ['tax_id' => null],
                    'planned_details' => [[
                        'row_type' => 'component',
                        'pos_return_line_id' => $line->id,
                        'sale_detail_id' => $componentPlaceholderDetail->id,
                        'dispatch_detail_id' => $componentDispatchDetail->id,
                        'source_location_id' => $this->location->id,
                        'tax_id' => null,
                        'product_id' => $componentProduct->id,
                        'product_name' => $componentProduct->product_name,
                        'product_code' => $componentProduct->product_code,
                        'quantity' => 2,
                        'amount' => 120,
                        'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                        'dispatch_resolution' => 'component.sale_id+product_id',
                        'stock_movement_intent' => 'stok_sumber_akan_bertambah_saat_receiving',
                        'source_pos_sale_detail_id' => $parentSaleDetail->id,
                        'component_sale_bundle_item_id' => $componentBundleItem->id,
                        'component_line_group_key' => $componentBundleItem->line_group_key,
                        'component_bundle_id' => $componentBundleItem->bundle_id,
                        'component_quantity_per_bundle' => 2,
                    ]],
                ],
            ],
        ];

        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($posReturn->fresh(), $plan);

        $componentDetail = SaleReturnDetail::query()
            ->whereHas('saleReturn', fn ($query) => $query->where('pos_return_id', $posReturn->id)->where('sale_id', $componentSale->id))
            ->firstOrFail();

        $this->assertSame('component', data_get($componentDetail->execution_context, 'row_type'));
        $this->assertSame($componentSale->id, data_get($componentDetail->execution_context, 'source_sale_id'));
        $this->assertSame($parentSaleDetail->id, data_get($componentDetail->execution_context, 'source_sale_detail_id'));
        $this->assertSame($componentPlaceholderDetail->id, data_get($componentDetail->execution_context, 'component_source_sale_detail_id'));
        $this->assertSame($componentDispatchDetail->id, data_get($componentDetail->execution_context, 'component_dispatch_detail_id'));
        $this->assertSame($componentBundleItem->id, data_get($componentDetail->execution_context, 'component_sale_bundle_item_id'));
        $this->assertSame('sale_bundle_item', data_get($componentDetail->execution_context, 'quantity_source'));
        $this->assertSame('sale_bundle_item', data_get($componentDetail->execution_context, 'commercial_value_source'));
        $this->assertSame('component.sale_id+product_id', data_get($componentDetail->execution_context, 'dispatch_resolution'));
    }

    protected function createTransactionWithCheckout(): array
    {
        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-PERSIST-' . uniqid(),
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $checkout = PosCheckout::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1200,
            'receipt_number' => 'RCP-PERSIST-' . uniqid(),
            'idempotency_key' => 'IDEM-PERSIST-' . uniqid(),
            'payload_hash' => 'HASH-PERSIST-' . uniqid(),
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        return [$transaction, $checkout];
    }

    protected function createSaleGraph(PosCheckout $checkout, int $sourceSettingId, int $sourceLocationId, string $suffix): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . $suffix . '-' . uniqid(),
            'product_name' => 'Persistence Product ' . $suffix,
            'sale_price' => 600,
        ]);

        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 600,
            'paid_amount' => 600,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . $suffix . '-' . uniqid(),
        ]);

        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $sourceSettingId,
            'source_location_id' => $sourceLocationId,
            'grand_total' => 600,
            'split_key' => 'SPLIT-' . $suffix . '-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 600,
            'unit_price' => 600,
            'sub_total' => 600,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $sourceLocationId,
            'tax_id' => null,
        ]);

        return [$sale, $saleDetail, $dispatchDetail];
    }

    protected function makePendingReturn(int $transactionId, array $lines): PosReturn
    {
        $snapshot = $this->snapshotService->build($transactionId);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => $lines,
        ]);

        return $this->submissionService->submitDraftForApproval($posReturn);
    }
}
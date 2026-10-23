<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosReturnApprovalPlanPersistenceService;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;

class POSReturnBundleScopeLifecycleExecutionTest extends PosTransactionFeatureTestCase
{
    protected PosReturnLifecycleService $lifecycleService;
    protected PosReturnApprovalPreviewPlannerService $planner;
    protected PosReturnApprovalPlanPersistenceService $persistenceService;
    protected PosReturnSubmissionService $submissionService;
    protected PosReturnSnapshotService $snapshotService;

    protected $user;
    protected $settingA;
    protected $settingB;
    protected $locationA;
    protected $locationB;
    protected $terminalA;
    protected $sessionA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycleService = app(PosReturnLifecycleService::class);
        $this->planner = app(PosReturnApprovalPreviewPlannerService::class);
        $this->persistenceService = app(PosReturnApprovalPlanPersistenceService::class);
        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);

        $this->settingA = $this->createSetting('Scope Lifecycle Setting A');
        [$this->terminalA, $this->locationA] = $this->createTerminalWithLocation($this->settingA);

        $this->settingB = $this->createSetting('Scope Lifecycle Setting B');
        [, $this->locationB] = $this->createTerminalWithLocation($this->settingB);

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');

        $this->user = $this->createUserForSetting($this->settingA, 'Lifecycle Executor', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.edit',
            'pos.returns.approve',
        ]);

        $this->sessionA = $this->openSession($this->settingA, $this->terminalA, $this->user);
    }

    /** @test */
    public function it_executes_incident_shaped_non_serial_split_owner_whole_bundle_cash_return_unpaid(): void
    {
        $this->actingAsInSetting($this->user, $this->settingA);

        $fixture = $this->createIncidentSplitOwnerFixture(['is_paid' => false]);

        $initialCameraStock = (int) ProductStock::where('product_id', $fixture['parent_product']->id)
            ->where('location_id', $this->locationA->id)->value('quantity');
        $initialMicroSdStock = (int) ProductStock::where('product_id', $fixture['comp_product']->id)
            ->where('location_id', $this->locationB->id)->value('quantity');

        // 1. Snapshot & Submit draft with whole_bundle scope
        $snapshot = $this->snapshotService->build($fixture['pos_transaction']->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $fixture['pos_transaction']->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['parent_detail']->id,
                    'sale_id' => $fixture['parent_sale']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);

        // 2. Plan preview intent
        $plan = $this->planner->plan($posReturn->fresh());
        $this->assertFalse($plan['is_blocked']);

        // 3. Lifecycle approval execution
        $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // 4. Assert PosReturn completed
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        // 5. Stock received back at original owner locations
        $updatedCameraStock = (int) ProductStock::where('product_id', $fixture['parent_product']->id)
            ->where('location_id', $this->locationA->id)->value('quantity');
        $updatedMicroSdStock = (int) ProductStock::where('product_id', $fixture['comp_product']->id)
            ->where('location_id', $this->locationB->id)->value('quantity');
        $this->assertSame($initialCameraStock + 1, $updatedCameraStock, 'Camera must be received back at Location A');
        $this->assertSame($initialMicroSdStock + 1, $updatedMicroSdStock, 'MicroSD must be received back at Location B');

        // 6. Dispatch quantities reduced
        $this->assertSame(0, (int) $fixture['parent_dispatch_detail']->fresh()->dispatched_quantity);
        $this->assertSame(0, (int) $fixture['comp_dispatch_detail']->fresh()->dispatched_quantity);

        // 7. Commercial totals and debt: Rp630k and Rp260k source obligations become zero with zero cash payout
        $sale1 = $fixture['parent_sale']->fresh();
        $sale2 = $fixture['comp_sale']->fresh();
        $this->assertEquals(0.0, (float) $sale1->due_amount, 'Camera sale debt must be 0');
        $this->assertEquals(0.0, (float) $sale1->total_amount);
        $this->assertEquals(0.0, (float) $sale2->due_amount, 'MicroSD sale debt must be 0');
        $this->assertEquals(0.0, (float) $sale2->total_amount);

        // Zero cash payout
        $this->assertFalse(
            SaleReturnPayment::whereHas('saleReturn', fn ($q) => $q->where('pos_return_id', $posReturn->id))
                ->where('amount', '>', 0)
                ->exists(),
            'Unpaid return must create zero cash payout'
        );

        // Both sales marked returned
        $this->assertSame(Sale::STATUS_RETURNED, $sale1->status);
        $this->assertSame(Sale::STATUS_RETURNED, $sale2->status);
    }

    /** @test */
    public function it_executes_incident_shaped_non_serial_split_owner_parent_only_cash_return_unpaid(): void
    {
        $this->actingAsInSetting($this->user, $this->settingA);

        $fixture = $this->createIncidentSplitOwnerFixture(['is_paid' => false]);

        $initialCameraStock = (int) ProductStock::where('product_id', $fixture['parent_product']->id)
            ->where('location_id', $this->locationA->id)->value('quantity');
        $initialMicroSdStock = (int) ProductStock::where('product_id', $fixture['comp_product']->id)
            ->where('location_id', $this->locationB->id)->value('quantity');

        // 1. Snapshot & Submit draft with parent_only scope
        $snapshot = $this->snapshotService->build($fixture['pos_transaction']->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $fixture['pos_transaction']->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['parent_detail']->id,
                    'sale_id' => $fixture['parent_sale']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);

        // 2. Plan preview intent
        $plan = $this->planner->plan($posReturn->fresh());
        $this->assertFalse($plan['is_blocked']);

        // 3. Lifecycle approval execution
        $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // 4. Assert PosReturn completed
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        // 5. Stock: ONLY camera received at Location A; microSD stock at Location B unchanged
        $updatedCameraStock = (int) ProductStock::where('product_id', $fixture['parent_product']->id)
            ->where('location_id', $this->locationA->id)->value('quantity');
        $updatedMicroSdStock = (int) ProductStock::where('product_id', $fixture['comp_product']->id)
            ->where('location_id', $this->locationB->id)->value('quantity');
        $this->assertSame($initialCameraStock + 1, $updatedCameraStock, 'Camera must be received back at Location A');
        $this->assertSame($initialMicroSdStock, $updatedMicroSdStock, 'MicroSD stock must remain unchanged');

        // 6. Dispatch: Camera dispatch reduced to 0; MicroSD dispatch remains 1
        $this->assertSame(0, (int) $fixture['parent_dispatch_detail']->fresh()->dispatched_quantity);
        $this->assertSame(1, (int) $fixture['comp_dispatch_detail']->fresh()->dispatched_quantity, 'MicroSD dispatch obligation must be preserved');

        // 7. Commercial obligations: Camera sale cleared (630k reduction), MicroSD sale retains 260k debt
        $sale1 = $fixture['parent_sale']->fresh();
        $sale2 = $fixture['comp_sale']->fresh();
        $this->assertEquals(0.0, (float) $sale1->due_amount);
        $this->assertEquals(0.0, (float) $sale1->total_amount);
        $this->assertSame(Sale::STATUS_RETURNED, $sale1->status);

        $this->assertEquals(260000.0, (float) $sale2->due_amount, 'MicroSD sale debt of 260,000 must remain retained');
        $this->assertEquals(260000.0, (float) $sale2->total_amount, 'MicroSD sale total of 260,000 must remain retained');

        // Sale 2 must NOT be marked fully returned and must NOT be archived
        $this->assertNotSame(Sale::STATUS_RETURNED, $sale2->status, 'Sale with remaining component obligation must not be marked RETURNED');
        $this->assertNull($sale2->archived_at, 'Sale with remaining component obligation must not be archived');

        // Component SaleBundleItem remains intact
        $sbi = $fixture['comp_bundle_item']->fresh();
        $this->assertSame(1, (int) $sbi->quantity);
        $this->assertEquals(260000.0, (float) $sbi->sub_total);
    }

    /** @test */
    public function it_executes_incident_shaped_split_owner_whole_bundle_and_parent_only_paid(): void
    {
        $this->actingAsInSetting($this->user, $this->settingA);

        // Case A: Whole bundle paid return
        $fixtureA = $this->createIncidentSplitOwnerFixture(['is_paid' => true]);

        $snapshotA = $this->snapshotService->build($fixtureA['pos_transaction']->id);
        $posReturnA = $this->submissionService->store([
            'pos_transaction_id' => $fixtureA['pos_transaction']->id,
            'source_snapshot_hash' => $snapshotA['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixtureA['parent_detail']->id,
                    'sale_id' => $fixtureA['parent_sale']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);
        $posReturnA = $this->submissionService->submitDraftForApproval($posReturnA);
        $planA = $this->planner->plan($posReturnA->fresh());
        $this->lifecycleService->executeApprovalFromPreview($posReturnA->id, PosReturn::OPTION_CASH_RETURN, $planA);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturnA->fresh()->status);

        // Refund payments created per owner: 630k on Owner 1, 260k on Owner 2 = total 890k refund
        $srPaymentsA = SaleReturnPayment::whereHas('saleReturn', fn ($q) => $q->where('pos_return_id', $posReturnA->id))->get();
        $this->assertCount(2, $srPaymentsA);
        $this->assertEquals(890000.0, (float) $srPaymentsA->sum('amount'));

        // Case B: Parent only paid return
        $fixtureB = $this->createIncidentSplitOwnerFixture(['is_paid' => true]);

        $snapshotB = $this->snapshotService->build($fixtureB['pos_transaction']->id);
        $posReturnB = $this->submissionService->store([
            'pos_transaction_id' => $fixtureB['pos_transaction']->id,
            'source_snapshot_hash' => $snapshotB['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixtureB['parent_detail']->id,
                    'sale_id' => $fixtureB['parent_sale']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
                ],
            ],
        ]);
        $posReturnB = $this->submissionService->submitDraftForApproval($posReturnB);
        $planB = $this->planner->plan($posReturnB->fresh());
        $this->lifecycleService->executeApprovalFromPreview($posReturnB->id, PosReturn::OPTION_CASH_RETURN, $planB);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturnB->fresh()->status);

        // Refund payments: ONLY Owner 1 gets 630k refund; Owner 2 is not refunded
        $srPaymentsB = SaleReturnPayment::whereHas('saleReturn', fn ($q) => $q->where('pos_return_id', $posReturnB->id))->get();
        $this->assertCount(1, $srPaymentsB);
        $this->assertEquals(630000.0, (float) $srPaymentsB->first()->amount);

        // Owner 2 sale remains paid with 260k
        $sale2B = $fixtureB['comp_sale']->fresh();
        $this->assertEquals(260000.0, (float) $sale2B->paid_amount);
        $this->assertEquals(0.0, (float) $sale2B->due_amount);
    }

    /** @test */
    public function it_executes_same_owner_bundle_with_stockless_component_for_both_scopes(): void
    {
        $this->actingAsInSetting($this->user, $this->settingA);

        // Setup same-owner bundle: Parent stock-managed, component stockless
        $parentProduct = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_name' => 'Stocked Camera',
            'product_code' => 'STK-CAM-' . uniqid(),
            'sale_price' => 500000,
            'stock_qty' => 10,
        ]);

        $stocklessComp = $this->createNonStockProduct($this->settingA, 'WRN-' . uniqid(), 'Extended Warranty', 150000);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parentProduct->id,
            'name' => 'Camera + Warranty Bundle',
            'setting_id' => $this->settingA->id,
            'is_active' => true,
        ]);
        $bItem2 = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $stocklessComp->id,
            'quantity' => 1,
            'informational_item_price' => 150000,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'customer_name' => 'Walk-in Customer',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 650000,
            'paid_amount' => 650000,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'reference' => 'SO-SAME-' . uniqid(),
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 650000,
            'date' => now()->toDateString(),
            'payment_method' => 'Cash',
            'reference' => 'PAY-SAME-' . uniqid(),
        ]);

        $parentDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 650000,
            'unit_price' => 650000,
            'sub_total' => 650000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bItem2->id,
            'product_id' => $stocklessComp->id,
            'name' => $stocklessComp->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);
        $compDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $stocklessComp->id,
            'product_name' => $stocklessComp->product_name,
            'product_code' => $stocklessComp->product_code,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'status' => Dispatch::STATUS_APPROVED,
            'dispatch_date' => now(),
        ]);
        $parentDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->locationA->id,
        ]);

        $posTxn = $this->createPosTransaction([
            'transaction_number' => 'TXN-SAME-TEST-' . uniqid(),
            'code' => 'TXN-SAME-TEST-' . uniqid(),
            'total_amount' => 650000,
            'setting_id' => $this->settingA->id,
        ]);
        $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'qty' => 1,
            'unit_price' => 650000,
            'subtotal' => 650000,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->name,
                'bundle_items' => [
                    [
                        'product_id' => $stocklessComp->id,
                        'name' => $stocklessComp->product_name,
                        'quantity' => 1,
                        'stock_managed' => false,
                        'bundle_item_id' => $bItem2->id,
                    ],
                ],
            ],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => $posTxn->id,
            'pos_session_id' => $this->sessionA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 650000,
            'receipt_number' => 'RCP-SAME-TEST',
            'idempotency_key' => 'IDEM-SAME-TEST-' . uniqid(),
            'payload_hash' => 'HASH-SAME-TEST-' . uniqid(),
        ]);
        $posTxn->update(['completed_checkout_id' => $checkout->id]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'grand_total' => 650000,
            'split_key' => 'SPLIT-SAME-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $initialCameraStock = (int) ProductStock::where('product_id', $parentProduct->id)
            ->where('location_id', $this->locationA->id)->value('quantity');

        // Execute whole-bundle return
        $snapshot = $this->snapshotService->build($posTxn->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $posTxn->id,
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
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);

        $plan = $this->planner->plan($posReturn->fresh());
        $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        // Parent camera received; stockless component has no stock record
        $updatedCameraStock = (int) ProductStock::where('product_id', $parentProduct->id)
            ->where('location_id', $this->locationA->id)->value('quantity');
        $this->assertSame($initialCameraStock + 1, $updatedCameraStock);
        $this->assertFalse(ProductStock::where('product_id', $stocklessComp->id)->exists());

        // Refund for the entire bundle 650k
        $srPayment = SaleReturnPayment::whereHas('saleReturn', fn ($q) => $q->where('pos_return_id', $posReturn->id))->first();
        $this->assertNotNull($srPayment);
        $this->assertEquals(650000.0, (float) $srPayment->amount);
    }

    /** @test */
    public function it_enforces_execution_guards_and_invariants(): void
    {
        $this->actingAsInSetting($this->user, $this->settingA);

        $fixture = $this->createIncidentSplitOwnerFixture(['is_paid' => false]);

        // Guard 1: Missing scope throws exception during approval
        $posReturnMissingScope = PosReturn::create([
            'pos_transaction_id' => $fixture['pos_transaction']->id,
            'pos_checkout_id' => $fixture['pos_transaction']->completed_checkout_id,
            'transaction_code' => $fixture['pos_transaction']->code,
            'receipt_number' => 'RCP-GUARD-1',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-guard-1',
            'setting_id' => $this->settingA->id,
            'created_by' => $this->user->id,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 630000,
            'due_amount' => 630000,
            'paid_amount' => 0,
            'reference' => 'PRTN-GUARD-MISSING',
        ]);
        PosReturnLine::create([
            'pos_return_id' => $posReturnMissingScope->id,
            'pos_checkout_sale_id' => $fixture['parent_checkout_sale']->id,
            'sale_id' => $fixture['parent_sale']->id,
            'sale_detail_id' => $fixture['parent_detail']->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $fixture['parent_product']->id,
            'product_name' => $fixture['parent_product']->product_name,
            'product_code' => $fixture['parent_product']->product_code,
            'quantity' => 1,
            'unit_price' => 630000,
            'line_total' => 630000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'bundle_group_key' => 'bundle-' . $fixture['parent_detail']->id,
            'bundle_return_scope' => null, // MISSING
        ]);

        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturnMissingScope->id, PosReturn::OPTION_CASH_RETURN);
            $this->fail('Expected missing scope exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Retur bundel memerlukan penentuan lingkup retur', $e->getMessage());
        }

        // Guard 2: Invalid scope throws exception
        $posReturnMissingScope->lines()->first()->update(['bundle_return_scope' => 'invalid_scope']);
        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturnMissingScope->id, PosReturn::OPTION_CASH_RETURN);
            $this->fail('Expected invalid scope exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tidak valid', $e->getMessage());
        }

        // Guard 3: Parent only with extraneous component line throws exception
        $posReturnMissingScope->lines()->first()->update(['bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY]);
        $extraneousLine = PosReturnLine::create([
            'pos_return_id' => $posReturnMissingScope->id,
            'pos_checkout_sale_id' => $fixture['comp_checkout_sale']->id,
            'sale_id' => $fixture['comp_sale']->id,
            'sale_detail_id' => $fixture['carrier_detail']->id,
            'bundle_parent_sale_detail_id' => $fixture['parent_detail']->id,
            'source_setting_id' => $this->settingB->id,
            'source_location_id' => $this->locationB->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $fixture['comp_product']->id,
            'product_name' => $fixture['comp_product']->product_name,
            'product_code' => $fixture['comp_product']->product_code,
            'quantity' => 1,
            'unit_price' => 260000,
            'line_total' => 260000,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);

        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturnMissingScope->id, PosReturn::OPTION_CASH_RETURN);
            $this->fail('Expected extraneous component exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Baris komponen tidak boleh disertakan saat lingkup retur dipilih hanya produk utama', $e->getMessage());
        }

        // Guard 4: Whole bundle missing required component throws exception
        $extraneousLine->delete();
        $posReturnMissingScope->lines()->first()->update(['bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE]);
        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturnMissingScope->id, PosReturn::OPTION_CASH_RETURN);
            $this->fail('Expected missing required component exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wajib disertakan saat lingkup retur dipilih seluruh paket', $e->getMessage());
        }

        // Guard 5: Component cash return line alone without parent throws exception
        $posReturnComponentAlone = PosReturn::create([
            'pos_transaction_id' => $fixture['pos_transaction']->id,
            'pos_checkout_id' => $fixture['pos_transaction']->completed_checkout_id,
            'transaction_code' => $fixture['pos_transaction']->code,
            'receipt_number' => 'RCP-GUARD-2',
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-guard-2',
            'setting_id' => $this->settingA->id,
            'created_by' => $this->user->id,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'total_amount' => 260000,
            'due_amount' => 260000,
            'paid_amount' => 0,
            'reference' => 'PRTN-GUARD-ALONE',
        ]);
        PosReturnLine::create([
            'pos_return_id' => $posReturnComponentAlone->id,
            'pos_checkout_sale_id' => $fixture['comp_checkout_sale']->id,
            'sale_id' => $fixture['comp_sale']->id,
            'sale_detail_id' => $fixture['carrier_detail']->id,
            'bundle_parent_sale_detail_id' => 999999, // Absent parent
            'source_setting_id' => $this->settingB->id,
            'source_location_id' => $this->locationB->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $fixture['comp_product']->id,
            'product_name' => $fixture['comp_product']->product_name,
            'product_code' => $fixture['comp_product']->product_code,
            'quantity' => 1,
            'unit_price' => 260000,
            'line_total' => 260000,
            'bundle_group_key' => 'bundle-guard-alone',
            'component_quantity_per_bundle' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]);
        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturnComponentAlone->id, PosReturn::OPTION_CASH_RETURN);
            $this->fail('Expected component without parent exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Baris komponen tidak dapat diretur tunai tanpa baris bundel utama', $e->getMessage());
        }
    }

    /** @test */
    public function it_maintains_retry_idempotency_and_atomic_rollback(): void
    {
        $this->actingAsInSetting($this->user, $this->settingA);

        $fixture = $this->createIncidentSplitOwnerFixture(['is_paid' => false]);

        // Complete one return successfully
        $snapshot = $this->snapshotService->build($fixture['pos_transaction']->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $fixture['pos_transaction']->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['parent_detail']->id,
                    'sale_id' => $fixture['parent_sale']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);
        $plan = $this->planner->plan($posReturn->fresh());
        $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        $cameraStockAfter = (int) ProductStock::where('product_id', $fixture['parent_product']->id)
            ->where('location_id', $this->locationA->id)->value('quantity');

        // Retry: Calling executeApprovalFromPreview again throws exception
        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);
            $this->fail('Expected retry exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Only pending approval returns can be executed', $e->getMessage());
        }

        // Assert no duplicate stock receiving
        $cameraStockAfterRetry = (int) ProductStock::where('product_id', $fixture['parent_product']->id)
            ->where('location_id', $this->locationA->id)->value('quantity');
        $this->assertSame($cameraStockAfter, $cameraStockAfterRetry);
    }

    /** @test */
    public function whole_bundle_approval_blocks_and_rejects_when_affected_price_changes_after_submission(): void
    {
        $this->actingAsInSetting($this->user, $this->settingA);

        $fixture = $this->createIncidentSplitOwnerFixture(['is_paid' => false]);

        // 1. Snapshot & Submit draft with whole_bundle scope (initial unit price Rp890,000)
        $snapshot = $this->snapshotService->build($fixture['pos_transaction']->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $fixture['pos_transaction']->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['parent_detail']->id,
                    'sale_id' => $fixture['parent_sale']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);

        // Verify initial plan is approvable with Rp890,000
        $initialPlan = $this->planner->plan($posReturn->fresh());
        $this->assertFalse($initialPlan['is_blocked']);

        // 2. Change camera parent allocation from Rp630,000 to Rp600,000 after submission
        $fixture['parent_detail']->update([
            'unit_price' => 600000,
            'price' => 600000,
            'sub_total' => 600000,
        ]);
        $fixture['parent_sale']->update([
            'total_amount' => 600000,
            'due_amount' => 600000,
        ]);

        // 3. Approval preview planner must detect monetary drift and report snapshot_drift blocker
        $planAfterPriceChange = $this->planner->plan($posReturn->fresh());
        $this->assertTrue($planAfterPriceChange['is_blocked'], 'Plan must be blocked when parent price allocation changes.');
        $blockerCodes = collect($planAfterPriceChange['blockers'])->pluck('code')->all();
        $this->assertContains('snapshot_drift', $blockerCodes);

        $driftBlocker = collect($planAfterPriceChange['blockers'])->firstWhere('code', 'snapshot_drift');
        $this->assertTrue($driftBlocker['renewed_review_required'] ?? false);
        $this->assertEquals(890000.0, (float) ($driftBlocker['submitted_unit_price'] ?? 0));
        $this->assertEquals(860000.0, (float) ($driftBlocker['current_unit_price'] ?? 0));

        // 4. Execution must reject approval attempt:
        // 4a. When given the blocked plan, executeApprovalFromPreview refuses to execute
        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $planAfterPriceChange);
            $this->fail('Expected RuntimeException for blocked plan was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Preview persetujuan terbaru belum siap dieksekusi', $e->getMessage());
        }

        // 4b. Even if a caller attempts to bypass with a forged unblocked plan,
        // the lifecycle service re-evaluates financial basis under execution and aborts.
        $forgedPlan = array_merge($planAfterPriceChange, ['is_blocked' => false, 'blockers' => []]);
        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $forgedPlan);
            $this->fail('Expected RuntimeException for underlying financial drift was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nilai moneter pada dokumen sumber telah berubah', $e->getMessage());
        }

        // PosReturn must remain in pending approval
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Helper Fixtures
    // -------------------------------------------------------------------------

    private function createIncidentSplitOwnerFixture(array $options = []): array
    {
        $isPaid = (bool) ($options['is_paid'] ?? false);

        $parentProduct = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_name' => 'Kamera Test',
            'product_code' => 'CAM-' . uniqid(),
            'sale_price' => 630000,
            'stock_qty' => 10,
        ]);
        $compProduct = $this->createStockedProduct($this->settingB, $this->locationB, [
            'product_name' => 'MicroSD Test',
            'product_code' => 'MSD-' . uniqid(),
            'sale_price' => 260000,
            'stock_qty' => 10,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parentProduct->id,
            'name' => 'Bundle Kamera + MicroSD',
            'setting_id' => $this->settingA->id,
            'is_active' => true,
        ]);
        $bItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compProduct->id,
            'quantity' => 1,
            'informational_item_price' => 260000,
        ]);

        $sale1 = Sale::create([
            'date' => now(),
            'customer_name' => 'Walk-in Customer',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 630000,
            'paid_amount' => $isPaid ? 630000 : 0,
            'due_amount' => $isPaid ? 0 : 630000,
            'status' => 'DISPATCHED',
            'payment_status' => $isPaid ? 'PAID' : 'UNPAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'reference' => 'SO-CAM-' . uniqid(),
        ]);
        if ($isPaid) {
            SalePayment::create([
                'sale_id' => $sale1->id,
                'amount' => 630000,
                'date' => now()->toDateString(),
                'reference' => 'PAY-CAM-' . uniqid(),
                'payment_method' => 'Cash',
            ]);
        }

        $parentDetail = SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch1 = Dispatch::create([
            'sale_id' => $sale1->id,
            'status' => Dispatch::STATUS_APPROVED,
            'dispatch_date' => now(),
        ]);
        $parentDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale1->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->locationA->id,
        ]);

        $sale2 = Sale::create([
            'date' => now(),
            'customer_name' => 'Walk-in Customer',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 260000,
            'paid_amount' => $isPaid ? 260000 : 0,
            'due_amount' => $isPaid ? 0 : 260000,
            'status' => 'DISPATCHED',
            'payment_status' => $isPaid ? 'PAID' : 'UNPAID',
            'payment_method' => 'Cash',
            'setting_id' => $this->settingB->id,
            'reference' => 'SO-MSD-' . uniqid(),
        ]);
        if ($isPaid) {
            SalePayment::create([
                'sale_id' => $sale2->id,
                'amount' => 260000,
                'date' => now()->toDateString(),
                'reference' => 'PAY-MSD-' . uniqid(),
                'payment_method' => 'Cash',
            ]);
        }

        $carrierDetail = SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $sbi = SaleBundleItem::create([
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bItem->id,
            'product_id' => $compProduct->id,
            'name' => $compProduct->product_name,
            'quantity' => 1,
            'price' => 260000,
            'sub_total' => 260000,
        ]);

        $dispatch2 = Dispatch::create([
            'sale_id' => $sale2->id,
            'status' => Dispatch::STATUS_APPROVED,
            'dispatch_date' => now(),
        ]);
        $compDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'product_id' => $compProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->locationB->id,
            'bundle_id' => $bundle->id,
        ]);

        $posTxn = $this->createPosTransaction([
            'transaction_number' => 'TNC-TXN-2026-10-0638-TEST-' . uniqid(),
            'code' => 'TNC-TXN-2026-10-0638-TEST-' . uniqid(),
            'total_amount' => 890000,
            'setting_id' => $this->settingA->id,
        ]);

        $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'qty' => 1,
            'unit_price' => 890000,
            'subtotal' => 890000,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->name,
                'bundle_items' => [
                    [
                        'product_id' => $compProduct->id,
                        'name' => $compProduct->product_name,
                        'quantity' => 1,
                        'bundle_item_id' => $bItem->id,
                    ],
                ],
            ],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => $posTxn->id,
            'pos_session_id' => $this->sessionA->id,
            'terminal_id' => $this->terminalA->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 890000,
            'receipt_number' => 'RCP-INC-TEST-' . uniqid(),
            'idempotency_key' => 'IDEM-INC-TEST-' . uniqid(),
            'payload_hash' => 'HASH-INC-TEST-' . uniqid(),
        ]);
        $posTxn->update(['completed_checkout_id' => $checkout->id]);

        $parentCheckoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'grand_total' => 630000,
            'split_key' => 'SPLIT-CAM-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $compCheckoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'source_setting_id' => $this->settingB->id,
            'source_location_id' => $this->locationB->id,
            'grand_total' => 260000,
            'split_key' => 'SPLIT-MSD-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        return [
            'pos_transaction' => $posTxn,
            'parent_detail' => $parentDetail,
            'parent_sale' => $sale1,
            'comp_sale' => $sale2,
            'parent_checkout_sale' => $parentCheckoutSale,
            'comp_checkout_sale' => $compCheckoutSale,
            'carrier_detail' => $carrierDetail,
            'parent_product' => $parentProduct,
            'comp_product' => $compProduct,
            'parent_dispatch_detail' => $parentDispatchDetail,
            'comp_dispatch_detail' => $compDispatchDetail,
            'comp_bundle_item' => $sbi,
        ];
    }

    private function createPosTransaction(array $attributes): PosTransaction
    {
        static $txSeq = 500;
        $num = $txSeq++;
        return PosTransaction::create(array_merge([
            'setting_id' => $this->settingA->id,
            'transaction_number' => "TXN-EXEC-{$num}",
            'code' => "TXN-EXEC-{$num}",
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->sessionA->id,
        ], $attributes));
    }

    private function createPosTransactionLine(array $attributes): PosTransactionLine
    {
        static $ln = 500;
        $name = $attributes['product_name'] ?? 'Product Line';
        unset($attributes['product_name']);
        return PosTransactionLine::create(array_merge([
            'line_no' => $ln++,
            'product_name_snapshot' => $name,
            'product_code_snapshot' => 'SKU',
        ], $attributes));
    }

    /** @test */
    public function whole_bundle_approval_blocks_and_rejects_when_component_allocation_changes_after_submission(): void
    {
        $this->actingAsInSetting($this->user, $this->settingA);
        $fixture = $this->createIncidentSplitOwnerFixture(['is_paid' => false]);

        $snapshot = $this->snapshotService->build($fixture['pos_transaction']->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $fixture['pos_transaction']->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [[
                'sale_detail_id' => $fixture['parent_detail']->id,
                'sale_id' => $fixture['parent_sale']->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'quantity' => 1,
                'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            ]],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn);
        $this->assertFalse($this->planner->plan($posReturn->fresh())['is_blocked']);

        // Change only the component allocation (Rp260,000 -> Rp230,000)
        $fixture['comp_bundle_item']->update(['price' => 230000, 'sub_total' => 230000]);

        $plan = $this->planner->plan($posReturn->fresh());
        $this->assertTrue($plan['is_blocked'], json_encode($plan['blockers']));
        $this->assertContains('snapshot_drift', collect($plan['blockers'])->pluck('code')->all());

        $forgedPlan = array_merge($plan, ['is_blocked' => false, 'blockers' => []]);
        try {
            $this->lifecycleService->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $forgedPlan);
            $this->fail('Expected RuntimeException for component allocation drift was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nilai moneter pada dokumen sumber telah berubah', $e->getMessage());
        }

        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->fresh()->status);
    }
}

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
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;

class POSReturnApprovalPreviewBundleScopeTest extends PosTransactionFeatureTestCase
{
    protected PosReturnApprovalPreviewPlannerService $planner;
    protected PosReturnApprovalPlanPersistenceService $persistenceService;
    protected PosReturnSubmissionService $submissionService;
    protected PosReturnSnapshotService $snapshotService;
    protected $user;
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = app(PosReturnApprovalPreviewPlannerService::class);
        $this->persistenceService = app(PosReturnApprovalPlanPersistenceService::class);
        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);

        $this->setting = $this->createSetting('Preview Scope Main Setting');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'Preview Clerk', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.edit',
            'pos.returns.approve',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_projects_bundle_scope_effects_for_incident_fixture_with_whole_bundle(): void
    {
        $fixture = $this->createIncidentSplitOwnerFixture();

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

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], 'Plan should not be blocked: ' . json_encode($plan['blockers']));
        $this->assertSame('ready', $plan['status']);

        // Incident numbers: Camera 630,000 + MicroSD 260,000 = 890,000
        $this->assertEquals(890000.0, (float) $plan['commercial_reduction']);
        $this->assertEquals(0.0, (float) $plan['actual_refund']);
        $this->assertEquals(890000.0, (float) $plan['debt_reduction']);
        $this->assertEquals(0.0, (float) $plan['retained_charges']);

        $this->assertCount(2, $plan['groups'], 'Split-owner whole bundle must have 2 owner groups');

        $cameraGroup = collect($plan['groups'])->firstWhere('source_sale.id', $fixture['parent_sale']->id);
        $this->assertNotNull($cameraGroup);
        $this->assertEquals(630000.0, (float) $cameraGroup['planned_header']['commercial_reduction']);
        $this->assertEquals(630000.0, (float) $cameraGroup['planned_header']['debt_reduction']);
        $this->assertEquals(0.0, (float) $cameraGroup['planned_header']['actual_refund']);

        $sdGroup = collect($plan['groups'])->firstWhere('source_sale.id', $fixture['comp_sale']->id);
        $this->assertNotNull($sdGroup);
        $this->assertEquals(260000.0, (float) $sdGroup['planned_header']['commercial_reduction']);
        $this->assertEquals(260000.0, (float) $sdGroup['planned_header']['debt_reduction']);
        $this->assertEquals(0.0, (float) $sdGroup['planned_header']['actual_refund']);

        $this->assertNotEmpty($plan['included_components']);
        $this->assertEmpty($plan['retained_components']);
        $this->assertSame((int) $fixture['comp_product']->id, (int) $plan['included_components'][0]['product_id']);
        $this->assertEquals(260000.0, (float) $plan['included_components'][0]['allocated_amount']);
    }

    /** @test */
    public function it_projects_bundle_scope_effects_for_incident_fixture_with_parent_only(): void
    {
        $fixture = $this->createIncidentSplitOwnerFixture();

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

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], 'Plan should not be blocked: ' . json_encode($plan['blockers']));
        $this->assertSame('ready', $plan['status']);

        // Parent only: Camera 630,000 reversed, MicroSD 260,000 retained
        $this->assertEquals(630000.0, (float) $plan['commercial_reduction']);
        $this->assertEquals(0.0, (float) $plan['actual_refund']);
        $this->assertEquals(630000.0, (float) $plan['debt_reduction']);
        $this->assertEquals(260000.0, (float) $plan['retained_charges']);

        $this->assertCount(1, $plan['groups'], 'Parent-only return must have only the parent owner group');
        $parentGroup = $plan['groups'][0];
        $this->assertEquals(630000.0, (float) $parentGroup['planned_header']['commercial_reduction']);
        $this->assertEquals(630000.0, (float) $parentGroup['planned_header']['debt_reduction']);
        $this->assertEquals(260000.0, (float) $parentGroup['planned_header']['retained_charges']);

        $this->assertEmpty($plan['included_components']);
        $this->assertNotEmpty($plan['retained_components']);
        $this->assertSame((int) $fixture['comp_product']->id, (int) $plan['retained_components'][0]['product_id']);
        $this->assertEquals(260000.0, (float) $plan['retained_components'][0]['allocated_amount']);
    }

    /** @test */
    public function it_projects_actual_refund_for_paid_bundle_sales(): void
    {
        $fixture = $this->createIncidentSplitOwnerFixture(isPaid: true);

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

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked']);
        $this->assertEquals(890000.0, (float) $plan['commercial_reduction']);
        $this->assertEquals(890000.0, (float) $plan['actual_refund']);
        $this->assertEquals(0.0, (float) $plan['debt_reduction']);
        $this->assertEquals(0.0, (float) $plan['retained_charges']);
    }

    /** @test */
    public function it_blocks_legacy_pending_draft_lacking_scope(): void
    {
        $fixture = $this->createIncidentSplitOwnerFixture();

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

        // Simulate legacy unreviewed scope by clearing bundle_return_scope
        $posReturn->lines()->where('sale_detail_id', $fixture['parent_detail']->id)->update([
            'bundle_return_scope' => null,
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $this->assertSame('blocked', $plan['status']);

        $blockerCodes = collect($plan['blockers'])->pluck('code')->all();
        $this->assertContains('bundle_scope_missing', $blockerCodes);
    }

    /** @test */
    public function it_blocks_invalid_scope_value(): void
    {
        $fixture = $this->createIncidentSplitOwnerFixture();

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

        // Tamper scope with invalid value
        $posReturn->lines()->where('sale_detail_id', $fixture['parent_detail']->id)->update([
            'bundle_return_scope' => 'invalid_scope_value',
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $blockerCodes = collect($plan['blockers'])->pluck('code')->all();
        $this->assertContains('bundle_scope_invalid', $blockerCodes);
    }

    /** @test */
    public function it_blocks_whole_bundle_when_component_is_missing(): void
    {
        $fixture = $this->createIncidentSplitOwnerFixture();

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

        // Delete the synthesized component line to simulate missing component
        $posReturn->lines()->where('product_id', $fixture['comp_product']->id)->delete();

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $blockerCodes = collect($plan['blockers'])->pluck('code')->all();
        $this->assertContains('bundle_component_missing', $blockerCodes);
    }

    /** @test */
    public function it_blocks_parent_only_when_component_line_is_extraneous(): void
    {
        $fixture = $this->createIncidentSplitOwnerFixture();

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

        // Change the parent line to parent_only while leaving component line intact
        $posReturn->lines()->where('sale_detail_id', $fixture['parent_detail']->id)->update([
            'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $blockerCodes = collect($plan['blockers'])->pluck('code')->all();
        $this->assertContains('bundle_component_extraneous', $blockerCodes);
    }

    /** @test */
    public function it_guarantees_preview_is_read_only(): void
    {
        $fixture = $this->createIncidentSplitOwnerFixture();

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

        $saleReturnCountBefore = SaleReturn::count();
        $saleReturnDetailCountBefore = SaleReturnDetail::count();
        $parentSaleDueBefore = $fixture['parent_sale']->fresh()->due_amount;
        $compSaleDueBefore = $fixture['comp_sale']->fresh()->due_amount;

        // Run preview planning
        $plan = $this->planner->plan($posReturn->fresh());
        $this->assertFalse($plan['is_blocked']);

        // Assert zero database mutation
        $this->assertSame($saleReturnCountBefore, SaleReturn::count());
        $this->assertSame($saleReturnDetailCountBefore, SaleReturnDetail::count());
        $this->assertEquals($parentSaleDueBefore, $fixture['parent_sale']->fresh()->due_amount);
        $this->assertEquals($compSaleDueBefore, $fixture['comp_sale']->fresh()->due_amount);
    }

    /** @test */
    public function it_carries_scope_into_persisted_execution_context(): void
    {
        $fixture = $this->createIncidentSplitOwnerFixture();

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

        $plan = $this->planner->plan($posReturn->fresh());
        $this->assertFalse($plan['is_blocked']);

        $saleReturns = $this->persistenceService->synchronize($posReturn->fresh(), $plan);
        $this->assertNotEmpty($saleReturns);

        $parentSaleReturn = $saleReturns->first();
        $detail = $parentSaleReturn->saleReturnDetails->first();
        $this->assertNotNull($detail);
        $this->assertSame(
            PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY,
            data_get($detail->execution_context, 'bundle_return_scope'),
            'execution_context must persist bundle_return_scope'
        );
    }

    /**
     * Build the exact incident-shaped fixture:
     * Parent: Camera (Store 1, allocated 630,000)
     * Component: MicroSD (Store 2, allocated 260,000)
     * Customer bundle price: 890,000
     */
    protected function createIncidentSplitOwnerFixture(bool $isPaid = false): array
    {
        $setting1 = $this->setting;
        $loc1 = $this->location;

        $setting2 = $this->createSetting('Incident Component Setting');
        $loc2 = Location::create(['name' => 'Store 2 Loc', 'setting_id' => $setting2->id]);

        $category = Category::create([
            'category_code' => 'ELEC',
            'category_name' => 'Electronics',
            'created_by' => $this->user->id,
            'setting_id' => $setting1->id,
        ]);

        $parentProduct = Product::create([
            'setting_id' => $setting1->id,
            'category_id' => $category->id,
            'product_name' => 'Camera Kit',
            'product_code' => 'CAM-KIT-INC',
            'product_price' => 890000,
            'product_unit' => 1,
            'product_cost' => 500000,
            'serial_number_required' => false,
        ]);

        $compProduct = Product::create([
            'setting_id' => $setting2->id,
            'category_id' => $category->id,
            'product_name' => 'MicroSD 64GB',
            'product_code' => 'MSD-64-INC',
            'product_price' => 260000,
            'product_unit' => 1,
            'product_cost' => 150000,
            'serial_number_required' => false,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parentProduct->id,
            'name' => 'Camera Kit with MicroSD',
            'setting_id' => $setting1->id,
        ]);

        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compProduct->id,
            'quantity' => 1,
        ]);

        $sale1 = Sale::create([
            'date' => now(),
            'customer_id' => null,
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
            'setting_id' => $setting1->id,
            'reference' => 'SO-CAM-' . uniqid(),
        ]);

        if ($isPaid) {
            \Modules\Sale\Entities\SalePayment::create([
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
        $dispatchDetail1 = DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale1->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $loc1->id,
        ]);

        $sale2 = Sale::create([
            'date' => now(),
            'customer_id' => null,
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
            'setting_id' => $setting2->id,
            'reference' => 'SO-MSD-' . uniqid(),
        ]);

        if ($isPaid) {
            \Modules\Sale\Entities\SalePayment::create([
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
            'bundle_item_id' => $bundleItem->id,
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
        $dispatchDetail2 = DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'product_id' => $compProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $loc2->id,
            'bundle_id' => $bundle->id,
        ]);

        $posTxn = $this->createPosTransaction([
            'transaction_number' => 'TNC-TXN-2026-10-0638-TEST',
            'code' => 'TNC-TXN-2026-10-0638-TEST',
            'total_amount' => 890000,
            'setting_id' => $setting1->id,
        ]);

        $ptl = $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'qty' => 1,
            'unit_price' => 890000,
            'subtotal' => 890000,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->bundle_name,
                'bundle_items' => [
                    [
                        'product_id' => $compProduct->id,
                        'name' => $compProduct->product_name,
                        'quantity' => 1,
                        'bundle_item_id' => $bundleItem->id,
                    ],
                ],
            ],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $setting1->id,
            'pos_transaction_id' => $posTxn->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 890000,
            'receipt_number' => 'RCP-INC-TEST',
            'idempotency_key' => 'IDEM-INC-TEST-' . uniqid(),
            'payload_hash' => 'HASH-INC-TEST-' . uniqid(),
        ]);
        $posTxn->update(['completed_checkout_id' => $checkout->id]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale1->id,
            'source_setting_id' => $setting1->id,
            'source_location_id' => $loc1->id,
            'grand_total' => 630000,
            'split_key' => 'SPLIT-CAM-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale2->id,
            'source_setting_id' => $setting2->id,
            'source_location_id' => $loc2->id,
            'grand_total' => 260000,
            'split_key' => 'SPLIT-MSD-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        return [
            'pos_transaction' => $posTxn,
            'parent_detail' => $parentDetail,
            'parent_sale' => $sale1,
            'comp_sale' => $sale2,
            'carrier_detail' => $carrierDetail,
            'parent_product' => $parentProduct,
            'comp_product' => $compProduct,
        ];
    }

    private function createPosTransaction(array $attributes): PosTransaction
    {
        static $txSeq = 100;
        $num = $txSeq++;
        return PosTransaction::create(array_merge([
            'setting_id' => $this->setting->id,
            'transaction_number' => "TXN-{$num}",
            'code' => "TXN-{$num}",
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ], $attributes));
    }

    private function createPosTransactionLine(array $attributes): PosTransactionLine
    {
        static $ln = 100;
        $name = $attributes['product_name'] ?? 'Product Line';
        unset($attributes['product_name']);
        return PosTransactionLine::create(array_merge([
            'line_no' => $ln++,
            'product_name_snapshot' => $name,
            'product_code_snapshot' => 'SKU',
        ], $attributes));
    }
}

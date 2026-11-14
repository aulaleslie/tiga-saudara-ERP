<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Entities\PosTransactionLineSerial;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Spatie\Permission\Models\Permission;

class POSReturnBundleParentCarrierCashReturnTest extends PosTransactionFeatureTestCase
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

        $this->setting = $this->createSetting('Parent Carrier Cash Return Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        foreach (['pos.access', 'pos.returns.create', 'pos.returns.edit', 'pos.returns.approve'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = $this->createUserForSetting($this->setting, 'Parent Carrier Return Clerk', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.edit',
            'pos.returns.approve',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /**
     * Production shape matching TPI-TXN-2026-11-0170:
     * - 1 serial-tracked parent product (allocated 7,450,000, bundle price 7,600,000)
     * - 3 non-serial stock-managed components (20,000 / 30,000 / 100,000)
     * - Only 1 SaleDetails row (the parent row); no sibling component SaleDetails rows
     * - 3 SaleBundleItem rows attached directly to parent SaleDetails.id
     * - 4 DispatchDetail rows (1 parent + 3 components), component dispatches share parent sale_detail_id
     */
    protected function buildParentCarrierBundleFixture(string $suffix = ''): array
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Laptop Bundle Parent' . $suffix,
            'product_code' => 'LAPTOP-PAR' . $suffix,
            'serial_number_required' => true,
            'sale_price' => 7600000,
            'stock_qty' => 10,
        ]);

        $comp1 = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Mouse Bundle Comp' . $suffix,
            'product_code' => 'COMP-1' . $suffix,
            'serial_number_required' => false,
            'sale_price' => 20000,
            'stock_qty' => 10,
        ]);
        $comp2 = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Backpack Bundle Comp' . $suffix,
            'product_code' => 'COMP-2' . $suffix,
            'serial_number_required' => false,
            'sale_price' => 30000,
            'stock_qty' => 10,
        ]);
        $comp3 = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Headset Bundle Comp' . $suffix,
            'product_code' => 'COMP-3' . $suffix,
            'serial_number_required' => false,
            'sale_price' => 100000,
            'stock_qty' => 10,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Laptop Value Bundle' . $suffix,
            'is_active' => true,
        ]);

        $bi1 = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp1->id, 'quantity' => 1, 'informational_item_price' => 20000]);
        $bi2 = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp2->id, 'quantity' => 1, 'informational_item_price' => 30000]);
        $bi3 = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp3->id, 'quantity' => 1, 'informational_item_price' => 100000]);

        $parentSerial = $this->createSerialNumber($parent, $this->location, 'NXDDDSN001531033386L01' . $suffix);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TPI-TXN-2026-11-0170-TEST' . $suffix,
            'status' => PosTransaction::STATUS_COMPLETED,
            'total_amount' => 7600000,
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
            'grand_total' => 7600000,
            'receipt_number' => 'RCP-0170' . $suffix,
            'idempotency_key' => 'IDEM-0170' . $suffix,
            'payload_hash' => 'HASH-0170' . $suffix,
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 7600000,
            'paid_amount' => 7600000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-0170' . $suffix,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 7600000,
            'date' => now()->toDateString(),
            'payment_method' => 'Cash',
            'reference' => 'PAY-0170' . $suffix,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 7600000,
            'split_key' => 'SPLIT-0170' . $suffix,
            'tax_bucket' => 'NON_TAX',
        ]);

        // Only ONE SaleDetails row (parent row carries sub_total = 7,600,000 and unit_price = 7,450,000)
        $parentDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'quantity' => 1,
            'price' => 7450000,
            'unit_price' => 7450000,
            'sub_total' => 7600000,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$parentSerial->id],
        ]);

        // All 3 SaleBundleItem rows attach directly to $parentDetail->id
        $sbi1 = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bi1->id,
            'product_id' => $comp1->id,
            'name' => $comp1->product_name,
            'quantity' => 1,
            'price' => 20000,
            'sub_total' => 20000,
        ]);
        $sbi2 = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bi2->id,
            'product_id' => $comp2->id,
            'name' => $comp2->product_name,
            'quantity' => 1,
            'price' => 30000,
            'sub_total' => 30000,
        ]);
        $sbi3 = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bi3->id,
            'product_id' => $comp3->id,
            'name' => $comp3->product_name,
            'quantity' => 1,
            'price' => 100000,
            'sub_total' => 100000,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'status' => Dispatch::STATUS_APPROVED,
            'dispatch_date' => now(),
        ]);

        $parentDispatch = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $parent->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$parentSerial->serial_number]),
        ]);

        $parentSerial->update([
            'dispatch_detail_id' => $parentDispatch->id,
            'sale_id' => $sale->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $comp1Dispatch = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $comp1->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        $comp2Dispatch = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $comp2->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        $comp3Dispatch = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $comp3->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $parent->id,
            'product_name_snapshot' => $parent->product_name,
            'product_code_snapshot' => $parent->product_code,
            'qty' => 1,
            'unit_price' => 7600000,
            'subtotal' => 7600000,
            'line_no' => 1,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->name,
                'bundle_items' => [
                    ['bundle_item_id' => $bi1->id, 'product_id' => $comp1->id, 'name' => $comp1->product_name, 'quantity' => 1, 'allocated_price' => 20000],
                    ['bundle_item_id' => $bi2->id, 'product_id' => $comp2->id, 'name' => $comp2->product_name, 'quantity' => 1, 'allocated_price' => 30000],
                    ['bundle_item_id' => $bi3->id, 'product_id' => $comp3->id, 'name' => $comp3->product_name, 'quantity' => 1, 'allocated_price' => 100000],
                ],
            ],
        ]);

        PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => $parentSerial->serial_number,
        ]);

        return [
            'transaction' => $transaction,
            'sale' => $sale,
            'parent_detail' => $parentDetail,
            'parent_product' => $parent,
            'parent_serial' => $parentSerial,
            'parent_dispatch' => $parentDispatch,
            'components' => [$comp1, $comp2, $comp3],
            'sale_bundle_items' => [$sbi1, $sbi2, $sbi3],
            'component_dispatches' => [$comp1Dispatch, $comp2Dispatch, $comp3Dispatch],
            'bundle' => $bundle,
            'ptl' => $ptl,
        ];
    }

    /** @test */
    public function it_saves_whole_bundle_cash_return_when_components_use_parent_as_carrier(): void
    {
        $fixture = $this->buildParentCarrierBundleFixture('-SAVE');
        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['parent_detail']->id,
                    'sale_id' => $fixture['sale']->id,
                    'pos_transaction_line_id' => $fixture['ptl']->id,
                    'dispatch_detail_id' => $fixture['parent_dispatch']->id,
                    'returned_serial_id' => $fixture['parent_serial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);

        $lines = $posReturn->lines()->get();
        $this->assertCount(4, $lines);
        $this->assertEquals(7600000.0, (float) $posReturn->total_amount);

        $parentLine = $lines->first(fn ($line) => ! PosReturnLine::isComponentLine($line));
        $this->assertNotNull($parentLine);
        $this->assertSame($fixture['parent_product']->id, (int) $parentLine->product_id);
        $this->assertSame($fixture['parent_dispatch']->id, (int) $parentLine->dispatch_detail_id);
        $this->assertSame(PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE, $parentLine->bundle_return_scope);
        $this->assertEquals(7600000.0, (float) $parentLine->unit_price);
        $this->assertEquals(7600000.0, (float) $parentLine->line_total);
        $this->assertEquals(7600000.0, (float) $parentLine->expected_cash_amount);

        $componentLines = $lines->filter(fn ($line) => PosReturnLine::isComponentLine($line));
        $this->assertCount(3, $componentLines);

        $expectedAllocatedPrices = [20000.0, 30000.0, 100000.0];
        foreach ($fixture['components'] as $index => $componentProduct) {
            $componentLine = $componentLines->firstWhere('product_id', $componentProduct->id);
            $this->assertNotNull($componentLine, "Missing synthesized line for component {$componentProduct->id}");
            $this->assertSame($fixture['parent_detail']->id, (int) $componentLine->sale_detail_id);
            $this->assertSame($fixture['parent_detail']->id, (int) $componentLine->bundle_parent_sale_detail_id);
            $this->assertSame($fixture['component_dispatches'][$index]->id, (int) $componentLine->dispatch_detail_id);
            $this->assertNull($componentLine->bundle_return_scope);
            $this->assertEquals(1.0, (float) $componentLine->quantity);
            $this->assertEquals(1.0, (float) $componentLine->component_quantity_per_bundle);
            $this->assertEquals(0.0, (float) $componentLine->unit_price);
            $this->assertEquals(0.0, (float) $componentLine->line_total);
            $this->assertEquals(0.0, (float) $componentLine->expected_cash_amount);
            $this->assertEquals($expectedAllocatedPrices[$index], (float) data_get($componentLine->line_meta, 'allocated_price'));
            $this->assertSame($fixture['sale_bundle_items'][$index]->id, (int) data_get($componentLine->line_meta, 'sale_bundle_item_id'));
            $this->assertNull(data_get($componentLine->line_meta, 'bundle_trace'));
        }

        // Task 2.3: Resubmitting/submitting the saved draft for approval must preserve
        // all 1 parent + 3 component lines cleanly.
        $submitted = $this->submissionService->submitDraftForApproval($posReturn->fresh('lines'));
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $submitted->status);
        $resubmittedLines = $submitted->lines()->get();
        $this->assertCount(4, $resubmittedLines);
        $this->assertCount(3, $resubmittedLines->filter(fn ($line) => PosReturnLine::isComponentLine($line)));
    }

    /** @test */
    public function it_rejects_second_whole_bundle_cash_return_for_same_serial(): void
    {
        $fixture = $this->buildParentCarrierBundleFixture('-DUP');
        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $payload = [
            'pos_transaction_id' => $fixture['transaction']->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['parent_detail']->id,
                    'sale_id' => $fixture['sale']->id,
                    'pos_transaction_line_id' => $fixture['ptl']->id,
                    'dispatch_detail_id' => $fixture['parent_dispatch']->id,
                    'returned_serial_id' => $fixture['parent_serial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ];

        $firstReturn = $this->submissionService->store($payload);
        $this->submissionService->submitDraftForApproval($firstReturn->fresh('lines'));

        $this->expectException(\Exception::class);
        $this->submissionService->store($payload);
    }

    /** @test */
    public function it_approves_and_completes_whole_bundle_cash_return_when_components_use_parent_as_carrier(): void
    {
        $fixture = $this->buildParentCarrierBundleFixture('-APPROVE');
        $snapshot = $this->snapshotService->build($fixture['transaction']->id);

        $draft = $this->submissionService->store([
            'pos_transaction_id' => $fixture['transaction']->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $fixture['parent_detail']->id,
                    'sale_id' => $fixture['sale']->id,
                    'pos_transaction_line_id' => $fixture['ptl']->id,
                    'dispatch_detail_id' => $fixture['parent_dispatch']->id,
                    'returned_serial_id' => $fixture['parent_serial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                    'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
                ],
            ],
        ]);

        $submitted = $this->submissionService->submitDraftForApproval($draft->fresh('lines'));
        $plan = $this->planner->plan($submitted->fresh('lines'));
        $this->assertEmpty($plan['blockers'] ?? [], json_encode($plan['blockers'] ?? []));

        $allProducts = array_merge([$fixture['parent_product']], $fixture['components']);
        $stockBefore = [];
        foreach ($allProducts as $product) {
            $stockBefore[$product->id] = (float) (ProductStock::query()
                ->where('product_id', $product->id)
                ->where('location_id', $this->location->id)
                ->value('quantity') ?? 0);
        }

        $this->lifecycleService->executeApprovalFromPreview($submitted->id, PosReturn::OPTION_CASH_RETURN, $plan);

        $completed = $submitted->fresh(['lines', 'saleReturns.saleReturnDetails']);
        $this->assertSame(PosReturn::STATUS_COMPLETED, $completed->status);
        $this->assertSame(PosReturn::APPROVAL_STATUS_APPROVED, $completed->approval_status);

        foreach ($allProducts as $product) {
            $stockAfter = (float) (ProductStock::query()
                ->where('product_id', $product->id)
                ->where('location_id', $this->location->id)
                ->value('quantity') ?? 0);
            $this->assertEquals(
                $stockBefore[$product->id] + 1.0,
                $stockAfter,
                "Expected +1 stock restored at source location for product {$product->id}"
            );
        }

        $parentSerial = $fixture['parent_serial']->fresh();
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $parentSerial->status);
        $this->assertNull($parentSerial->dispatch_detail_id);

        $allDispatches = array_merge([$fixture['parent_dispatch']], $fixture['component_dispatches']);
        foreach ($allDispatches as $dispatchDetail) {
            $this->assertSame(0, (int) $dispatchDetail->fresh()->dispatched_quantity);
        }

        $this->assertSame(0, (int) $fixture['parent_detail']->fresh()->quantity);
        foreach ($fixture['sale_bundle_items'] as $saleBundleItem) {
            $this->assertSame(0, (int) $saleBundleItem->fresh()->quantity);
        }

        $totalRefundPayments = (float) SaleReturnPayment::query()
            ->whereIn('sale_return_id', $completed->saleReturns->pluck('id'))
            ->sum('amount');
        $this->assertEquals(7600000.0, $totalRefundPayments);
    }
}

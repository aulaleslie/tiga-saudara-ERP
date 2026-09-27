<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnApprovalPlanPersistenceService;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnSplitOwnerMappingTest extends PosTransactionFeatureTestCase
{
    protected $setting;

    protected $secondSetting;

    protected $location;

    protected $secondLocation;

    protected $terminal;

    protected $session;

    protected $user;

    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->setting = $this->createSetting('POS Return Split Owner Test');
        $this->secondSetting = $this->createSetting('POS Return Split Owner Test 2');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        [, $this->secondLocation] = $this->createTerminalWithLocation($this->secondSetting);

        Permission::findOrCreate('pos.returns.create', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Split Owner Clerk', [
            'pos.access',
            'pos.returns.create',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_preserves_original_sale_owner_location_tax_and_dispatch_context_for_split_owner_transactions(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SPLIT-' . uniqid(),
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
            'receipt_number' => 'RCP-SPLIT-' . uniqid(),
            'idempotency_key' => 'IDEM-SPLIT-' . uniqid(),
            'payload_hash' => 'HASH-SPLIT-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        [$firstSale, $firstDetail, $firstDispatchDetail] = $this->createOwnerMappedSale($checkout, $this->setting->id, $this->location->id, 'A');
        [$secondSale, $secondDetail, $secondDispatchDetail] = $this->createOwnerMappedSale($checkout, $this->secondSetting->id, $this->secondLocation->id, 'B');

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $firstDetail->id,
                    'quantity' => 1,
                    'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
                [
                    'sale_detail_id' => $secondDetail->id,
                    'quantity' => 1,
                    'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);

        $plan = app(PosReturnApprovalPreviewPlannerService::class)->plan($posReturn->fresh());
        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($posReturn->fresh(), $plan);

        $posReturn->load(['lines', 'saleReturns.saleReturnDetails']);

        $this->assertCount(2, $posReturn->lines);
        $this->assertCount(2, $posReturn->saleReturns);

        $firstLine = $posReturn->lines->firstWhere('sale_id', $firstSale->id);
        $secondLine = $posReturn->lines->firstWhere('sale_id', $secondSale->id);
        $firstSaleReturnDetail = $posReturn->saleReturns
            ->firstWhere('sale_id', $firstSale->id)
            ?->saleReturnDetails
            ->firstWhere('sale_detail_id', $firstDetail->id);
        $secondSaleReturnDetail = $posReturn->saleReturns
            ->firstWhere('sale_id', $secondSale->id)
            ?->saleReturnDetails
            ->firstWhere('sale_detail_id', $secondDetail->id);

        $this->assertSame($this->setting->id, $firstLine->source_setting_id);
        $this->assertSame($this->location->id, $firstLine->source_location_id);
        $this->assertSame($firstDetail->tax_id, $firstLine->tax_id);
        $this->assertSame($firstDispatchDetail->id, $firstSaleReturnDetail?->dispatch_detail_id);

        $this->assertSame($this->secondSetting->id, $secondLine->source_setting_id);
        $this->assertSame($this->secondLocation->id, $secondLine->source_location_id);
        $this->assertSame($secondDetail->tax_id, $secondLine->tax_id);
        $this->assertSame($secondDispatchDetail->id, $secondSaleReturnDetail?->dispatch_detail_id);

        $this->assertTrue($posReturn->saleReturns->contains(fn ($saleReturn) => (int) $saleReturn->sale_id === (int) $firstSale->id && (int) $saleReturn->location_id === (int) $this->location->id));
        $this->assertTrue($posReturn->saleReturns->contains(fn ($saleReturn) => (int) $saleReturn->sale_id === (int) $secondSale->id && (int) $saleReturn->location_id === (int) $this->secondLocation->id));
    }

    /**
     * Real production defect (Sequence 10, transaction TPI-TXN-2026-08-0024):
     * a split checkout posts the bundle PARENT under one owner's Sale (e.g.
     * PERDANA) and a serialized bundle COMPONENT under a DIFFERENT owner's
     * Sale (e.g. WHITE KNIGHT) via the shared PosTransactionLine's
     * line_meta.bundle_items. PosReturnSnapshotService::findComponentAllocation()
     * previously searched only the PARENT's own checkoutSale->sale for the
     * component's zero-priced SaleDetails sibling row — since the component
     * actually lives under a DIFFERENT Sale entirely, this always resolved to
     * null: sale_detail_id/dispatch_detail_id/returned serial all came back
     * empty, returnable_quantity was 0, and PosReturnCreateForm's
     * `if (empty($componentEntry['sale_detail_id'])) continue;` guard (line
     * ~157) silently skipped initializing the component row — the UI rendered
     * "Tersedia: 0" and no serial control at all, even though the component
     * had real, returnable stock under its own (correct) owner.
     *
     * @test
     */
    public function it_resolves_a_serialized_bundle_component_posted_under_a_different_owner_sale_than_its_parent(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SPLITBUNDLE-' . uniqid(),
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
            'grand_total' => 5000000,
            'receipt_number' => 'RCP-SPLITBUNDLE-' . uniqid(),
            'idempotency_key' => 'IDEM-SPLITBUNDLE-' . uniqid(),
            'payload_hash' => 'HASH-SPLITBUNDLE-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        // Parent (AXIOO-like) product, owner Setting A (PERDANA equivalent).
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-SPLITBUNDLE-PARENT-' . uniqid(),
            'sale_price' => 5000000,
        ]);
        $parentSale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 5000000,
            'paid_amount' => 5000000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SPLITBUNDLE-PARENT-' . uniqid(),
        ]);
        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $parentSale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 5000000,
            'split_key' => 'SPLIT-PARENT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);
        $parentDetail = SaleDetails::query()->create([
            'sale_id' => $parentSale->id,
            'product_id' => $parentProduct->id,
            'quantity' => 1,
            'price' => 5000000,
            'unit_price' => 5000000,
            'sub_total' => 5000000,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $parentDispatch = Dispatch::query()->create(['sale_id' => $parentSale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatch->id,
            'sale_id' => $parentSale->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        // Serialized component (AIO-like) product, DIFFERENT owner Setting B
        // (WHITE KNIGHT equivalent) — its own separate Sale, entirely
        // distinct from the parent's.
        $componentProduct = $this->createStockedProduct($this->secondSetting, $this->secondLocation, [
            'product_code' => 'PRD-SPLITBUNDLE-COMP-' . uniqid(),
            'serial_number_required' => true,
        ]);

        // Catalog bundle definition: AXIOO (parent) -> AIO (component).
        $bundle = \Modules\Product\Entities\ProductBundle::query()->create([
            'parent_product_id' => $parentProduct->id,
            'setting_id' => $this->setting->id,
            'name' => 'AXIOO Bundle',
            'is_active' => true,
        ]);
        $bundleItemDef = \Modules\Product\Entities\ProductBundleItem::query()->create([
            'bundle_id' => $bundle->id,
            'product_id' => $componentProduct->id,
            'quantity' => 1,
        ]);

        $componentSale = Sale::query()->create([
            'setting_id' => $this->secondSetting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SPLITBUNDLE-COMP-' . uniqid(),
        ]);
        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $componentSale->id,
            'source_setting_id' => $this->secondSetting->id,
            'source_location_id' => $this->secondLocation->id,
            'grand_total' => 0,
            'split_key' => 'SPLIT-COMP-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        // The REAL split-owner persistence shape (InlinePosCheckoutPostingAdapter):
        // the component owner's Sale does NOT get a standalone SaleDetails row
        // keyed by the component's own product_id. It gets a zero-quantity
        // "carrier" row keyed by the PARENT's product_id (the owner group's
        // own residual/audit line, since this owner fulfilled none of the
        // parent's own stock/serial — only the component), and the component
        // is represented ONLY via a SaleBundleItem attached to that carrier row.
        $carrierDetail = SaleDetails::query()->create([
            'sale_id' => $componentSale->id,
            'product_id' => $parentProduct->id,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        SaleBundleItem::query()->create([
            'sale_id' => $componentSale->id,
            'sale_detail_id' => $carrierDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItemDef->id,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        $componentDispatch = Dispatch::query()->create(['sale_id' => $componentSale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $componentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $componentDispatch->id,
            'sale_id' => $componentSale->id,
            // DispatchDetail.sale_detail_id points at the CARRIER row's id
            // (confirmed: InlinePosCheckoutPostingAdapter::recordStockMovement()
            // always persists the group line's own SaleDetails id here,
            // regardless of whether this specific DispatchDetail is for the
            // parent's own stock or a bundle child's).
            'sale_detail_id' => $carrierDetail->id,
            'product_id' => $componentProduct->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->secondLocation->id,
        ]);

        $returnedSerial = $this->createSerialNumber($componentProduct, $this->secondLocation, 'SN-SPLITBUNDLE-' . uniqid());
        $returnedSerial->update(['dispatch_detail_id' => $componentDispatchDetail->id, 'status' => 'SOLD']);

        // The shared POS transaction line carries the bundle_items metadata
        // linking parent -> component, exactly as real split-owner checkout
        // posting produces.
        $ptl = \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $parentProduct->id,
            'product_name_snapshot' => $parentProduct->product_name,
            'product_code_snapshot' => $parentProduct->product_code,
            'qty' => 1,
            'unit_price' => 5000000,
            'line_no' => 1,
            'line_meta' => [
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    ['product_id' => $componentProduct->id, 'name' => $componentProduct->product_name, 'quantity' => 1, 'bundle_item_id' => $bundleItemDef->id],
                ],
            ],
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => $returnedSerial->serial_number,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $parentLine = collect($snapshot['lines'])->firstWhere('sale_detail_id', $parentDetail->id);
        $this->assertNotNull($parentLine, 'Bundle parent line missing from snapshot.');

        $componentEntry = collect($parentLine['bundle_items'] ?? [])->first(
            fn (array $item) => (int) ($item['product_id'] ?? 0) === (int) $componentProduct->id
        );

        $this->assertNotNull($componentEntry, 'Component entry missing from bundle_items.');
        // sale_detail_id is the CARRIER row's id (the only SaleDetails
        // identity that exists for this component in the real split-owner
        // shape) — never a hypothetical standalone component-product row.
        $this->assertSame((int) $carrierDetail->id, (int) ($componentEntry['sale_detail_id'] ?? 0));
        $this->assertSame((int) $componentDispatchDetail->id, (int) ($componentEntry['dispatch_detail_id'] ?? 0));
        $this->assertSame($this->secondSetting->id, $componentEntry['source_setting_id'] ?? null);
        $this->assertSame($this->secondLocation->id, $componentEntry['source_location_id'] ?? null);
        $this->assertSame((int) $bundleItemDef->bundle_id, (int) ($componentEntry['bundle_id'] ?? 0));
        $this->assertNotNull($componentEntry['sale_bundle_item_id'] ?? null);
        $this->assertEquals(1.0, (float) ($componentEntry['returnable_quantity'] ?? 0));
        $this->assertContains(
            $returnedSerial->id,
            collect($componentEntry['serial_number_ids'] ?? [])->map(fn ($id) => (int) $id)->all()
        );
        $this->assertContains(
            $returnedSerial->serial_number,
            collect($componentEntry['serial_numbers'] ?? [])->pluck('serial_number')->all()
        );
    }

    protected function createOwnerMappedSale(PosCheckout $checkout, int $sourceSettingId, int $sourceLocationId, string $suffix): array
    {
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-SPLIT-' . $suffix . '-' . uniqid(),
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
            'reference' => 'SO-SPLIT-' . $suffix . '-' . uniqid(),
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
        $detail = SaleDetails::query()->create([
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
            'tax_id' => null,
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

        return [$sale, $detail, $dispatchDetail];
    }
}
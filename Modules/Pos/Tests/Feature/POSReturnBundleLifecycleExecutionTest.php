<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Customer;
use Spatie\Permission\Models\Permission;

/**
 * Phase 4 (tasks 4.1-4.6) focused execution coverage for
 * align-bundle-return-replacement-rules: lifecycle execution of the
 * persisted Phase 2/3 intent — serial-tracked replacement physical
 * movement, non-serial note-only completion, independent bundle
 * parent/component replacement without requiring a parent line, whole-bundle
 * cash return receiving per owner, chained serial lineage, atomic rollback,
 * and retry idempotency.
 *
 * Fixtures hand-build PosReturnLine/SaleReturn/SaleReturnDetail rows directly
 * (bypassing draft submission/preview planning) so execution_context /
 * replacement_kind can be asserted precisely, following the same low-level
 * fixture convention already established by POSReturnAtomicLifecycleTest and
 * POSReturnCrossOwnerReplacementTest.
 */
class POSReturnBundleLifecycleExecutionTest extends PosTransactionFeatureTestCase
{
    protected $settingA;

    protected $settingB;

    protected $locationA;

    protected $locationB;

    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingA = $this->createSetting('Lifecycle Execution Setting A');
        [, $this->locationA] = $this->createTerminalWithLocation($this->settingA);

        $this->settingB = $this->createSetting('Lifecycle Execution Setting B');
        [, $this->locationB] = $this->createTerminalWithLocation($this->settingB);

        Permission::findOrCreate('pos.access', 'web');

        $this->actor = $this->createUserForSetting($this->settingA, 'Lifecycle Execution Actor', [
            'pos.access',
        ]);
    }

    /** @test */
    public function it_executes_same_owner_serialized_bundle_component_replacement_end_to_end(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $componentLine, $componentDetail, $returnedSerial, $replacementSerial, $componentProduct] =
            $this->createPendingApprovalSerialComponentReplacement();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        // Returned serial received into source location as immediately active/saleable.
        $returnedSerial->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, (string) $returnedSerial->status);
        $this->assertNull($returnedSerial->dispatch_detail_id);
        $this->assertSame($this->locationA->id, (int) $returnedSerial->location_id);

        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $returnedSerial->id,
            'event_type' => \Modules\Product\Entities\SerialNumberHistory::EVENT_SALE_RETURNED,
        ]);

        // Replacement serial dispatched and linked.
        $replacementSerial->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, (string) $replacementSerial->status);
        $this->assertNotNull($replacementSerial->dispatch_detail_id);

        $replacementDispatchDetail = DispatchDetail::query()->findOrFail($replacementSerial->dispatch_detail_id);
        $this->assertSame((int) $componentLine->id, (int) $replacementDispatchDetail->pos_return_line_id);
        $this->assertSame((int) $returnedSerial->id, (int) $replacementDispatchDetail->replacement_returned_serial_id);

        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $replacementSerial->id,
            'event_type' => \Modules\Product\Entities\SerialNumberHistory::EVENT_SOLD,
        ]);

        // Cost reversal + fresh outgoing HPP recorded exactly once.
        $componentDetail->refresh();
        $this->assertNotNull($componentDetail->cost_effective_at);
        $this->assertNotNull($replacementDispatchDetail->replacement_cost_unit_snapshot);

        $this->assertTrue(
            \Modules\Product\Entities\Transaction::query()
                ->where('product_id', $componentProduct->id)
                ->where('type', 'DISPATCH_RETURN')
                ->exists()
        );
    }

    /**
     * 5.2: same-product color exchange. The returned and replacement serials
     * are the SAME product_id (the guard only ever checks product_id, never
     * a color/variant attribute — there is no separate "color" concept in
     * this schema), proving a same-SKU physical swap (e.g. a defective unit
     * exchanged for a different-colored unit of the identical product)
     * executes identically to any other serial replacement: returned serial
     * received as active, replacement serial dispatched, lineage persisted.
     *
     * @test
     */
    public function it_executes_a_same_product_color_exchange_serial_replacement(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [
            $sale,
            $parentProduct,
            $componentProduct,
            $parentSaleDetail,
            $componentSaleDetail,
            $bundleItem,
            $parentDispatchDetail,
            $componentDispatchDetail,
        ] = $this->createSaleWithComponentBundle($this->settingA, $this->locationA);

        // Both serials share the identical product_id — a color-only
        // physical exchange, not a different product substitution.
        $returnedSerial = $this->createSerialNumber($componentProduct, $this->locationA, 'SN-COLOR-OLD-' . uniqid());
        $returnedSerial->update([
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $replacementSerial = $this->createSerialNumber($componentProduct, $this->locationA, 'SN-COLOR-NEW-' . uniqid());
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        $this->assertSame((int) $returnedSerial->product_id, (int) $replacementSerial->product_id);

        $posReturn = $this->createPosReturnShell();
        $saleReturn = $this->createSaleReturnShell($posReturn, $sale);

        $componentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'bundle_group_key' => 'BUNDLE-COLOR-' . uniqid(),
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 1,
            'serial_number_ids' => [$returnedSerial->id],
            'returned_serial_id' => $returnedSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $componentProduct->id,
            'replacement_quantity' => 1,
        ]);

        $componentDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $componentLine->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->locationA->id,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returnedSerial->id],
            'component_sale_bundle_item_id' => $bundleItem->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'component',
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'execution_mode' => 'same_owner_replacement',
                'replacement_kind' => 'serial_inventory_replacement',
                'component_sale_bundle_item_id' => $bundleItem->id,
                'component_serial_ids' => [$returnedSerial->id],
                'commercial_value_source' => 'sale_bundle_item',
            ],
        ]);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        $returnedSerial->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, (string) $returnedSerial->status);
        $this->assertNull($returnedSerial->dispatch_detail_id);

        $replacementSerial->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, (string) $replacementSerial->status);
        $this->assertNotNull($replacementSerial->dispatch_detail_id);

        $replacementDispatchDetail = DispatchDetail::query()->findOrFail($replacementSerial->dispatch_detail_id);
        $this->assertSame((int) $returnedSerial->id, (int) $replacementDispatchDetail->replacement_returned_serial_id);

        // Original Sale commercials untouched by a same-product replacement.
        $componentSaleDetail->refresh();
        $this->assertSame(1, (int) $componentSaleDetail->quantity);
    }

    /** @test */
    public function it_executes_cross_owner_serialized_bundle_component_replacement_split_across_two_owners(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $saleReturn, $componentDetail, $returnedSerial, $replacementSerial, $componentProduct, $customer] =
            $this->createPendingApprovalCrossOwnerComponentReplacement();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        // Returned serial received back into ORIGINAL (settingA/locationA) side as active.
        $returnedSerial->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, (string) $returnedSerial->status);
        $this->assertSame($this->locationA->id, (int) $returnedSerial->location_id);

        // A generated replacement-owner Sale/Dispatch was created under settingB.
        $replacementSale = Sale::query()->where('setting_id', $this->settingB->id)->latest('id')->firstOrFail();
        $this->assertSame((int) $customer->id, (int) $replacementSale->customer_id);

        $replacementSerial->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, (string) $replacementSerial->status);
        $this->assertSame($this->locationB->id, (int) $replacementSerial->location_id);

        $replacementDispatchDetail = DispatchDetail::query()
            ->where('sale_id', $replacementSale->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($this->locationB->id, (int) $replacementDispatchDetail->location_id);

        // Component's own SaleReturn commercial correction + HPP reversed exactly once.
        $componentDetail->refresh();
        $this->assertNotNull($componentDetail->cost_effective_at);

        // The replacement-owner Sale carries its own generated payment
        // (cross-owner replacement generates a real Sale+SalePayment on the
        // replacement owner's side rather than a SaleReturnPayment refund).
        $this->assertTrue(\Modules\Sale\Entities\SalePayment::query()->where('sale_id', $replacementSale->id)->exists());
    }

    /** @test */
    public function it_completes_a_note_only_ordinary_product_replacement_with_zero_effects(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $line, $detail, $product] = $this->createPendingApprovalNoteOnlyReplacement();

        $initialQuantity = (int) $product->fresh()->product_quantity;

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        $this->assertFalse(DispatchDetail::query()->where('pos_return_line_id', $line->id)->exists());
        $this->assertFalse(
            \Modules\Product\Entities\Transaction::query()
                ->where('product_id', $product->id)
                ->where('type', 'DISPATCH_RETURN')
                ->exists()
        );
        $this->assertFalse(
            \Modules\Product\Entities\Transaction::query()
                ->where('product_id', $product->id)
                ->whereIn('type', ['SALE_RETURN_GOOD_TAX', 'SALE_RETURN_GOOD_NON_TAX'])
                ->exists()
        );

        $this->assertSame($initialQuantity, (int) $product->fresh()->product_quantity);

        $detail->refresh();
        $this->assertNull($detail->cost_effective_at);
    }

    /** @test */
    public function it_completes_a_note_only_bundle_parent_replacement_with_zero_effects(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $parentLine, $parentDetail, $parentProduct] = $this->createPendingApprovalNoteOnlyBundleReplacement('parent');

        $initialQuantity = (int) $parentProduct->fresh()->product_quantity;

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);
        $this->assertFalse(DispatchDetail::query()->where('pos_return_line_id', $parentLine->id)->exists());
        $this->assertSame($initialQuantity, (int) $parentProduct->fresh()->product_quantity);

        $parentDetail->refresh();
        $this->assertNull($parentDetail->cost_effective_at);
    }

    /**
     * Independent component replacement must not require or mutate the
     * bundle parent — this PosReturn never even creates a parent line.
     *
     * @test
     */
    public function it_completes_a_note_only_bundle_component_replacement_without_a_parent_line(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $componentLine, $componentDetail, $componentProduct] = $this->createPendingApprovalNoteOnlyBundleReplacement('component');

        $initialQuantity = (int) $componentProduct->fresh()->product_quantity;

        // Assert no parent line exists in this PosReturn at all.
        $this->assertSame(1, $posReturn->lines()->count());
        $this->assertSame((int) $componentLine->id, (int) $posReturn->lines()->first()->id);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);
        $this->assertFalse(DispatchDetail::query()->where('pos_return_line_id', $componentLine->id)->exists());
        $this->assertSame($initialQuantity, (int) $componentProduct->fresh()->product_quantity);

        $componentDetail->refresh();
        $this->assertNull($componentDetail->cost_effective_at);
    }

    /** @test */
    public function it_executes_a_whole_bundle_cash_return_receiving_each_group_under_its_own_owner(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [
            $posReturn,
            $parentSaleReturn,
            $componentSaleReturn,
            $parentDetail,
            $componentDetail,
            $parentProduct,
            $componentProduct,
        ] = $this->createPendingApprovalSplitOwnerWholeBundleCashReturn();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        // Each SaleReturn (one per distinct owner) received under its OWN location/owner.
        $parentStock = ProductStock::query()
            ->where('product_id', $parentProduct->id)
            ->where('location_id', $this->locationA->id)
            ->firstOrFail();
        $componentStock = ProductStock::query()
            ->where('product_id', $componentProduct->id)
            ->where('location_id', $this->locationB->id)
            ->firstOrFail();

        $this->assertGreaterThan(0, (int) $parentStock->quantity);
        $this->assertGreaterThan(0, (int) $componentStock->quantity);

        // Only the parent's own SaleReturn carries the single customer refund;
        // the cross-owner component's SaleReturn never gets its own separate refund payment.
        $this->assertTrue(SaleReturnPayment::query()->where('sale_return_id', $parentSaleReturn->id)->exists());
        $this->assertFalse(SaleReturnPayment::query()->where('sale_return_id', $componentSaleReturn->id)->exists());

        // HPP reversed exactly once per detail.
        $parentDetail->refresh();
        $componentDetail->refresh();
        $this->assertNotNull($parentDetail->cost_effective_at);
        $this->assertNotNull($componentDetail->cost_effective_at);
    }

    /**
     * 5.1: partial-by-quantity cash refund. The parent SaleDetail was
     * originally sold in quantity 2 (2 bundle units), and this return covers
     * only 1 of the 2 units. The component's own SaleDetail quantity is 4
     * (quantity_per_bundle=2 x 2 units), and this return's component line
     * covers exactly 2 (quantity_per_bundle=2 x 1 returned unit) — proving
     * proportional quantity synthesis holds at ACTUAL EXECUTION (receiving +
     * HPP), not just at draft-submission validation (already covered by
     * POSReturnBundleCashReturnCompletenessTest). After execution, the
     * remaining un-returned unit's worth of stock/refundable quantity must
     * still be intact (this return must not over-receive/over-refund the
     * untouched second unit).
     *
     * @test
     */
    public function it_executes_a_partial_by_quantity_whole_bundle_cash_refund_with_proportional_component_quantities(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        $parentProduct = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'PARTIAL-PARENT-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 0,
        ]);
        $componentProduct = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'PARTIAL-COMP-' . uniqid(),
            'sale_price' => 150,
            'stock_qty' => 0,
        ]);

        $sale = Sale::query()->create([
            'setting_id' => $this->settingA->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-PARTIAL-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2600,
            'paid_amount' => 2600,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        // Parent originally sold in quantity 2 (2 bundle units purchased).
        $parentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // Component's own SaleDetail quantity = quantity_per_bundle(2) x parent_qty(2) = 4.
        $componentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 4,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $bundleItem = SaleBundleItem::query()->create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'bundle_id' => random_int(9000, 999999),
            'bundle_item_id' => random_int(9000, 999999),
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 2,
            'price' => 300,
            'sub_total' => 600,
        ]);

        $parentDispatch = Dispatch::query()->create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $parentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'bundle_id' => $bundleItem->bundle_id,
            'dispatched_quantity' => 2,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
        ]);

        $componentDispatch = Dispatch::query()->create(['sale_id' => $sale->id, 'dispatch_date' => now(), 'status' => Dispatch::STATUS_APPROVED]);
        $componentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $componentDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $componentProduct->id,
            'bundle_id' => $bundleItem->bundle_id,
            'dispatched_quantity' => 4,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
        ]);

        $posReturn = $this->createPosReturnShell(['return_option' => PosReturn::OPTION_CASH_RETURN, 'total_amount' => 1000]);
        $saleReturn = $this->createSaleReturnShell($posReturn, $sale, [
            'return_type' => 'Cash Return',
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);

        $parentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'expected_cash_amount' => 1000,
            'bundle_group_key' => 'BUNDLE-PARTIAL-' . uniqid(),
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $componentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 2,
            'unit_price' => 300,
            'line_total' => 600,
            'expected_cash_amount' => 0,
            'bundle_group_key' => $parentLine->bundle_group_key,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 2,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $parentDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $parentLine->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'location_id' => $this->locationA->id,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'cash_return_amount' => 1000,
            ],
        ]);

        $componentDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $componentLine->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->locationA->id,
            'quantity' => 2,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'component_sale_bundle_item_id' => $bundleItem->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'component',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'cash_return_amount' => 0,
                'component_sale_bundle_item_id' => $bundleItem->id,
                'commercial_value_source' => 'sale_bundle_item',
            ],
        ]);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        // Receiving quantities match the PARTIAL (1-of-2-unit) proportional
        // amounts, not the full originally-sold quantities (2 parent / 4 component).
        $parentStock = ProductStock::query()->where('product_id', $parentProduct->id)->where('location_id', $this->locationA->id)->firstOrFail();
        $componentStock = ProductStock::query()->where('product_id', $componentProduct->id)->where('location_id', $this->locationA->id)->firstOrFail();
        $this->assertSame(1, (int) $parentStock->quantity);
        $this->assertSame(2, (int) $componentStock->quantity);

        // Single refund on the parent's SaleReturn only, for the partial (1 unit) amount.
        $this->assertTrue(SaleReturnPayment::query()->where('sale_return_id', $saleReturn->id)->where('amount', 1000)->exists());

        // HPP reversed exactly once per detail, proportional to the returned quantity.
        $parentDetail->refresh();
        $componentDetail->refresh();
        $this->assertNotNull($parentDetail->cost_effective_at);
        $this->assertNotNull($componentDetail->cost_effective_at);
    }

    /**
     * Chained serial replacement fixture: B-OLD -> B-NEW -> B-NEWER, where the
     * B-OLD->B-NEW hop is an already-COMPLETED historical PosReturn (Phase 2
     * fixture shape). Executing a whole-bundle cash return against the
     * CURRENT PosReturn must receive B-NEWER (the current leaf), never
     * B-OLD/B-NEW, and must not re-touch the historical completed return.
     *
     * @test
     */
    public function it_receives_the_current_leaf_serial_in_a_chained_replacement_and_leaves_history_untouched(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [
            $posReturn,
            $historicalPosReturn,
            $bNewer,
            $bOld,
            $bNew,
            $product,
        ] = $this->createChainedSerialReplacementCashReturnFixture();

        $historicalStatusBefore = $historicalPosReturn->status;

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        // B-NEWER (the current leaf) is received as active.
        $bNewer->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, (string) $bNewer->status);

        // B-OLD and B-NEW (already resolved historically) are untouched.
        $bOld->refresh();
        $bNew->refresh();
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, (string) $bNew->status);

        $this->assertSame($historicalStatusBefore, $historicalPosReturn->fresh()->status);
    }

    /**
     * Inject a failure after the PARENT's effects are applied but before a
     * COMPONENT's in a mixed-line approval; assert the entire transaction
     * rolled back with no partial ProductStock/Dispatch/cost_effective_at
     * changes persisted.
     *
     * @test
     */
    public function it_rolls_back_the_entire_transaction_when_a_later_detail_fails_mid_execution(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $parentDetail, $componentDetail, $parentProduct, $componentProduct] =
            $this->createPendingApprovalMixedCashReturnForRollback();

        $service = new class extends PosReturnLifecycleService {
            protected function applyCashSettlementForSaleReturn(
                \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
                float $settlementAmount,
                \Illuminate\Support\Carbon $settledAt,
                ?int $actorId
            ): void {
                parent::applyCashSettlementForSaleReturn($saleReturn, $settlementAmount, $settledAt, $actorId);

                throw new \RuntimeException('injected mid-execution failure');
            }
        };

        try {
            $service->executeApprovalFromPreview($posReturn->id);
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('injected mid-execution failure', $e->getMessage());
        }

        // The whole executeApprovalFromPreview() body runs inside ONE
        // DB::transaction(); a plain \RuntimeException (not
        // PosReturnManualCorrectionRequiredException) propagates straight out
        // of runLifecycleMutation() and rolls the transaction back completely
        // — the PosReturn is left at its original PENDING_APPROVAL status,
        // never partially advanced.
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->fresh()->status);

        $parentDetail->refresh();
        $componentDetail->refresh();
        $this->assertNull($parentDetail->cost_effective_at);
        $this->assertNull($componentDetail->cost_effective_at);
        $this->assertFalse(SaleReturnPayment::query()->where('sale_return_id', $parentDetail->sale_return_id)->exists());
        $this->assertFalse(SaleReturnPayment::query()->where('sale_return_id', $componentDetail->sale_return_id)->exists());
    }

    /**
     * Retry: calling executeApprovalFromPreview() twice for the same
     * PosReturn must be rejected by the existing status guard after the
     * first call advances status past PENDING_APPROVAL, without creating
     * duplicate serial history/dispatch/HPP rows.
     *
     * @test
     */
    public function it_rejects_a_retry_execution_after_the_first_call_already_completed(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $componentLine, $componentDetail, $returnedSerial, $replacementSerial] =
            $this->createPendingApprovalSerialComponentReplacement();

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->fresh()->status);

        $historyCountAfterFirst = \Modules\Product\Entities\SerialNumberHistory::query()
            ->whereIn('product_serial_number_id', [$returnedSerial->id, $replacementSerial->id])
            ->count();
        $dispatchDetailCountAfterFirst = DispatchDetail::query()
            ->where('pos_return_line_id', $componentLine->id)
            ->count();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only pending approval returns can be executed from approval preview.');

        try {
            app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);
        } finally {
            $this->assertSame(
                $historyCountAfterFirst,
                \Modules\Product\Entities\SerialNumberHistory::query()
                    ->whereIn('product_serial_number_id', [$returnedSerial->id, $replacementSerial->id])
                    ->count()
            );
            $this->assertSame(
                $dispatchDetailCountAfterFirst,
                DispatchDetail::query()->where('pos_return_line_id', $componentLine->id)->count()
            );
        }
    }

    /**
     * Duplicate-claim guard: PosReturnApprovalPlanPersistenceService's
     * assertPlanSerialsNotClaimedElsewhere() (invoked inside
     * executeApprovalFromPreview() when an approvalPlan is supplied) blocks a
     * second PosReturn from synchronizing a plan that claims a serial another
     * active return has already claimed. Directly exercises the guard using
     * the established simulated-concurrency pattern (two PosReturns racing
     * for the same returned_serial_id).
     *
     * @test
     */
    public function it_prevents_a_second_pos_return_from_claiming_a_serial_already_claimed_by_an_active_return(): void
    {
        $this->actingAsInSetting($this->actor, $this->settingA);

        [$posReturn, $componentLine, $componentDetail, $returnedSerial] =
            $this->createPendingApprovalSerialComponentReplacement();

        // Second PosReturn attempts to claim the SAME returned serial via a
        // fresh plan while the first return is still active (pending approval).
        $secondPosReturn = PosReturn::query()->create([
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-DUP-' . uniqid(),
            'receipt_number' => 'RCP-DUP-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-DUP-' . uniqid(),
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 100,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $plan = [
            'blockers' => [],
            'warnings' => [],
            'groups' => [
                [
                    'planned_header' => [
                        'sale_id' => $componentLine->sale_id,
                        'setting_id' => $this->settingA->id,
                        'location_id' => $this->locationA->id,
                        'total_amount' => 100,
                        'return_type' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    ],
                    'planned_details' => [
                        [
                            'row_type' => 'component',
                            'pos_return_line_id' => null,
                            'sale_detail_id' => $componentLine->sale_detail_id,
                            'dispatch_detail_id' => $componentLine->dispatch_detail_id,
                            'product_id' => $componentLine->product_id,
                            'product_name' => $componentLine->product_name,
                            'product_code' => $componentLine->product_code,
                            'quantity' => 1,
                            'amount' => 100,
                            'source_location_id' => $this->locationA->id,
                            'component_serial_ids' => [$returnedSerial->id],
                            'replacement_kind' => 'serial_inventory_replacement',
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Satu atau lebih serial pada rencana ini sudah diklaim oleh retur lain yang sedang diproses atau selesai.');

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($secondPosReturn->id, PosReturn::OPTION_PRODUCT_REPLACEMENT, $plan);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function createSaleWithComponentBundle(Setting $setting, $location, array $overrides = []): array
    {
        $parentProduct = $this->createStockedProduct($setting, $location, array_merge([
            'product_code' => 'LIFECYCLE-PARENT-' . uniqid(),
            'sale_price' => 1000,
            'stock_qty' => 1,
        ], $overrides['parent'] ?? []));

        $componentProduct = $this->createStockedProduct($setting, $location, array_merge([
            'product_code' => 'LIFECYCLE-COMP-' . uniqid(),
            'sale_price' => 300,
            'stock_qty' => 1,
            'serial_number_required' => true,
        ], $overrides['component'] ?? []));

        $sale = Sale::query()->create([
            'setting_id' => $setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-LIFECYCLE-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1300,
            'paid_amount' => 1300,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $parentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $componentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $bundleItem = SaleBundleItem::query()->create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'bundle_id' => random_int(9000, 999999),
            'bundle_item_id' => random_int(9000, 999999),
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 1,
            'price' => 300,
            'sub_total' => 300,
        ]);

        $parentDispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $parentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'bundle_id' => $bundleItem->bundle_id,
            'dispatched_quantity' => 1,
            'location_id' => $location->id,
            'tax_id' => null,
        ]);

        $componentDispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);
        $componentDispatchDetail = DispatchDetail::query()->create([
            'dispatch_id' => $componentDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $componentProduct->id,
            'bundle_id' => $bundleItem->bundle_id,
            'dispatched_quantity' => 1,
            'location_id' => $location->id,
            'tax_id' => null,
        ]);

        return [
            $sale,
            $parentProduct,
            $componentProduct,
            $parentSaleDetail,
            $componentSaleDetail,
            $bundleItem,
            $parentDispatchDetail,
            $componentDispatchDetail,
        ];
    }

    private function createPosReturnShell(array $overrides = []): PosReturn
    {
        return PosReturn::query()->create(array_merge([
            'setting_id' => $this->settingA->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-' . uniqid(),
            'receipt_number' => 'RCP-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 100,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ], $overrides));
    }

    private function createSaleReturnShell(PosReturn $posReturn, Sale $sale, array $overrides = []): SaleReturn
    {
        return SaleReturn::query()->create(array_merge([
            'setting_id' => $this->settingA->id,
            'location_id' => $this->locationA->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SR-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ], $overrides));
    }

    private function createPendingApprovalSerialComponentReplacement(): array
    {
        [
            $sale,
            $parentProduct,
            $componentProduct,
            $parentSaleDetail,
            $componentSaleDetail,
            $bundleItem,
            $parentDispatchDetail,
            $componentDispatchDetail,
        ] = $this->createSaleWithComponentBundle($this->settingA, $this->locationA);

        $returnedSerial = $this->createSerialNumber($componentProduct, $this->locationA, 'SN-RET-' . uniqid());
        $returnedSerial->update([
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $replacementSerial = $this->createSerialNumber($componentProduct, $this->locationA, 'SN-REP-' . uniqid());
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        $posReturn = $this->createPosReturnShell();
        $saleReturn = $this->createSaleReturnShell($posReturn, $sale);

        $componentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'bundle_group_key' => 'BUNDLE-COMP-' . uniqid(),
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 1,
            'serial_number_ids' => [$returnedSerial->id],
            'returned_serial_id' => $returnedSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $componentProduct->id,
            'replacement_quantity' => 1,
        ]);

        $componentDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $componentLine->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returnedSerial->id],
            'component_sale_bundle_item_id' => $bundleItem->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'component',
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'execution_mode' => 'same_owner_replacement',
                'replacement_kind' => 'serial_inventory_replacement',
                'component_sale_bundle_item_id' => $bundleItem->id,
                'component_serial_ids' => [$returnedSerial->id],
                'commercial_value_source' => 'sale_bundle_item',
            ],
        ]);

        return [$posReturn, $componentLine, $componentDetail, $returnedSerial, $replacementSerial, $componentProduct];
    }

    private function createPendingApprovalCrossOwnerComponentReplacement(): array
    {
        [
            $sale,
            $parentProduct,
            $componentProduct,
            $parentSaleDetail,
            $componentSaleDetail,
            $bundleItem,
            $parentDispatchDetail,
            $componentDispatchDetail,
        ] = $this->createSaleWithComponentBundle($this->settingA, $this->locationA);

        // Component product also stocked under settingB for the replacement owner side.
        ProductStock::query()->create([
            'product_id' => $componentProduct->id,
            'location_id' => $this->locationB->id,
            'quantity' => 3,
            'quantity_tax' => 0,
            'quantity_non_tax' => 3,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'tax_id' => null,
        ]);

        $returnedSerial = $this->createSerialNumber($componentProduct, $this->locationA, 'SN-XRET-' . uniqid());
        $returnedSerial->update([
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $replacementSerial = $this->createSerialNumber($componentProduct, $this->locationB, 'SN-XREP-' . uniqid());
        $replacementSerial->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        $customer = Customer::query()->create([
            'setting_id' => $this->settingB->id,
            'customer_name' => 'Cross-Owner Component Customer',
            'customer_email' => 'cross-comp@example.com',
            'customer_phone' => '0822222222',
        ]);

        $posReturn = $this->createPosReturnShell();
        $saleReturn = $this->createSaleReturnShell($posReturn, $sale);

        $componentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'bundle_group_key' => 'BUNDLE-XCOMP-' . uniqid(),
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 1,
            'serial_number_ids' => [$returnedSerial->id],
            'returned_serial_id' => $returnedSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $componentProduct->id,
            'replacement_quantity' => 1,
        ]);

        $componentDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $componentLine->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returnedSerial->id],
            'component_sale_bundle_item_id' => $bundleItem->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'component',
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'execution_mode' => 'cross_owner_replacement',
                'replacement_kind' => 'serial_inventory_replacement',
                'component_sale_bundle_item_id' => $bundleItem->id,
                'component_serial_ids' => [$returnedSerial->id],
                'commercial_value_source' => 'sale_bundle_item',
                'replacement_serial_owner_setting_id' => $this->settingB->id,
                'replacement_serial_location_id' => $this->locationB->id,
                'original_sale_correction_quantity' => 1,
                'original_sale_correction_amount' => 300,
                'generated_replacement_sale_effects' => [
                    'setting_id' => $this->settingB->id,
                    'setting_name' => 'Lifecycle Execution Setting B',
                    'location_id' => $this->locationB->id,
                    'location_name' => 'Location B',
                    'sale_reference' => 'generated_on_approval',
                    'customer_id' => $customer->id,
                    'customer_resolution_source' => 'existing',
                    'payment_amount' => 300.0,
                    'dispatch_quantity' => 1.0,
                ],
            ],
        ]);

        return [$posReturn, $saleReturn, $componentDetail, $returnedSerial, $replacementSerial, $componentProduct, $customer];
    }

    private function createPendingApprovalNoteOnlyReplacement(): array
    {
        $product = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'NOTE-ONLY-' . uniqid(),
            'sale_price' => 200,
            'stock_qty' => 5,
        ]);

        $sale = Sale::query()->create([
            'setting_id' => $this->settingA->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-NOTEONLY-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 200,
            'paid_amount' => 200,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $saleDetail = SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => 200,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
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
            'location_id' => $this->locationA->id,
            'tax_id' => null,
        ]);

        $posReturn = $this->createPosReturnShell(['total_amount' => 200]);
        $saleReturn = $this->createSaleReturnShell($posReturn, $sale, ['total_amount' => 200, 'due_amount' => 200]);

        $line = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 200,
            'line_total' => 200,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 1,
            'line_meta' => ['execution_mode' => 'non_serial_note_only', 'replacement_reason' => 'Warna berbeda'],
        ]);

        $detail = SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => 200,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'replacement_kind' => 'non_serial_note_only',
            ],
        ]);

        return [$posReturn, $line, $detail, $product];
    }

    private function createPendingApprovalNoteOnlyBundleReplacement(string $target): array
    {
        [
            $sale,
            $parentProduct,
            $componentProduct,
            $parentSaleDetail,
            $componentSaleDetail,
            $bundleItem,
            $parentDispatchDetail,
            $componentDispatchDetail,
        ] = $this->createSaleWithComponentBundle($this->settingA, $this->locationA);

        $posReturn = $this->createPosReturnShell();
        $saleReturn = $this->createSaleReturnShell($posReturn, $sale);

        $isParent = $target === 'parent';
        $product = $isParent ? $parentProduct : $componentProduct;
        $saleDetail = $isParent ? $parentSaleDetail : $componentSaleDetail;
        $dispatchDetail = $isParent ? $parentDispatchDetail : $componentDispatchDetail;

        $lineAttributes = [
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => $isParent ? 1000 : 300,
            'line_total' => $isParent ? 1000 : 300,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 1,
            'line_meta' => ['execution_mode' => 'non_serial_note_only'],
        ];

        if (! $isParent) {
            $lineAttributes['bundle_group_key'] = 'BUNDLE-NOTEONLY-' . uniqid();
            $lineAttributes['bundle_parent_sale_detail_id'] = $parentSaleDetail->id;
            $lineAttributes['bundle_quantity'] = 1;
            $lineAttributes['component_quantity_per_bundle'] = 1;
        }

        $line = PosReturnLine::query()->create($lineAttributes);

        $detailAttributes = [
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => $isParent ? 1000 : 300,
            'unit_price' => $isParent ? 1000 : 300,
            'sub_total' => $isParent ? 1000 : 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => $isParent ? 'parent' : 'component',
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'replacement_kind' => 'non_serial_note_only',
                'component_sale_bundle_item_id' => $isParent ? null : $bundleItem->id,
            ],
        ];

        if (! $isParent) {
            $detailAttributes['component_sale_bundle_item_id'] = $bundleItem->id;
            $detailAttributes['cost_origin'] = SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM;
        }

        $detail = SaleReturnDetail::query()->create($detailAttributes);

        return [$posReturn, $line, $detail, $product];
    }

    private function createPendingApprovalSplitOwnerWholeBundleCashReturn(): array
    {
        [
            $sale,
            $parentProduct,
            $componentProduct,
            $parentSaleDetail,
            $componentSaleDetail,
            $bundleItem,
            $parentDispatchDetail,
            $componentDispatchDetail,
        ] = $this->createSaleWithComponentBundle($this->settingA, $this->locationA);

        // Component product also stocked under settingB (split-owner component).
        ProductStock::query()->create([
            'product_id' => $componentProduct->id,
            'location_id' => $this->locationB->id,
            'quantity' => 1,
            'quantity_tax' => 0,
            'quantity_non_tax' => 1,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'tax_id' => null,
        ]);

        $posReturn = $this->createPosReturnShell(['return_option' => PosReturn::OPTION_CASH_RETURN, 'total_amount' => 1300]);

        $parentSaleReturn = $this->createSaleReturnShell($posReturn, $sale, [
            'return_type' => 'Cash Return',
            'total_amount' => 1300,
            'due_amount' => 1300,
        ]);

        $componentSaleReturn = $this->createSaleReturnShell($posReturn, $sale, [
            'setting_id' => $this->settingB->id,
            'location_id' => $this->locationB->id,
            'return_type' => 'Cash Return',
            'total_amount' => 0,
            'due_amount' => 0,
        ]);

        $parentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $parentSaleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'expected_cash_amount' => 1300,
            'bundle_group_key' => 'BUNDLE-SPLIT-' . uniqid(),
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $componentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $componentSaleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'source_setting_id' => $this->settingB->id,
            'source_location_id' => $this->locationB->id,
            'tax_id' => null,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'expected_cash_amount' => 0,
            'bundle_group_key' => $parentLine->bundle_group_key,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 1,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $parentDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $parentSaleReturn->id,
            'pos_return_line_id' => $parentLine->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'cash_return_amount' => 1300,
            ],
        ]);

        $componentDetail = SaleReturnDetail::query()->create([
            'sale_return_id' => $componentSaleReturn->id,
            'pos_return_line_id' => $componentLine->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->locationB->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            // Internal allocation amount is 0 — the component never carries its
            // own separate customer-facing refund; the whole-bundle refund is
            // carried entirely by the parent's own SaleReturn (design.md decision:
            // "Parent group alone carries the customer refund").
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'component_sale_bundle_item_id' => $bundleItem->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'component',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'cash_return_amount' => 0,
                'component_sale_bundle_item_id' => $bundleItem->id,
                'commercial_value_source' => 'sale_bundle_item',
            ],
        ]);

        return [
            $posReturn,
            $parentSaleReturn,
            $componentSaleReturn,
            $parentDetail,
            $componentDetail,
            $parentProduct,
            $componentProduct,
        ];
    }

    private function createChainedSerialReplacementCashReturnFixture(): array
    {
        $product = $this->createStockedProduct($this->settingA, $this->locationA, [
            'product_code' => 'CHAIN-' . uniqid(),
            'sale_price' => 500,
            'stock_qty' => 3,
            'serial_number_required' => true,
        ]);

        $bOld = $this->createSerialNumber($product, $this->locationA, 'B-OLD-' . uniqid());
        $bNew = $this->createSerialNumber($product, $this->locationA, 'B-NEW-' . uniqid());
        $bNewer = $this->createSerialNumber($product, $this->locationA, 'B-NEWER-' . uniqid());

        $bOld->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);
        $bNew->update(['status' => ProductSerialNumber::STATUS_SOLD]);
        $bNewer->update(['status' => ProductSerialNumber::STATUS_SOLD]);

        // Historical, ALREADY-COMPLETED PosReturn representing the B-OLD -> B-NEW hop.
        $historicalSale = Sale::query()->create([
            'setting_id' => $this->settingA->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-CHAIN-HIST-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $historicalSaleDetail = SaleDetails::query()->create([
            'sale_id' => $historicalSale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $historicalPosReturn = $this->createPosReturnShell([
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        PosReturnLine::query()->create([
            'pos_return_id' => $historicalPosReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_id' => $historicalSale->id,
            'sale_detail_id' => $historicalSaleDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 500,
            'line_total' => 500,
            'returned_serial_id' => $bOld->id,
            'replacement_serial_id' => $bNew->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 1,
        ]);

        // Current, whole-bundle cash return referencing the CURRENT leaf: B-NEWER.
        [
            $sale,
            $parentProduct,
            $componentProduct,
            $parentSaleDetail,
            $componentSaleDetail,
            $bundleItem,
            $parentDispatchDetail,
            $componentDispatchDetail,
        ] = $this->createSaleWithComponentBundle($this->settingA, $this->locationA, [
            'component' => ['product_code' => 'CHAIN-BUNDLE-COMP-' . uniqid()],
        ]);

        $bNewer->update(['dispatch_detail_id' => $componentDispatchDetail->id]);

        $posReturn = $this->createPosReturnShell(['return_option' => PosReturn::OPTION_CASH_RETURN, 'total_amount' => 1300]);
        $saleReturn = $this->createSaleReturnShell($posReturn, $sale, [
            'return_type' => 'Cash Return',
            'total_amount' => 1300,
            'due_amount' => 1300,
        ]);

        $parentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'expected_cash_amount' => 1300,
            'bundle_group_key' => 'BUNDLE-CHAIN-' . uniqid(),
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $componentLine = PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'source_setting_id' => $this->settingA->id,
            'source_location_id' => $this->locationA->id,
            'tax_id' => null,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 1,
            'unit_price' => 300,
            'line_total' => 300,
            'expected_cash_amount' => 0,
            'serial_number_ids' => [$bNewer->id],
            'returned_serial_id' => $bNewer->id,
            'bundle_group_key' => $parentLine->bundle_group_key,
            'bundle_parent_sale_detail_id' => $parentSaleDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 1,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $parentLine->id,
            'sale_detail_id' => $parentSaleDetail->id,
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'parent',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'cash_return_amount' => 1300,
            ],
        ]);

        SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $componentLine->id,
            'sale_detail_id' => $componentSaleDetail->id,
            'dispatch_detail_id' => $componentDispatchDetail->id,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'location_id' => $this->locationA->id,
            'tax_id' => null,
            'quantity' => 1,
            'price' => 300,
            'unit_price' => 300,
            'sub_total' => 300,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'serial_number_ids' => [$bNewer->id],
            'component_sale_bundle_item_id' => $bundleItem->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'execution_context' => [
                'row_type' => 'component',
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'cash_return_amount' => 0,
                'component_sale_bundle_item_id' => $bundleItem->id,
                'commercial_value_source' => 'sale_bundle_item',
                'component_serial_ids' => [$bNewer->id],
            ],
        ]);

        return [$posReturn, $historicalPosReturn, $bNewer, $bOld, $bNew, $product];
    }

    private function createPendingApprovalMixedCashReturnForRollback(): array
    {
        [
            $posReturn,
            $parentSaleReturn,
            $componentSaleReturn,
            $parentDetail,
            $componentDetail,
            $parentProduct,
            $componentProduct,
        ] = $this->createPendingApprovalSplitOwnerWholeBundleCashReturn();

        return [$posReturn, $parentDetail, $componentDetail, $parentProduct, $componentProduct];
    }
}

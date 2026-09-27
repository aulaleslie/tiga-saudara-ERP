<?php

namespace Modules\Pos\Tests\Feature;

use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

class POSReturnApprovalPreviewPlannerTest extends PosTransactionFeatureTestCase
{
    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

    protected PosReturnApprovalPreviewPlannerService $planner;

    protected $setting;

    protected $secondSetting;

    protected $location;

    protected $secondLocation;

    protected $terminal;

    protected $session;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->planner = app(PosReturnApprovalPreviewPlannerService::class);
        $this->setting = $this->createSetting('POS Return Approval Preview Planner Test');
        $this->secondSetting = $this->createSetting('POS Return Approval Preview Planner Test 2');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        [, $this->secondLocation] = $this->createTerminalWithLocation($this->secondSetting);

        foreach (['pos.returns.create', 'pos.returns.approve'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Approval Preview Planner User', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.approve',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_groups_preview_targets_by_generated_sale_and_source_owner_location_when_no_linked_sales_returns_exist(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [$firstSale, $firstDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'A');
        [$secondSale, $secondDetail] = $this->createSaleGraph($checkout, $this->secondSetting->id, $this->secondLocation->id, 'B');

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $firstDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
            [
                'sale_detail_id' => $secondDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked']);
        $this->assertCount(2, $plan['groups']);
        $this->assertSame([], $posReturn->fresh()->saleReturns->all());
        $this->assertSame(
            [$firstSale->id, $secondSale->id],
            collect($plan['groups'])->pluck('source_sale.id')->sort()->values()->all()
        );
        $this->assertContains('no_linked_sale_returns', collect($plan['info'])->pluck('code')->all());
    }

    /** @test */
    public function it_resolves_serial_dispatch_from_product_serial_numbers_dispatch_detail_id(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'SERIAL-' . uniqid(),
            'product_name' => 'Preview Serial Product',
            'serial_number_required' => true,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-PREVIEW-' . uniqid());
        [$sale, $detail, $dispatchDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'SER', $product, [
            'serial_number_ids' => [$serial->id],
        ]);
        $serial->update(['dispatch_detail_id' => $dispatchDetail->id, 'status' => ProductSerialNumber::STATUS_SOLD]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detail->id,
                'returned_serial_id' => $serial->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);
        $posReturn->lines()->update(['dispatch_detail_id' => null]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan));
        $detailPlan = $plan['groups'][0]['planned_details'][0];
        $this->assertSame($dispatchDetail->id, $detailPlan['dispatch_detail_id']);
        $this->assertSame('returned_serial.dispatch_detail_id', $detailPlan['dispatch_resolution']);
    }

    /** @test */
    public function it_reports_missing_and_ambiguous_dispatch_blockers_for_non_serial_lines(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$missingTransaction, $missingCheckout] = $this->createTransactionWithCheckout();
        [$missingSale, $missingDetail] = $this->createSaleGraph($missingCheckout, $this->setting->id, $this->location->id, 'MISS');
        $missingPosReturn = $this->makePendingReturn($missingTransaction->id, [
            [
                'sale_detail_id' => $missingDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);
        $missingPosReturn->lines()->update(['dispatch_detail_id' => null]);
        DispatchDetail::query()->where('sale_id', $missingSale->id)->delete();

        $missingPlan = $this->planner->plan($missingPosReturn->fresh());
        $this->assertTrue($missingPlan['is_blocked']);
        $this->assertContains('dispatch_missing', collect($missingPlan['blockers'])->pluck('code')->all());

        [$ambiguousTransaction, $ambiguousCheckout] = $this->createTransactionWithCheckout();
        [$ambiguousSale, $ambiguousDetail, $ambiguousDispatch] = $this->createSaleGraph($ambiguousCheckout, $this->setting->id, $this->location->id, 'AMB');
        DispatchDetail::query()->create([
            'dispatch_id' => $ambiguousDispatch->dispatch_id,
            'sale_id' => $ambiguousSale->id,
            'product_id' => $ambiguousDetail->product_id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $ambiguousPosReturn = $this->makePendingReturn($ambiguousTransaction->id, [
            [
                'sale_detail_id' => $ambiguousDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);
        $ambiguousPosReturn->lines()->update(['dispatch_detail_id' => null]);
        DispatchDetail::query()->where('sale_detail_id', $ambiguousDetail->id)->delete();

        $ambiguousPlan = $this->planner->plan($ambiguousPosReturn->fresh());
        $this->assertTrue($ambiguousPlan['is_blocked']);
        $this->assertContains('dispatch_ambiguous', collect($ambiguousPlan['blockers'])->pluck('code')->all());
    }

    /** @test */
    public function it_allows_mixed_cash_return_and_product_replacement_preview_when_each_line_is_resolvable(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'MIX-' . uniqid(),
            'product_name' => 'Mixed Preview Product',
            'serial_number_required' => true,
        ]);
        $serialA = $this->createSerialNumber($product, $this->location, 'SN-MIX-A-' . uniqid());
        $serialB = $this->createSerialNumber($product, $this->location, 'SN-MIX-B-' . uniqid());
        $replacement = $this->createSerialNumber($product, $this->location, 'SN-MIX-R-' . uniqid());
        $replacement->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);

        [, $detailA] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'MIXA', $product, [
            'serial_number_ids' => [$serialA->id],
        ]);
        [, $detailB] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'MIXB', $product, [
            'serial_number_ids' => [$serialB->id],
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detailA->id,
                'returned_serial_id' => $serialA->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
            [
                'sale_detail_id' => $detailB->id,
                'returned_serial_id' => $serialB->id,
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'replacement_serial_id' => $replacement->id,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());
        if ($plan['is_blocked']) {
            throw new \RuntimeException(json_encode($plan, JSON_PRETTY_PRINT));
        }

        $this->assertFalse($plan['is_blocked']);
        $this->assertNotContains('mixed_options', collect($plan['blockers'])->pluck('code')->all());
        $this->assertSame(
            [
                PosReturnLine::RESOLUTION_CASH_RETURN,
                PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            ],
            collect($plan['groups'])
                ->flatMap(fn (array $group) => collect($group['planned_details'])->pluck('resolution'))
                ->sort()
                ->values()
                ->all()
        );
    }

    /** @test */
    public function it_marks_same_owner_replacement_preview_without_generated_sale_effects(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'SAME-OWNER-' . uniqid(),
            'product_name' => 'Same Owner Replacement Product',
            'serial_number_required' => true,
        ]);
        $returnedSerial = $this->createSerialNumber($product, $this->location, 'SN-SAME-RET-' . uniqid());
        $replacementSerial = $this->createSerialNumber($product, $this->location, 'SN-SAME-REP-' . uniqid());
        [, $detail, $dispatchDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'SAME', $product, [
            'serial_number_ids' => [$returnedSerial->id],
        ]);
        $returnedSerial->update([
            'dispatch_detail_id' => $dispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $detail->id,
            'returned_serial_id' => $returnedSerial->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'replacement_serial_id' => $replacementSerial->id,
        ]]);

        $plan = $this->planner->plan($posReturn->fresh());
        $detailPlan = $plan['groups'][0]['planned_details'][0];

        $this->assertFalse($plan['is_blocked'], json_encode($plan));
        $this->assertSame('same_owner_replacement', $detailPlan['execution_mode']);
        $this->assertSame($this->setting->id, $detailPlan['replacement_serial_owner_setting_id']);
        $this->assertSame($this->location->id, $detailPlan['replacement_serial_location_id']);
        $this->assertNull($detailPlan['generated_replacement_sale_effects']);
        $this->assertNull($detailPlan['original_sale_correction_amount']);
    }

    /** @test */
    public function it_plans_cross_owner_replacement_preview_with_original_sale_correction_and_generated_sale_effects(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'CROSS-OWNER-' . uniqid(),
            'product_name' => 'Cross Owner Replacement Product',
            'serial_number_required' => true,
        ]);
        $returnedSerial = $this->createSerialNumber($product, $this->location, 'SN-CROSS-RET-' . uniqid());
        $replacementSerial = $this->createSerialNumber($product, $this->secondLocation, 'SN-CROSS-REP-' . uniqid());
        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        [$sale, $detail, $dispatchDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'CROSS', $product, [
            'serial_number_ids' => [$returnedSerial->id],
        ]);
        $sale->update([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
        ]);
        $returnedSerial->update([
            'dispatch_detail_id' => $dispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $detail->id,
            'returned_serial_id' => $returnedSerial->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'replacement_serial_id' => $replacementSerial->id,
        ]]);

        $plan = $this->planner->plan($posReturn->fresh());
        $detailPlan = $plan['groups'][0]['planned_details'][0];

        $this->assertFalse($plan['is_blocked'], json_encode($plan));
        $this->assertSame('cross_owner_replacement', $detailPlan['execution_mode']);
        $this->assertSame($this->secondSetting->id, $detailPlan['replacement_serial_owner_setting_id']);
        $this->assertSame($this->secondLocation->id, $detailPlan['replacement_serial_location_id']);
        $this->assertSame(1.0, (float) $detailPlan['original_sale_correction_quantity']);
        $this->assertSame((float) $detail->sub_total, (float) $detailPlan['original_sale_correction_amount']);
        $this->assertSame($this->secondSetting->id, data_get($detailPlan, 'generated_replacement_sale_effects.setting_id'));
        $this->assertSame($this->secondLocation->id, data_get($detailPlan, 'generated_replacement_sale_effects.location_id'));
        $this->assertSame('selected', data_get($detailPlan, 'generated_replacement_sale_effects.customer_resolution_source'));
    }

    /**
     * Under the new model, a whole-bundle cash return's component that was
     * dispatched from a DIFFERENT owner/location (split-owner bundle) must be
     * expanded by real Phase 2 synthesis (PosReturnSubmissionService) into
     * its own persisted, real PosReturnLine — and the planner must render
     * that as its own group keyed by the component's own source sale, using
     * the component's own owner/location rather than the parent's. This
     * replaces the old flow, which hand-built SaleBundleItem "candidate" rows
     * on a separately-created component Sale/PosCheckoutSale and relied on
     * planner-side live candidate re-derivation (buildComponentEntries(),
     * removed) to match them up; the new planner reads only persisted
     * PosReturnLine identity, so the fixture must give synthesis a real
     * zero-price component SaleDetails sibling row + its own DispatchDetail
     * pointing at the split owner's location.
     *
     * @test
     */
    public function it_expands_split_owner_bundle_component_targets_into_generated_source_sale_groups(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BND-PARENT-' . uniqid(),
            'product_name' => 'Preview Bundle Parent',
            'serial_number_required' => true,
            'sale_price' => 1500,
        ]);
        $componentProduct = $this->createStockedProduct($this->secondSetting, $this->secondLocation, [
            'product_code' => 'BND-COMP-' . uniqid(),
            'product_name' => 'Preview Bundle Component',
            'sale_price' => 0,
        ]);
        $returnedSerial = $this->createSerialNumber($parentProduct, $this->location, 'SN-BND-' . uniqid());

        [$parentSale, $parentDetail, $parentDispatchDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'BNDP',
            $parentProduct,
            ['serial_number_ids' => [$returnedSerial->id], 'sub_total' => 1500, 'unit_price' => 1500, 'price' => 1500]
        );
        $returnedSerial->update([
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SaleBundleItem::query()->create([
            'sale_id' => $parentSale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 501,
            'bundle_item_id' => 701,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'pos-0-0',
        ]);

        // Component's own real, persisted sibling SaleDetails row (unit_price
        // = 0), same sale_id as the parent — required by
        // PosReturnSubmissionService::synthesizeBundleCashReturnComponents().
        $componentDetail = SaleDetails::query()->create([
            'sale_id' => $parentSale->id,
            'product_id' => $componentProduct->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Component's own DispatchDetail, dispatched from the SPLIT owner's
        // location — its own lineage, independent of the parent's.
        DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatchDetail->dispatch_id,
            'sale_id' => $parentSale->id,
            'product_id' => $componentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->secondLocation->id,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $parentDetail->id,
                'returned_serial_id' => $returnedSerial->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));
        $this->assertCount(2, $plan['groups']);

        // Component line shares the parent's sale_id (same-sale sibling row),
        // but its dispatch/owner resolution is its own — group keying is by
        // owner/location, so locate it by owner instead of source_sale.id.
        $componentGroup = collect($plan['groups'])->first(function (array $group) {
            return ($group['source_owner']['setting_id'] ?? null) === $this->secondSetting->id;
        });
        $this->assertNotNull($componentGroup);
        $this->assertSame($this->secondSetting->id, $componentGroup['source_owner']['setting_id']);
        $this->assertSame($this->secondLocation->id, $componentGroup['source_location']['location_id']);

        $componentDetailPlan = $componentGroup['planned_details'][0];
        $this->assertSame('component', $componentDetailPlan['row_type']);
        $this->assertSame($componentProduct->id, $componentDetailPlan['product_id']);
        // source_pos_product_id/name/code mirror the row's own product for
        // every persisted PosReturnLine (component or parent) under the new
        // model — there is no longer a separate "parent product" field
        // carried on the component row.
        $this->assertSame($componentProduct->id, $componentDetailPlan['source_pos_product_id']);
        // The component here is not serial-required and has no serial
        // lineage of its own in this fixture, so its own serial identity is
        // correctly unresolved rather than inheriting the parent's serial
        // number as a misleading display value.
        $this->assertNull($componentDetailPlan['returned_serial']);
        $this->assertSame([], $componentDetailPlan['component_serial_ids']);
    }

    /**
     * Real intent: a whole-bundle cash return for a bundle sold at quantity 2
     * must synthesize the component's own persisted PosReturnLine using its
     * OWN sale_detail-linked SaleDetails row (unit_price = 0), with quantity
     * scaled by quantity_per_bundle * parent quantity (1 * 2 = 2) and amount
     * derived from the component's own unit price on that row. Under the new
     * model, this is exactly what real Phase 2 synthesis does — there is no
     * more separate "return line share" live-matching machinery in the
     * planner (removed); the planner only renders whatever synthesis already
     * persisted. Rewritten to build a real component SaleDetails sibling row
     * + DispatchDetail so `makePendingReturn()`'s store() call naturally
     * synthesizes the component PosReturnLine.
     *
     * @test
     */
    public function it_maps_component_targets_from_sale_detail_linked_rows_using_return_line_share(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BND-SHARE-PARENT-' . uniqid(),
            'product_name' => 'Preview Shared Bundle Parent',
            'serial_number_required' => true,
            'sale_price' => 6000,
        ]);
        $componentProduct = $this->createStockedProduct($this->secondSetting, $this->secondLocation, [
            'product_code' => 'BND-SHARE-COMP-' . uniqid(),
            'product_name' => 'Preview Shared Bundle Component',
            'sale_price' => 0,
        ]);
        $returnedSerial = $this->createSerialNumber($parentProduct, $this->location, 'SN-BND-SHARE-' . uniqid());

        [$parentSale, $parentDetail, $parentDispatchDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'BNDSHAREP',
            $parentProduct,
            ['quantity' => 2, 'sub_total' => 12000, 'unit_price' => 6000, 'price' => 6000]
        );
        $returnedSerial->update([
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SaleBundleItem::query()->create([
            'sale_id' => $parentSale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 9901,
            'bundle_item_id' => 9911,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 2,
            'price' => 200,
            'sub_total' => 400,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'pos-0-0',
        ]);

        // Component's own real sibling SaleDetails row (unit_price = 0),
        // quantity 2 (matching the total component quantity for this bundle
        // line: quantity_per_bundle 1 * parent quantity 2) — required by
        // PosReturnSubmissionService::synthesizeBundleCashReturnComponents(),
        // which matches on product_id + unit_price === 0.0.
        $componentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $parentSale->id,
            'product_id' => $componentProduct->id,
            'quantity' => 2,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatchDetail->dispatch_id,
            'sale_id' => $parentSale->id,
            'product_id' => $componentProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->secondLocation->id,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $parentDetail->id,
                'quantity' => 2,
                'returned_serial_id' => $returnedSerial->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));

        $componentGroup = collect($plan['groups'])->first(function (array $group) {
            return ($group['source_owner']['setting_id'] ?? null) === $this->secondSetting->id;
        });
        $this->assertNotNull($componentGroup);

        $componentDetail = $componentGroup['planned_details'][0];
        $this->assertSame('component', $componentDetail['row_type']);
        $this->assertSame(2.0, (float) $componentDetail['quantity']);
        // Component allocations carry unit_price = 0 under the new model —
        // no separate customer-facing amount, since the parent line already
        // reflects the full bundle price (Corrections/1).
        $this->assertSame(0.0, (float) $componentDetail['amount']);
        $this->assertSame($componentSaleDetail->id, $componentDetail['sale_detail_id']);
    }

    /**
     * Real intent, reinterpreted for the new model: the OLD test simulated a
     * single component SaleDetails row shared across TWO different bundles
     * (two SaleBundleItem rows with different bundle_id pointing at the same
     * sale_detail_id) and asserted the planner picks only the multiplier
     * belonging to the SELECTED bundle. Under the new model this shape is
     * impossible — synthesis creates exactly one component SaleDetails
     * sibling row per bundle LINE (scoped 1:1 to that specific parent
     * SaleDetails/bundle instance), so there is no live multi-bundle
     * candidate list to "limit" at preview time; synthesis's own
     * product_id + unit_price===0.0 lookup already scopes correctly per
     * bundle line. The equivalent, still-meaningful assertion under the new
     * model is: an UNRELATED plain (non-bundle) line submitted alongside a
     * whole-bundle cash return must not leak into or affect the bundle's own
     * synthesized component quantity — isolation between concurrent return
     * lines, one bundle-derived and one not.
     *
     * @test
     */
    public function it_limits_bundle_component_mapping_to_the_selected_bundle_parent_multiplier(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $bundleParentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BND-LIMIT-PARENT-' . uniqid(),
            'product_name' => 'Preview Limited Bundle Parent',
            'serial_number_required' => true,
            'sale_price' => 5000,
        ]);
        $plainProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PLAIN-PARENT-' . uniqid(),
            'product_name' => 'Preview Plain Product',
            'sale_price' => 700,
        ]);
        $componentProduct = $this->createStockedProduct($this->secondSetting, $this->secondLocation, [
            'product_code' => 'BND-LIMIT-COMP-' . uniqid(),
            'product_name' => 'Preview Limited Bundle Component',
            'sale_price' => 0,
        ]);
        $returnedSerial = $this->createSerialNumber($bundleParentProduct, $this->location, 'SN-BND-LIMIT-' . uniqid());

        [, $plainDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'PLAINLIM',
            $plainProduct,
            ['quantity' => 1, 'sub_total' => 700, 'unit_price' => 700, 'price' => 700]
        );

        [$bundleSale, $bundleDetail, $bundleDispatchDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'BNDLIM',
            $bundleParentProduct,
            ['quantity' => 1, 'sub_total' => 5000, 'unit_price' => 5000, 'price' => 5000]
        );
        $returnedSerial->update([
            'dispatch_detail_id' => $bundleDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SaleBundleItem::query()->create([
            'sale_id' => $bundleSale->id,
            'sale_detail_id' => $bundleDetail->id,
            'bundle_id' => 12345,
            'bundle_item_id' => 901,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'pos-0-0',
        ]);

        // Component's own real sibling SaleDetails row, scoped 1:1 to this
        // one bundle line (quantity 1, matching quantity_per_bundle 1 *
        // parent quantity 1).
        $componentSaleDetail = SaleDetails::query()->create([
            'sale_id' => $bundleSale->id,
            'product_id' => $componentProduct->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        DispatchDetail::query()->create([
            'dispatch_id' => $bundleDispatchDetail->dispatch_id,
            'sale_id' => $bundleSale->id,
            'product_id' => $componentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->secondLocation->id,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $bundleDetail->id,
                'returned_serial_id' => $returnedSerial->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
            [
                'sale_detail_id' => $plainDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));
        $this->assertNotContains('component_target_ambiguous', collect($plan['blockers'])->pluck('code')->all());

        $componentGroup = collect($plan['groups'])->first(function (array $group) {
            return ($group['source_owner']['setting_id'] ?? null) === $this->secondSetting->id;
        });
        $this->assertNotNull($componentGroup);
        $this->assertCount(1, $componentGroup['planned_details']);
        $this->assertSame(1.0, (float) $componentGroup['planned_details'][0]['quantity']);
        $this->assertSame(0.0, (float) $componentGroup['planned_details'][0]['amount']);
        $this->assertSame($componentSaleDetail->id, $componentGroup['planned_details'][0]['sale_detail_id']);
    }

    /**
     * Real intent, reinterpreted for the new model: the OLD test simulated a
     * scenario where the live POS-transaction line_meta (bundle_items) is
     * ambiguous between two candidate components (A/B sharing the same
     * bundle_id/sale_detail_id), and asserted that a persisted bundle_trace
     * override on the PosReturnLine takes precedence over a live
     * `PosTransactionLine.line_meta.bundle_items` re-derivation. Under the
     * new model there is no live component candidate matching at preview
     * time at all — Phase 2 synthesis (at submission time) is the ONLY
     * place component identity is ever established, driven strictly by real
     * SaleDetails/DispatchDetail rows per product. The equivalent,
     * still-meaningful scenario is: a bundle with TWO real, distinct
     * components (A and B) must have BOTH independently and correctly
     * synthesized as their own PosReturnLine rows (not merged, not
     * conflated) — proving multi-component bundles resolve deterministically
     * by real per-product persisted lineage, with no reliance on informational
     * PosTransactionLine.line_meta at all.
     *
     * @test
     */
    public function it_prefers_pos_transaction_bundle_lineage_before_generic_component_candidate_matching(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BND-LINEAGE-PARENT-' . uniqid(),
            'product_name' => 'Preview POS Lineage Parent',
            'serial_number_required' => true,
            'sale_price' => 5000,
        ]);
        $componentA = $this->createStockedProduct($this->secondSetting, $this->secondLocation, [
            'product_code' => 'BND-LINEAGE-A-' . uniqid(),
            'product_name' => 'Preview POS Lineage A',
            'sale_price' => 0,
        ]);
        $componentB = $this->createStockedProduct($this->secondSetting, $this->secondLocation, [
            'product_code' => 'BND-LINEAGE-B-' . uniqid(),
            'product_name' => 'Preview POS Lineage B',
            'sale_price' => 0,
        ]);
        $returnedSerial = $this->createSerialNumber($parentProduct, $this->location, 'SN-BND-LINEAGE-' . uniqid());

        [$parentSale, $parentDetail, $parentDispatchDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'BNDLINP',
            $parentProduct,
            ['quantity' => 1, 'sub_total' => 5000, 'unit_price' => 5000, 'price' => 5000]
        );
        $returnedSerial->update([
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $componentSale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 800,
            'paid_amount' => 800,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-BNDLINC-' . uniqid(),
        ]);

        PosCheckoutSale::query()->create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $componentSale->id,
            'source_setting_id' => $this->secondSetting->id,
            'source_location_id' => $this->secondLocation->id,
            'grand_total' => 800,
            'split_key' => 'SPLIT-BNDLINC-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        SaleBundleItem::query()->create([
            'sale_id' => $parentSale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 8801,
            'bundle_item_id' => 88011,
            'product_id' => $componentA->id,
            'name' => $componentA->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'pos-0-0',
        ]);
        SaleBundleItem::query()->create([
            'sale_id' => $parentSale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 8801,
            'bundle_item_id' => 88012,
            'product_id' => $componentB->id,
            'name' => $componentB->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'pos-0-1',
        ]);

        // Each component's own real sibling SaleDetails row (unit_price = 0)
        // + its own DispatchDetail — this is the ONLY lineage Phase 2
        // synthesis uses; no live PosTransactionLine.line_meta candidate
        // matching exists under the new model.
        $componentADetail = SaleDetails::query()->create([
            'sale_id' => $parentSale->id,
            'product_id' => $componentA->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $componentA->product_name,
            'product_code' => $componentA->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $componentBDetail = SaleDetails::query()->create([
            'sale_id' => $parentSale->id,
            'product_id' => $componentB->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $componentB->product_name,
            'product_code' => $componentB->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatchDetail->dispatch_id,
            'sale_id' => $parentSale->id,
            'product_id' => $componentA->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->secondLocation->id,
        ]);
        DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatchDetail->dispatch_id,
            'sale_id' => $parentSale->id,
            'product_id' => $componentB->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->secondLocation->id,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $parentDetail->id,
                'returned_serial_id' => $returnedSerial->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));
        $componentGroup = collect($plan['groups'])->first(function (array $group) {
            return ($group['source_owner']['setting_id'] ?? null) === $this->secondSetting->id;
        });
        $this->assertNotNull($componentGroup);
        $this->assertCount(2, $componentGroup['planned_details']);

        $componentProductIds = collect($componentGroup['planned_details'])->pluck('product_id')->sort()->values()->all();
        $this->assertSame(
            collect([$componentA->id, $componentB->id])->sort()->values()->all(),
            $componentProductIds
        );

        $detailA = collect($componentGroup['planned_details'])->firstWhere('product_id', $componentA->id);
        $detailB = collect($componentGroup['planned_details'])->firstWhere('product_id', $componentB->id);
        $this->assertSame($componentADetail->id, $detailA['sale_detail_id']);
        $this->assertSame($componentBDetail->id, $detailB['sale_detail_id']);
    }

    /** @test */
    public function it_plans_mixed_same_sale_and_split_sale_bundle_components_when_lineage_is_unique(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BND-MIX-PARENT-' . uniqid(),
            'product_name' => 'Preview Mixed Bundle Parent',
            'sale_price' => 5000,
        ]);
        $sameSaleComponent = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BND-MIX-SAME-' . uniqid(),
            'product_name' => 'Preview Mixed Same Sale Component',
            'sale_price' => 0,
        ]);
        $splitSaleComponent = $this->createStockedProduct($this->secondSetting, $this->secondLocation, [
            'product_code' => 'BND-MIX-SPLIT-' . uniqid(),
            'product_name' => 'Preview Mixed Split Sale Component',
            'sale_price' => 0,
        ]);
        [$parentSale, $parentDetail, $parentDispatchDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'BNDMIXP',
            $parentProduct,
            ['quantity' => 1, 'sub_total' => 5000, 'unit_price' => 5000, 'price' => 5000]
        );

        $sameSaleBundleItem = SaleBundleItem::query()->create([
            'sale_id' => $parentSale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 9901,
            'bundle_item_id' => 99011,
            'product_id' => $sameSaleComponent->id,
            'name' => $sameSaleComponent->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'pos-0-0',
        ]);
        SaleBundleItem::query()->create([
            'sale_id' => $parentSale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 9901,
            'bundle_item_id' => 99012,
            'product_id' => $splitSaleComponent->id,
            'name' => $splitSaleComponent->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'pos-0-1',
        ]);

        // Same-sale component's own real sibling SaleDetails row + its own
        // DispatchDetail from the same owner/location as the parent.
        $sameSaleComponentDetail = SaleDetails::query()->create([
            'sale_id' => $parentSale->id,
            'product_id' => $sameSaleComponent->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $sameSaleComponent->product_name,
            'product_code' => $sameSaleComponent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatchDetail->dispatch_id,
            'sale_id' => $parentSale->id,
            'product_id' => $sameSaleComponent->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        // Split-sale component's own real sibling SaleDetails row, same
        // sale_id as the parent (synthesis keys strictly on sale_id +
        // product_id + unit_price===0.0, never on checkout-sale ownership),
        // but its OWN DispatchDetail from the split owner's location.
        $splitComponentDetail = SaleDetails::query()->create([
            'sale_id' => $parentSale->id,
            'product_id' => $splitSaleComponent->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $splitSaleComponent->product_name,
            'product_code' => $splitSaleComponent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatchDetail->dispatch_id,
            'sale_id' => $parentSale->id,
            'product_id' => $splitSaleComponent->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->secondLocation->id,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $parentDetail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));

        $parentGroup = collect($plan['groups'])->first(function (array $group) {
            return ($group['source_owner']['setting_id'] ?? null) === $this->setting->id;
        });
        $this->assertNotNull($parentGroup);

        $sameSaleComponentDetailPlan = collect($parentGroup['planned_details'])
            ->firstWhere('sale_detail_id', $sameSaleComponentDetail->id);

        $this->assertNotNull($sameSaleComponentDetailPlan);
        $this->assertSame('component', $sameSaleComponentDetailPlan['row_type']);
        $this->assertSame($sameSaleComponent->id, $sameSaleComponentDetailPlan['product_id']);
        $this->assertSame(1.0, (float) $sameSaleComponentDetailPlan['quantity']);
        $this->assertSame($this->setting->id, $sameSaleComponentDetailPlan['source_setting_id']);
        $this->assertSame($this->location->id, $sameSaleComponentDetailPlan['source_location_id']);
        $this->assertSame(PosReturnLine::RESOLUTION_CASH_RETURN, $sameSaleComponentDetailPlan['resolution']);

        $splitGroup = collect($plan['groups'])->first(function (array $group) {
            return ($group['source_owner']['setting_id'] ?? null) === $this->secondSetting->id;
        });
        $this->assertNotNull($splitGroup);
        $this->assertCount(1, $splitGroup['planned_details']);
        $this->assertSame($splitSaleComponent->id, $splitGroup['planned_details'][0]['product_id']);
        $this->assertSame($splitComponentDetail->id, $splitGroup['planned_details'][0]['sale_detail_id']);
    }

    /**
     * Reinterpreted for the new model: the OLD `component_target_missing`
     * blocker was surfaced by the planner's live candidate-matching
     * machinery (buildComponentEntries(), removed this session) when no
     * SaleBundleItem candidate could be found for an informational
     * bundle_trace entry. That machinery no longer exists — component
     * identity is established ONLY by Phase 2 synthesis
     * (PosReturnSubmissionService::synthesizeBundleCashReturnComponents())
     * AT SUBMISSION TIME, which requires a real, persisted zero-price
     * component SaleDetails sibling row. When that sibling row is missing
     * entirely (as in this fixture — the SaleBundleItem exists, but no
     * corresponding component SaleDetails row does), synthesis finds nothing
     * to synthesize and `assertBundleCashReturnCompleteness()` rejects the
     * whole submission before a PosReturn is even created — there is no
     * later planner-preview stage to reach.
     *
     * However, empirically, `assertBundleCashReturnCompleteness()` only
     * THROWS when a bundle parent WAS matched to at least one resolvable
     * component allocation but not all of them (a partial match). When NO
     * component SaleDetails sibling exists at all for a bundle parent (as in
     * this fixture — the SaleBundleItem row is purely informational, with no
     * real allocation anywhere), the parent/components mapping never gets
     * built for that bundle in the first place, so completeness has nothing
     * to compare and does not throw — `store()` succeeds, but produces ONLY
     * the parent PosReturnLine (no component line is ever synthesized,
     * matching the task's documented silent-no-op behavior). This is the
     * actual new-model analogue of the old "component target missing"
     * blocker: not a rejection, but a plan that renders a single parent-only
     * group with no component allocation at all.
     *
     * @test
     */
    public function it_reports_a_blocker_when_a_bundle_component_target_is_missing(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'MISS-PARENT-' . uniqid(),
            'product_name' => 'Missing Component Parent',
            'serial_number_required' => true,
            'sale_price' => 1500,
        ]);
        $componentProduct = $this->createStockedProduct($this->secondSetting, $this->secondLocation, [
            'product_code' => 'MISS-COMP-' . uniqid(),
            'product_name' => 'Missing Component Product',
            'sale_price' => 0,
        ]);
        $returnedSerial = $this->createSerialNumber($parentProduct, $this->location, 'SN-MISS-' . uniqid());

        [$parentSale, $parentDetail, $parentDispatchDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'MISSP',
            $parentProduct,
            ['serial_number_ids' => [$returnedSerial->id], 'sub_total' => 1500, 'unit_price' => 1500, 'price' => 1500]
        );
        $returnedSerial->update([
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SaleBundleItem::query()->create([
            'sale_id' => $parentSale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 601,
            'bundle_item_id' => 801,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'pos-0-0',
        ]);

        // Deliberately NO component SaleDetails sibling row and NO component
        // DispatchDetail — the SaleBundleItem row above is purely
        // informational, with no real allocation Phase 2 synthesis can find.

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $parentDetail->id,
                'returned_serial_id' => $returnedSerial->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        // No component line was synthesized (nothing to find) — only the
        // parent line persists.
        $this->assertEquals(1, $posReturn->lines()->count());

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));
        $this->assertCount(1, $plan['groups']);
        $this->assertSame('parent', $plan['groups'][0]['planned_details'][0]['row_type']);
    }

    /**
     * Reinterpreted for the new model: the OLD `component_target_ambiguous`
     * blocker was surfaced by the planner's removed live candidate-matching
     * machinery when MULTIPLE informational SaleBundleItem rows across
     * different sales matched the same bundle_id/product_id with no unique
     * sale_detail_id to disambiguate. Under the new model, Phase 2
     * synthesis's lookup for the component's own real SaleDetails row is a
     * single `first()` on sale_id (the SAME sale_id as the parent) +
     * product_id + unit_price===0.0 — it can never see "multiple candidate
     * sales" because it only ever looks within the parent's own sale. There
     * is no way to reconstruct a genuine multi-candidate ambiguity at
     * synthesis time from real persisted data; the two "candidate"
     * SaleBundleItem rows on other, unrelated component Sales are simply
     * invisible to synthesis and irrelevant. Empirically (as with the
     * "missing" test above), a bundle parent with no resolvable component
     * allocation on its OWN sale does not throw — it produces a parent-only
     * PosReturn/plan, since `assertBundleCashReturnCompleteness()` never
     * builds a parent-to-components mapping for a bundle it finds no
     * component allocation for at all.
     *
     * @test
     */
    public function it_reports_a_blocker_when_a_bundle_component_target_is_ambiguous(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'AMB-PARENT-' . uniqid(),
            'product_name' => 'Ambiguous Component Parent',
            'serial_number_required' => true,
            'sale_price' => 1500,
        ]);
        $componentProduct = $this->createStockedProduct($this->secondSetting, $this->secondLocation, [
            'product_code' => 'AMB-COMP-' . uniqid(),
            'product_name' => 'Ambiguous Component Product',
            'sale_price' => 0,
        ]);
        $returnedSerial = $this->createSerialNumber($parentProduct, $this->location, 'SN-AMB-' . uniqid());

        [$parentSale, $parentDetail, $parentDispatchDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'AMBP',
            $parentProduct,
            ['serial_number_ids' => [$returnedSerial->id], 'sub_total' => 1500, 'unit_price' => 1500, 'price' => 1500]
        );
        $returnedSerial->update([
            'dispatch_detail_id' => $parentDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SaleBundleItem::query()->create([
            'sale_id' => $parentSale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 701,
            'bundle_item_id' => 901,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'pos-0-0',
        ]);

        // Two UNRELATED "candidate" component sales — under the new model
        // these are invisible to synthesis (which only ever looks at the
        // parent's own sale_id), so they cannot produce genuine ambiguity.
        // They remain here only to prove they have no effect, and the real
        // component SaleDetails sibling row on the PARENT's own sale is still
        // simply missing, which is what actually blocks submission.
        foreach (['A', 'B'] as $suffix) {
            $componentSale = Sale::query()->create([
                'setting_id' => $this->setting->id,
                'customer_id' => null,
                'customer_name' => 'Walk-in Customer',
                'total_amount' => 0,
                'paid_amount' => 0,
                'due_amount' => 0,
                'date' => now()->toDateString(),
                'status' => 'DISPATCHED',
                'payment_status' => 'PAID',
                'payment_method' => 'CASH',
                'reference' => 'SO-AMBC-' . $suffix . '-' . uniqid(),
            ]);

            PosCheckoutSale::query()->create([
                'pos_checkout_id' => $checkout->id,
                'sale_id' => $componentSale->id,
                'source_setting_id' => $this->secondSetting->id,
                'source_location_id' => $this->secondLocation->id,
                'grand_total' => 0,
                'split_key' => 'SPLIT-AMBC-' . $suffix . '-' . uniqid(),
                'tax_bucket' => 'NON_TAX',
            ]);

            SaleBundleItem::query()->create([
                'sale_id' => $componentSale->id,
                'sale_detail_id' => null,
                'bundle_id' => 701,
                'bundle_item_id' => 901,
                'product_id' => $componentProduct->id,
                'name' => $componentProduct->product_name,
                'quantity' => 1,
                'price' => 0,
                'sub_total' => 0,
                'tax_id' => null,
                'tax_amount' => 0,
                'line_group_key' => 'standalone-701-' . $componentProduct->id,
            ]);
        }

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $parentDetail->id,
                'returned_serial_id' => $returnedSerial->id,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        // The two unrelated "candidate" sales have no bearing at all — only
        // the parent line persists, since no component allocation could be
        // found on the parent's own sale.
        $this->assertEquals(1, $posReturn->lines()->count());

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));
        $this->assertCount(1, $plan['groups']);
        $this->assertSame('parent', $plan['groups'][0]['planned_details'][0]['row_type']);
    }

    /** @test */
    public function it_blocks_final_approval_when_a_bundle_component_line_exists_without_its_parent_line(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'ORPHAN-PARENT-' . uniqid(),
            'product_name' => 'Orphan Parent Product',
            'sale_price' => 900,
        ]);
        $componentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'ORPHAN-COMP-' . uniqid(),
            'product_name' => 'Orphan Component Product',
            'sale_price' => 0,
        ]);

        [$sale, $parentDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'ORPHANP', $parentProduct, [
            'quantity' => 2,
            'sub_total' => 1800,
            'unit_price' => 900,
            'price' => 900,
        ]);

        $componentDetail = SaleDetails::query()->create([
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

        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $parentDetail->id,
            'quantity' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]]);

        $parentLine = $posReturn->fresh('lines')->lines->first();
        $parentLine->delete();

        PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $parentLine->pos_checkout_sale_id,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentDetail->id,
            'dispatch_detail_id' => null,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'tax_id' => null,
            'product_id' => $componentProduct->id,
            'product_name' => $componentProduct->product_name,
            'product_code' => $componentProduct->product_code,
            'quantity' => 2,
            'unit_price' => 0,
            'line_total' => 0,
            'expected_cash_amount' => 0,
            'bundle_group_key' => 'ORPHAN-BUNDLE-' . uniqid(),
            'bundle_parent_sale_detail_id' => $parentDetail->id,
            'bundle_quantity' => 1,
            'component_quantity_per_bundle' => 2,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $this->assertContains('bundle_parent_missing', collect($plan['blockers'])->pluck('code')->all());
    }

    /**
     * Real intent, preserved: a PARTIAL parent return (quantity 1 of an
     * originally-purchased quantity 2) must still synthesize the component
     * proportionally (quantity_per_bundle 2 * returned parent quantity 1 =
     * 2), not the component's full original total (4). Under the new model
     * this proportional math happens inside Phase 2 synthesis
     * (PosReturnSubmissionService::synthesizeBundleCashReturnComponents())
     * at submission time, using the real persisted SaleBundleItem.quantity
     * (4, i.e. quantity_per_bundle 2 across original parent quantity 2) and
     * the ACTUAL submitted parent quantity — not a hand-set
     * line_meta.bundle_trace override, which the new planner never reads for
     * component identity/quantity. Rewritten to let real synthesis compute
     * the proportional quantity from persisted data.
     *
     * @test
     */
    public function it_calculates_proportional_bundle_component_quantities_for_partial_parent_returns(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $parentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PARTIAL-PARENT-' . uniqid(),
            'product_name' => 'Partial Parent Product',
            'sale_price' => 900,
        ]);
        $componentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PARTIAL-COMP-' . uniqid(),
            'product_name' => 'Partial Component Product',
            'sale_price' => 0,
        ]);

        [$sale, $parentDetail, $parentDispatchDetail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'PARTIALP', $parentProduct, [
            'quantity' => 2,
            'sub_total' => 1800,
            'unit_price' => 900,
            'price' => 900,
        ]);

        SaleBundleItem::query()->create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => 1771,
            'bundle_item_id' => 2771,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'partial-0-0',
        ]);

        // Component's own real sibling SaleDetails row, quantity 4 (matching
        // the total component quantity for the FULL original bundle line:
        // quantity_per_bundle 2 * original parent quantity 2).
        $componentDetail = SaleDetails::query()->create([
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

        DispatchDetail::query()->create([
            'dispatch_id' => $parentDispatchDetail->dispatch_id,
            'sale_id' => $sale->id,
            'product_id' => $componentProduct->id,
            'dispatched_quantity' => 4,
            'location_id' => $this->location->id,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $parentDetail->id,
            'quantity' => 1,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
        ]]);

        $plan = $this->planner->plan($posReturn->fresh());
        $parentGroup = collect($plan['groups'])->firstWhere('source_sale.id', $sale->id);
        $plannedComponentDetail = collect($parentGroup['planned_details'] ?? [])
            ->firstWhere('sale_detail_id', $componentDetail->id);

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers'] ?? []));
        $this->assertNotNull($plannedComponentDetail);
        $this->assertSame(2.0, (float) $plannedComponentDetail['quantity']);
        $this->assertSame(2.0, (float) $plannedComponentDetail['component_quantity_per_bundle']);
    }

    /** @test */
    public function it_does_not_warn_about_legacy_header_return_option_when_line_intent_is_resolvable(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'WARN-' . uniqid(),
            'product_name' => 'Warning Preview Product',
            'serial_number_required' => true,
        ]);
        $serial = $this->createSerialNumber($product, $this->location, 'SN-WARN-' . uniqid());
        $replacement = $this->createSerialNumber($product, $this->location, 'SN-WARN-R-' . uniqid());
        [, $detail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'WARN', $product, [
            'serial_number_ids' => [$serial->id],
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detail->id,
                'returned_serial_id' => $serial->id,
                'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                'replacement_serial_id' => $replacement->id,
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked']);
        $this->assertNotContains('header_option_mismatch', collect($plan['warnings'])->pluck('code')->all());
    }

    /** @test */
    public function it_reports_snapshot_drift_as_blocker_without_mutating(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [, $detail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'DRIFT');
        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $detail->update(['quantity' => 2, 'sub_total' => 1200]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $this->assertContains('snapshot_drift', collect($plan['blockers'])->pluck('code')->all());
        $this->assertDatabaseHas('pos_returns', [
            'id' => $posReturn->id,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
        ]);
    }

    /** @test */
    public function it_reports_live_source_identity_mismatch_as_blocker(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();
        [$sale, $detail] = $this->createSaleGraph($checkout, $this->setting->id, $this->location->id, 'IDENT');
        $posReturn = $this->makePendingReturn($transaction->id, [
            [
                'sale_detail_id' => $detail->id,
                'quantity' => 1,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            ],
        ]);

        $otherProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'IDENT-OTHER-' . uniqid(),
            'product_name' => 'Identity Drift Product',
            'sale_price' => 600,
        ]);
        $detail->update([
            'product_id' => $otherProduct->id,
            'product_name' => $otherProduct->product_name,
            'product_code' => $otherProduct->product_code,
        ]);

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($plan['is_blocked']);
        $this->assertContains('source_identity_mismatch', collect($plan['blockers'])->pluck('code')->all());
    }

    /** @test */
    public function it_uses_source_sale_detail_commercial_amount_for_bundled_replacement_preview(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();

        // Parent product sale price = 1500 (POS bundle list price)
        // After split posting with bundle components, the sale detail unit_price
        // should be the parent residual (e.g. 1000) not the bundle list price (1500).
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BND-REPL-VAL-' . uniqid(),
            'product_name' => 'Bundled Replacement Valuation Product',
            'serial_number_required' => true,
            'sale_price' => 1500,
        ]);
        $returnedSerial = $this->createSerialNumber($product, $this->location, 'SN-BND-REPL-RET-' . uniqid());
        $replacementSerial = $this->createSerialNumber($product, $this->secondLocation, 'SN-BND-REPL-REP-' . uniqid());

        // The source sale detail has unit_price=1000 (parent residual after split),
        // which differs from the POS bundle list price of 1500.
        [$sale, $detail, $dispatchDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'BNDVAL',
            $product,
            [
                'serial_number_ids' => [$returnedSerial->id],
                'sub_total' => 1000,
                'unit_price' => 1000,
                'price' => 1000,
            ]
        );
        $componentProduct = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'BND-REPL-COMP-' . uniqid(),
            'product_name' => 'Bundled Replacement Component',
            'sale_price' => 0,
        ]);
        SaleBundleItem::query()->create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => 9101,
            'bundle_item_id' => 9201,
            'product_id' => $componentProduct->id,
            'name' => $componentProduct->product_name,
            'quantity' => 1,
            'price' => 500,
            'sub_total' => 500,
            'tax_id' => null,
            'tax_amount' => 0,
            'line_group_key' => 'replacement-0-0',
        ]);
        $returnedSerial->update([
            'dispatch_detail_id' => $dispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $customer = \Modules\People\Entities\Customer::factory()->create(['setting_id' => $this->setting->id]);
        $sale->update([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
        ]);

        // The POS return line stores the original bundle list price (1500)
        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $detail->id,
            'returned_serial_id' => $returnedSerial->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'replacement_serial_id' => $replacementSerial->id,
        ]]);

        // Override the line_total to simulate the POS bundle list price
        $posReturn->lines()->update([
            'line_total' => 1500,
            'line_meta' => [
                'bundle_trace' => [[
                    'product_id' => $componentProduct->id,
                    'quantity_per_bundle' => 1,
                    'total_component_quantity' => 1,
                ]],
            ],
        ]);

        $plan = $this->planner->plan($posReturn->fresh());
        $detailPlan = $plan['groups'][0]['planned_details'][0];

        $this->assertFalse($plan['is_blocked'], json_encode($plan));

        // The canonical amount must be derived from the source sale detail (1000),
        // not the POS return line_total (1500).
        $this->assertSame(1000.0, (float) $detailPlan['amount'],
            'Preview amount should use source sale detail commercial amount, not POS bundle list price');

        // For cross-owner replacement, the generated sale effects should also use the canonical amount
        if ($detailPlan['execution_mode'] === 'cross_owner_replacement') {
            $this->assertSame(1000.0, (float) data_get($detailPlan, 'generated_replacement_sale_effects.payment_amount'),
                'Cross-owner payment amount should use source sale detail commercial amount');
            $this->assertSame(1000.0, (float) $detailPlan['original_sale_correction_amount'],
                'Cross-owner correction amount should use source sale detail commercial amount');
        }
    }

    /** @test */
    public function it_keeps_pos_return_line_amount_for_non_bundled_replacement_preview(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);

        [$transaction, $checkout] = $this->createTransactionWithCheckout();

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'STD-REPL-VAL-' . uniqid(),
            'product_name' => 'Standard Replacement Valuation Product',
            'serial_number_required' => true,
            'sale_price' => 1500,
        ]);
        $returnedSerial = $this->createSerialNumber($product, $this->location, 'SN-STD-REPL-RET-' . uniqid());
        $replacementSerial = $this->createSerialNumber($product, $this->secondLocation, 'SN-STD-REPL-REP-' . uniqid());

        [$sale, $detail, $dispatchDetail] = $this->createSaleGraph(
            $checkout,
            $this->setting->id,
            $this->location->id,
            'STDVAL',
            $product,
            [
                'serial_number_ids' => [$returnedSerial->id],
                'sub_total' => 1000,
                'unit_price' => 1000,
                'price' => 1000,
            ]
        );
        $returnedSerial->update([
            'dispatch_detail_id' => $dispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $customer = \Modules\People\Entities\Customer::factory()->create(['setting_id' => $this->setting->id]);
        $sale->update([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
        ]);

        $posReturn = $this->makePendingReturn($transaction->id, [[
            'sale_detail_id' => $detail->id,
            'returned_serial_id' => $returnedSerial->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'replacement_serial_id' => $replacementSerial->id,
        ]]);

        $posReturn->lines()->update(['line_total' => 1500]);

        $plan = $this->planner->plan($posReturn->fresh());
        $detailPlan = $plan['groups'][0]['planned_details'][0];

        $this->assertFalse($plan['is_blocked'], json_encode($plan));
        $this->assertSame(1500.0, (float) $detailPlan['amount'],
            'Non-bundled replacement preview should keep the POS return line amount');
    }

    protected function createTransactionWithCheckout(): array
    {
        $transaction = PosTransaction::query()->create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-PREVIEW-' . uniqid(),
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
            'receipt_number' => 'RCP-PREVIEW-' . uniqid(),
            'idempotency_key' => 'IDEM-PREVIEW-' . uniqid(),
            'payload_hash' => 'HASH-PREVIEW-' . uniqid(),
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        return [$transaction, $checkout];
    }

    protected function createSaleGraph(PosCheckout $checkout, int $sourceSettingId, int $sourceLocationId, string $suffix, $product = null, array $detailOverrides = []): array
    {
        $product = $product ?: $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-' . $suffix . '-' . uniqid(),
            'product_name' => 'Preview Product ' . $suffix,
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

        $detail = SaleDetails::query()->create(array_merge([
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
        ], $detailOverrides));

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
            'tax_id' => $detail->tax_id,
        ]);

        return [$sale, $detail, $dispatchDetail];
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

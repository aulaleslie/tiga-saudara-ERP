<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnReplacementGuard;
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

/**
 * Phase 3 (align-bundle-return-replacement-rules) — approval preview
 * planning must read the durable intent Phase 2 already persisted (real
 * component PosReturnLine rows) rather than re-derive it, and must render
 * execution-explicit, note-only-safe, single-refund-accurate preview rows.
 *
 * These tests assert on PLANNED ROWS AND GROUPS only — lifecycle execution
 * effects are Phase 4 and are exercised elsewhere
 * (POSReturnBundleComponentSerialLineageTest, POSReturnReceivingWorkflowTest).
 */
class POSReturnApprovalPreviewBundleLineageTest extends PosTransactionFeatureTestCase
{
    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

    protected PosReturnApprovalPreviewPlannerService $planner;

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
        $this->setting = $this->createSetting('Preview Bundle Lineage Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        foreach (['pos.access', 'pos.returns.create', 'pos.returns.edit', 'pos.returns.approve'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = $this->createUserForSetting($this->setting, 'Preview Bundle Lineage Clerk', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.edit',
            'pos.returns.approve',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /**
     * @return array{0: PosTransaction, 1: SaleDetails, 2: SaleDetails, 3: SaleDetails, 4: Sale, 5: array<int, \Modules\Product\Entities\ProductSerialNumber>}
     */
    protected function createSerialBundlePurchase(string $suffix = ''): array
    {
        $serialComp = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Preview Serial Component' . $suffix,
            'product_code' => 'PVSERCOMP' . $suffix,
            'serial_number_required' => true,
            'stock_qty' => 30,
        ]);
        $plainComp = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Preview Plain Component' . $suffix,
            'product_code' => 'PVPLAINCOMP' . $suffix,
            'stock_qty' => 30,
        ]);
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Preview Serial Bundle Parent' . $suffix,
            'product_code' => 'PVSBPARENT' . $suffix,
            'sale_price' => 1000000,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Preview Serial Bundle Header' . $suffix,
            'is_active' => true,
        ]);

        $biSerial = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $serialComp->id, 'quantity' => 1]);
        $biPlain = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $plainComp->id, 'quantity' => 1]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-PVSBUNDLE' . $suffix,
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
            'grand_total' => 1000000,
            'receipt_number' => 'RCP-PVSBUNDLE' . $suffix,
            'idempotency_key' => 'IDEM-PVSBUNDLE' . $suffix,
            'payload_hash' => 'HASH-PVSBUNDLE' . $suffix,
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 1000000,
            'paid_amount' => 1000000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-PVSBUNDLE' . $suffix,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000000,
            'split_key' => 'SPLIT-PVSBUNDLE' . $suffix,
            'tax_bucket' => 'NON_TAX',
        ]);

        $parentDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'quantity' => 1,
            'price' => 1000000,
            'unit_price' => 1000000,
            'sub_total' => 1000000,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $biSerial->id,
            'product_id' => $serialComp->id,
            'name' => $serialComp->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $biPlain->id,
            'product_id' => $plainComp->id,
            'name' => $plainComp->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        $serialCompDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $serialComp->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $serialComp->product_name,
            'product_code' => $serialComp->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $plainCompDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $plainComp->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $plainComp->product_name,
            'product_code' => $plainComp->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        $serialDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $serialComp->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $plainComp->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $originalSerial = $this->createSerialNumber($serialComp, $this->location, 'SN-PV-' . $suffix . '-ORIG-1');
        $originalSerial->update(['dispatch_detail_id' => $serialDispatchDetail->id, 'status' => 'SOLD']);

        return [$transaction, $parentDetail, $serialCompDetail, $plainCompDetail, $sale, [$originalSerial]];
    }

    /** @test */
    public function it_shows_parent_and_serial_component_replacement_lineage_with_both_owner_locations(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, , $serialCompDetail, , $sale, $originalSerials] = $this->createSerialBundlePurchase('-3-2A');
        $originalSerial = $originalSerials[0];
        $replacementSerial = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-3-2A-REPL');

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $serialCompDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $originalSerial->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    'replacement_serial_id' => $replacementSerial->id,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked']);
        $componentDetail = collect($plan['groups'])->first()['planned_details'][0];

        // Replaceability follows the physical product: an independent bundle
        // component replacement submitted entirely on its own (no
        // accompanying parent cash_return/replacement line in this same
        // PosReturn) is a standalone actionable line — the submitted
        // PosReturnLine's own SaleDetail (the component's) carries no bundle
        // items of its own, so bundle_parent_sale_detail_id is never set and
        // this renders as row_type 'parent' (i.e. "the only row"), matching
        // the actual identity of what was submitted: this line does not
        // require any other line in the same POS return to be valid.
        $this->assertSame('parent', $componentDetail['row_type']);
        $this->assertSame('product_replacement', $componentDetail['resolution']);
        $this->assertSame($originalSerial->serial_number, $componentDetail['returned_serial']);
        $this->assertSame($replacementSerial->serial_number, $componentDetail['replacement_serial']);
        $this->assertSame('serial_inventory_replacement', $componentDetail['replacement_kind']);
        // execution_mode (same/cross owner) is a distinct concept from
        // replacement_kind (serial vs note-only) — same owner here.
        $this->assertSame('same_owner_replacement', $componentDetail['execution_mode']);
        $this->assertSame($this->setting->id, $componentDetail['replacement_serial_owner_setting_id']);
        $this->assertSame($this->location->id, $componentDetail['replacement_serial_location_id']);
        $this->assertSame($this->setting->id, $componentDetail['source_setting_id']);
        $this->assertSame($this->location->id, $componentDetail['source_location_id']);
        $this->assertSame(0.0, (float) $componentDetail['cash_return_amount']);
    }

    /** @test */
    public function it_shows_non_serial_replacement_as_note_only_with_zero_effects_and_no_dispatch_blocker(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, , , $plainCompDetail, $sale] = $this->createSerialBundlePurchase('-3-3A');

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $plainCompDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    'replacement_reason' => 'Warna berbeda dari pesanan',
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers']));
        $detail = collect($plan['groups'])->first()['planned_details'][0];

        $this->assertSame('non_serial_note_only', $detail['replacement_kind']);
        $this->assertSame(0.0, (float) $detail['cash_return_amount']);
        $this->assertSame('tidak_ada_mutasi_stok_catatan_saja', $detail['stock_movement_intent']);
        $this->assertSame('tidak_ada_mutasi_serial_catatan_saja', $detail['serial_movement_intent']);
        $this->assertSame('catatan_audit_saja_tanpa_mutasi_fisik', $detail['replacement_effect']);
        $this->assertSame('not_applicable_note_only', $detail['dispatch_resolution']);
        $this->assertNull($detail['dispatch_detail_id']);

        // A missing/unresolved physical dispatch target must never block a
        // note-only replacement.
        $this->assertNotContains('dispatch_missing', collect($plan['blockers'])->pluck('code')->all());
        $this->assertNotContains('dispatch_ambiguous', collect($plan['blockers'])->pluck('code')->all());

        $this->assertContains('note_only_manual_exchange_required', collect($plan['info'])->pluck('code')->all());
    }

    /** @test */
    public function it_groups_whole_bundle_cash_return_into_one_refund_group_when_all_lines_share_one_owner(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, $plainCompDetail, $sale, $originalSerials] = $this->createSerialBundlePurchase('-3-4A');

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers']));
        $this->assertCount(1, $plan['groups']);

        $group = $plan['groups'][0];
        $this->assertCount(3, $group['planned_details']); // parent + serial component + plain component
        $this->assertSame(1000000.0, (float) $group['planned_header']['cash_return_total']);

        // Every component detail must carry zero cash_return_amount — the
        // parent alone carries the whole-bundle refund value.
        foreach ($group['planned_details'] as $detail) {
            if ($detail['row_type'] === 'component') {
                $this->assertSame(0.0, (float) $detail['cash_return_amount']);
            }
        }
    }

    /**
     * Cross-owner grouping: when a component was dispatched from a
     * DIFFERENT setting/location than the parent, preview must place it in
     * its OWN group (keyed by its own persisted source_setting_id/
     * source_location_id), never merged into the parent's group. The
     * component's group must show a ZERO cash_return_total (component
     * allocations carry no separate refund value) while the parent's group
     * alone carries the full, single, whole-bundle refund amount — proving
     * the refund is never duplicated or split across groups.
     *
     * @test
     */
    public function it_places_a_cross_owner_component_in_its_own_group_with_zero_refund_while_the_parent_group_carries_the_whole_refund(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, $plainCompDetail, $sale] = $this->createSerialBundlePurchase('-3-CROSSOWNER');

        // A second, distinct owner/location that the plain (non-serial)
        // component was actually dispatched from — mirrors Phase 2's
        // cross-owner lineage fixture pattern.
        $otherSetting = $this->createSetting('Preview Cross Owner Test Setting');
        [, $otherLocation] = $this->createTerminalWithLocation($otherSetting);

        DispatchDetail::where('sale_id', $sale->id)
            ->where('product_id', $plainCompDetail->product_id)
            ->update(['location_id' => $otherLocation->id]);

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $plan = $this->planner->plan($posReturn->fresh());

        $this->assertFalse($plan['is_blocked'], json_encode($plan['blockers']));
        $this->assertCount(2, $plan['groups']);

        $parentGroup = collect($plan['groups'])->first(
            fn (array $group) => $group['source_owner']['setting_id'] === $this->setting->id
        );
        $crossOwnerGroup = collect($plan['groups'])->first(
            fn (array $group) => $group['source_owner']['setting_id'] === $otherSetting->id
        );

        $this->assertNotNull($parentGroup);
        $this->assertNotNull($crossOwnerGroup);

        // Parent's group: parent line + serial component (same owner) — carries
        // the full, single whole-bundle refund.
        $this->assertSame(1000000.0, (float) $parentGroup['planned_header']['cash_return_total']);

        // Cross-owner group: only the plain component — zero refund, since
        // component allocations never carry separate refund value.
        $this->assertSame(0.0, (float) $crossOwnerGroup['planned_header']['cash_return_total']);
        $this->assertCount(1, $crossOwnerGroup['planned_details']);
        $this->assertSame('component', $crossOwnerGroup['planned_details'][0]['row_type']);
        $this->assertSame((int) $plainCompDetail->id, $crossOwnerGroup['planned_details'][0]['sale_detail_id']);
        $this->assertSame($otherLocation->id, $crossOwnerGroup['source_location']['location_id']);
    }

    /** @test */
    public function it_surfaces_an_actionable_blocker_when_a_component_replacement_goes_in_flight_after_submission(): void
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, , $sale, $originalSerials] = $this->createSerialBundlePurchase('-3-5A');
        $originalSerial = $originalSerials[0];

        // Submit the whole-bundle cash return first — at this point there is
        // no in-flight replacement anywhere, so this must succeed and the
        // persisted component line's returned_serial_id is the original serial.
        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $initialPlan = $this->planner->plan($posReturn->fresh());
        $this->assertFalse($initialPlan['is_blocked'], json_encode($initialPlan['blockers']));

        // Now, AFTER this whole-bundle return is already pending_approval, a
        // separate PosReturn starts an in-flight (approved, not yet
        // completed) product_replacement against the SAME original serial —
        // simulating a component replacement kicked off on another document
        // while this whole-bundle return still sits in pending_approval.
        $replacementSerial = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-3-5A-REPL');
        $checkoutSaleId = PosCheckoutSale::where('sale_id', $sale->id)->value('id');

        $inFlightReturn = PosReturn::create([
            'reference' => 'POSRT-INFLIGHT-' . uniqid(),
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $sale->reference,
            'receipt_number' => $sale->reference,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'total_amount' => 0,
            'created_by' => $this->user->id,
        ]);

        PosReturnLine::create([
            'pos_return_id' => $inFlightReturn->id,
            'pos_checkout_sale_id' => $checkoutSaleId,
            'sale_id' => $sale->id,
            'sale_detail_id' => $serialCompDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $serialCompDetail->product_id,
            'product_name' => $serialCompDetail->product_name,
            'product_code' => $serialCompDetail->product_code,
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'returned_serial_id' => $originalSerial->id,
            'replacement_serial_id' => $replacementSerial->id,
            'replacement_quantity' => 1,
            'expected_cash_amount' => 0,
        ]);

        // Read-only re-check: preview planning re-runs on the same, already
        // persisted, whole-bundle return and must now surface the actionable
        // component_replacement_in_flight blocker rather than silently
        // treating the stale returned_serial_id as still current.
        $rePlan = $this->planner->plan($posReturn->fresh());

        $this->assertTrue($rePlan['is_blocked']);
        $this->assertContains(
            'component_replacement_in_flight',
            collect($rePlan['blockers'])->pluck('code')->all()
        );

        // Confirm this re-check is genuinely read-only: no serial claim/lock
        // side effects — the in-flight return's own serial fields are
        // untouched and the whole-bundle return's own lines are untouched.
        $this->assertEquals(PosReturn::STATUS_APPROVED, $inFlightReturn->fresh()->status);
        $this->assertEquals(
            $originalSerial->id,
            $posReturn->fresh()->lines()->where('sale_detail_id', $serialCompDetail->id)->value('returned_serial_id')
        );
    }
}

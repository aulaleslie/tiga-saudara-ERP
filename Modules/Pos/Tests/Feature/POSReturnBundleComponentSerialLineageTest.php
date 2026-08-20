<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnApprovalPlanPersistenceService;
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
use Spatie\Permission\Models\Permission;

/**
 * Task 4.1-4.4: End-to-end bundle-component serial lineage through POS Return
 * draft -> submit -> approve -> receive, exclusivity across concurrent
 * returns, and reconciliation after receiving.
 */
class POSReturnBundleComponentSerialLineageTest extends PosTransactionFeatureTestCase
{
    protected PosReturnSubmissionService $submissionService;

    protected PosReturnSnapshotService $snapshotService;

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
        $this->setting = $this->createSetting('POS Return Bundle Component Serial Lineage');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.approve', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'POS Return Bundle Component Clerk', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.approve',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /**
     * @return array{0: PosTransaction, 1: Sale, 2: SaleDetails, 3: ProductSerialNumber, 4: \Modules\Product\Entities\Product, 5: ProductSerialNumber, 6: PosTransactionLine}
     */
    private function buildSerializedBundleComponentFixture(): array
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $comp = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serialized Component',
            'product_code' => 'COMP-SERIAL',
            'serial_number_required' => true,
            'stock_qty' => 5,
        ]);
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Bundle Parent',
            'product_code' => 'BUNDLE-PARENT',
            'sale_price' => 1000000,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Serial Component Bundle',
            'is_active' => true,
        ]);
        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 1,
        ]);

        $compSerial = $this->createSerialNumber($comp, $this->location, 'SN-COMP-RETURN-1');

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-COMP-RETURN-' . uniqid(),
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
            'receipt_number' => 'RCP-COMP-RETURN-' . uniqid(),
            'idempotency_key' => 'IDEM-COMP-RETURN-' . uniqid(),
            'payload_hash' => 'HASH-COMP-RETURN-' . uniqid(),
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
            'reference' => 'SO-COMP-RETURN-' . uniqid(),
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000000,
            'split_key' => 'SPLIT-COMP-RETURN-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
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
            'sale_detail_id' => $saleDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);

        // Parent (non-serialized here) dispatch, separate from the component's own.
        $parentDispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $parentDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        // Component's own dispatch, carrying its own serial and bundle_id link.
        $compDispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $compDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $compDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $comp->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$compSerial->serial_number]),
        ]);
        $compSerial->update([
            'dispatch_detail_id' => $compDispatchDetail->id,
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $parent->id,
            'product_name_snapshot' => $parent->product_name,
            'product_code_snapshot' => $parent->product_code,
            'qty' => 1,
            'unit_price' => 1000000,
            'line_no' => 1,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    ['product_id' => $comp->id, 'product_name' => $comp->product_name, 'quantity' => 1],
                ],
            ],
        ]);

        return [$transaction, $sale, $saleDetail, $compSerial, $comp, $compSerial, $ptl];
    }

    /**
     * Parent quantity 2, one dispatch, one component DispatchDetail carrying
     * BOTH component serials (C-1 for the first bundled unit, C-2 for the
     * second) — mirrors how a single dispatch chunk covers the whole line
     * quantity when everything ships from one location. There is no persisted
     * pairing that says "C-1 belongs to physical parent unit A", so a partial
     * (quantity 1) return cannot know which of C-1/C-2 to restore.
     *
     * @return array{0: PosTransaction, 1: SaleDetails, 2: ProductSerialNumber, 3: ProductSerialNumber, 4: PosTransactionLine, 5: ProductSerialNumber}
     */
    private function buildMultiUnitSerializedBundleComponentFixture(): array
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $comp = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Multi Unit Serialized Component',
            'product_code' => 'COMP-MULTI-SERIAL',
            'serial_number_required' => true,
            'stock_qty' => 5,
        ]);
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Multi Unit Bundle Parent',
            'product_code' => 'BUNDLE-PARENT-MULTI',
            'serial_number_required' => true,
            'sale_price' => 1000000,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Multi Unit Serial Component Bundle',
            'is_active' => true,
        ]);
        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 1,
        ]);

        $compSerial1 = $this->createSerialNumber($comp, $this->location, 'SN-COMP-MULTI-1');
        $compSerial2 = $this->createSerialNumber($comp, $this->location, 'SN-COMP-MULTI-2');
        $parentSerial1 = $this->createSerialNumber($parent, $this->location, 'SN-PARENT-MULTI-1');
        $parentSerial2 = $this->createSerialNumber($parent, $this->location, 'SN-PARENT-MULTI-2');

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-COMP-MULTI-' . uniqid(),
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
            'grand_total' => 2000000,
            'receipt_number' => 'RCP-COMP-MULTI-' . uniqid(),
            'idempotency_key' => 'IDEM-COMP-MULTI-' . uniqid(),
            'payload_hash' => 'HASH-COMP-MULTI-' . uniqid(),
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 2000000,
            'paid_amount' => 2000000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-COMP-MULTI-' . uniqid(),
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 2000000,
            'split_key' => 'SPLIT-COMP-MULTI-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'quantity' => 2,
            'price' => 1000000,
            'unit_price' => 1000000,
            'sub_total' => 2000000,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$parentSerial1->id, $parentSerial2->id],
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
        ]);

        $parentDispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $parentDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $parentDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$parentSerial1->serial_number, $parentSerial2->serial_number]),
        ]);
        foreach ([$parentSerial1, $parentSerial2] as $sn) {
            $sn->update([
                'dispatch_detail_id' => $parentDispatchDetail->id,
                'status' => ProductSerialNumber::STATUS_SOLD,
            ]);
        }

        // One dispatch chunk covers the whole component quantity (2) for this
        // bundle line, carrying both physical units' serials together.
        $compDispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $compDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $compDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $comp->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode([$compSerial1->serial_number, $compSerial2->serial_number]),
        ]);
        foreach ([$compSerial1, $compSerial2] as $sn) {
            $sn->update([
                'dispatch_detail_id' => $compDispatchDetail->id,
                'status' => ProductSerialNumber::STATUS_SOLD,
            ]);
        }

        $ptl = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $parent->id,
            'product_name_snapshot' => $parent->product_name,
            'product_code_snapshot' => $parent->product_code,
            'qty' => 2,
            'unit_price' => 1000000,
            'line_no' => 1,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    ['product_id' => $comp->id, 'product_name' => $comp->product_name, 'quantity' => 1],
                ],
            ],
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => $parentSerial1->serial_number,
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => $parentSerial2->serial_number,
        ]);

        return [$transaction, $saleDetail, $compSerial1, $compSerial2, $ptl, $parentSerial1];
    }

    /** @test */
    public function it_returns_a_serialized_bundle_component_end_to_end_through_draft_submit_approve_and_receive(): void
    {
        [$transaction, , $saleDetail, $compSerial, $comp] = $this->buildSerializedBundleComponentFixture();

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);

        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $plan = app(PosReturnApprovalPreviewPlannerService::class)->plan($posReturn->fresh());
        $this->assertEmpty($plan['blockers'] ?? [], 'Component serial resolution must not produce blockers: ' . json_encode($plan['blockers'] ?? []));

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);

        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_COMPLETED, $posReturn->status);

        // The component's own serial (not the parent's) must be restored to ACTIVE
        // and detached from its dispatch, with its own SALE_RETURNED history entry.
        $compSerial->refresh();
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $compSerial->status);
        $this->assertNull($compSerial->dispatch_detail_id);
        $this->assertEquals($this->location->id, $compSerial->location_id);

        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $compSerial->id,
            'event_type' => \Modules\Product\Entities\SerialNumberHistory::EVENT_SALE_RETURNED,
        ]);

        // Component stock is incremented on receiving (fixture never decremented it at
        // checkout time, so the return adds one unit on top of the initial 5).
        $compStock = ProductStock::where('product_id', $comp->id)->where('location_id', $this->location->id)->value('quantity');
        $this->assertEquals(6, $compStock);
    }

    /** @test */
    public function it_blocks_a_partial_bundle_return_from_restoring_every_component_serial_in_the_dispatch(): void
    {
        [$transaction, $saleDetail, $compSerial1, $compSerial2, $ptl, $parentSerial1] = $this->buildMultiUnitSerializedBundleComponentFixture();

        // Return only 1 of the 2 bundled parent units.
        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'pos_transaction_line_id' => $ptl->id,
                    'quantity' => 1,
                    'returned_serial_id' => $parentSerial1->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        $plan = app(PosReturnApprovalPreviewPlannerService::class)->plan($posReturn->fresh());

        $this->assertNotEmpty($plan['blockers'] ?? [], 'A partial return covering fewer units than the component dispatch holds serials for must be blocked, not silently resolved.');
        $blockerCodes = collect($plan['blockers'])->pluck('code')->all();
        $this->assertContains('component_serial_partial_return_ambiguous', $blockerCodes);

        // Persisting/executing this plan must be refused while it carries blockers.
        $this->expectException(\RuntimeException::class);
        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($posReturn->fresh(), $plan);
    }

    /** @test */
    public function it_prevents_two_returns_from_claiming_the_same_originally_fulfilled_component_serial(): void
    {
        [$transaction, , $saleDetail, $compSerial] = $this->buildSerializedBundleComponentFixture();

        // First return references the parent line's returnable identity (cash return, no explicit serial claim needed
        // at parent granularity since the component serial is resolved independently by the planner).
        $snapshot = $this->snapshotService->build($transaction->id);
        $firstReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
        $firstReturn = $this->submissionService->submitDraftForApproval($firstReturn->fresh());
        $plan = app(PosReturnApprovalPreviewPlannerService::class)->plan($firstReturn->fresh());
        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($firstReturn->id, PosReturn::OPTION_CASH_RETURN, $plan);

        // A second POS Return attempting to reference the very same parent sale_detail's
        // returned unit must be rejected once the parent line's returnable quantity is
        // exhausted (already covered by the non-serial quantity guard); this asserts that
        // the parent-line quantity guard blocks the second attempt before the component
        // side is even reached.
        $secondSnapshot = $this->snapshotService->build($transaction->id);
        $this->expectException(\Exception::class);
        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $secondSnapshot,
            'source_snapshot_hash' => $secondSnapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
    }

    /** @test */
    public function it_prevents_a_second_return_from_synchronizing_a_plan_that_claims_an_already_claimed_component_serial(): void
    {
        [$transaction, , $saleDetail, $compSerial] = $this->buildSerializedBundleComponentFixture();

        $snapshot = $this->snapshotService->build($transaction->id);
        $firstReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
        $firstReturn = $this->submissionService->submitDraftForApproval($firstReturn->fresh());
        $firstPlan = app(PosReturnApprovalPreviewPlannerService::class)->plan($firstReturn->fresh());
        $this->assertEmpty($firstPlan['blockers'] ?? []);

        // Claim the component serial by synchronizing the first return's plan
        // into persisted SaleReturnDetail rows, without yet approving/receiving.
        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($firstReturn->fresh(), $firstPlan);

        // A second, independent PosReturn for the SAME underlying PosTransaction
        // (simulating a second draft built concurrently before the first
        // return's consuming state would have been visible to its own snapshot)
        // must be blocked from synchronizing a plan that resolves to the same
        // component serial, once that serial is already claimed by another
        // pending-approval (consuming) return.
        $secondReturn = PosReturn::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $transaction->code,
            'receipt_number' => 'RCP-COMP-DUP-' . uniqid(),
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'reference' => 'POSRT-COMP-DUP-' . uniqid(),
            'total_amount' => 1000000,
        ]);
        $checkoutSale = \Modules\Pos\Entities\PosCheckoutSale::where('sale_id', $saleDetail->sale_id)->firstOrFail();

        $secondReturn->lines()->create([
            'pos_transaction_line_id' => null,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'sale_id' => $saleDetail->sale_id,
            'sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'product_id' => $saleDetail->product_id,
            'product_name' => $saleDetail->product_name,
            'product_code' => $saleDetail->product_code,
            'quantity' => 1,
            'unit_price' => $saleDetail->unit_price,
            'line_total' => $saleDetail->unit_price,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN,
            'expected_cash_amount' => 1000000,
            'line_meta' => [
                'bundle_id' => null,
                'bundle_trace' => [
                    ['product_id' => $compSerial->product_id, 'quantity_per_bundle' => 1, 'total_component_quantity' => 1],
                ],
            ],
        ]);

        $secondPlan = app(PosReturnApprovalPreviewPlannerService::class)->plan($secondReturn->fresh());
        $this->assertEmpty($secondPlan['blockers'] ?? [], 'The planner itself should still resolve the component identically: ' . json_encode($secondPlan['blockers'] ?? []));

        $this->expectException(\RuntimeException::class);
        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($secondReturn->fresh(), $secondPlan);
    }

    /** @test */
    public function it_releases_a_component_serial_claim_when_the_owning_return_is_rejected(): void
    {
        [$transaction, , $saleDetail, $compSerial] = $this->buildSerializedBundleComponentFixture();

        $product = \Modules\Product\Entities\Product::find($saleDetail->product_id);
        $product->update(['serial_number_required' => true]);
        $parentSerial = $this->createSerialNumber($product, $this->location, 'SN-PARENT-RETURN-1');
        $saleDetail->update(['serial_number_ids' => [$parentSerial->id]]);
        $dispatchDetail = DispatchDetail::where('sale_id', $saleDetail->sale_id)->where('product_id', $product->id)->first();
        $parentSerial->update(['dispatch_detail_id' => $dispatchDetail->id, 'status' => ProductSerialNumber::STATUS_SOLD]);

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $parentSerial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);
        $posReturn = $this->submissionService->submitDraftForApproval($posReturn->fresh());

        app(PosReturnLifecycleService::class)->reject($posReturn->id, 'test rejection');
        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_REJECTED, $posReturn->status);

        // A rejected return is non-consuming/inactive, so a new return for the
        // same returned_serial_id must be accepted.
        $secondSnapshot = $this->snapshotService->build($transaction->id);
        $secondReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $secondSnapshot,
            'source_snapshot_hash' => $secondSnapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'returned_serial_id' => $parentSerial->id,
                    'resolution' => PosReturn::OPTION_CASH_RETURN,
                ],
            ],
        ]);

        $secondReturn = $this->submissionService->submitDraftForApproval($secondReturn->fresh());
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $secondReturn->status);
    }
}

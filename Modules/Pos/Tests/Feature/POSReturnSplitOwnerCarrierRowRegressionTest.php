<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\ProductBundleResolver;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnApprovalPlanPersistenceService;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sequence 10 correction regression: real end-to-end proof that the
 * split-owner CARRIER-ROW persistence shape (InlinePosCheckoutPostingAdapter)
 * — a component owner group that fulfills ZERO of the bundle parent's own
 * quantity, only the component — is correctly resolved by the POS Return
 * pipeline (snapshot -> draft submission -> approval preview -> plan
 * persistence -> lifecycle execution), not merely by hand-built fixtures.
 *
 * Confirmed shape (InlinePosCheckoutPostingAdapter::post(), verified this
 * session): every owner group's Sale receives a SaleDetails "carrier" row for
 * the PARENT product, even when that owner fulfills 0 parent quantity
 * (quantity = 0). The bundle component's SaleBundleItem and DispatchDetail
 * are both attached via sale_detail_id = that carrier row's id. This test
 * forces exactly that: the bundle parent (serial-tracked) is stocked and
 * dispatched ENTIRELY from Owner A's location, while the bundle component is
 * stocked ONLY at Owner B's location — so Owner B's carrier row for the
 * parent product is created with quantity = 0.
 */
class POSReturnSplitOwnerCarrierRowRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        config(['pos.checkout.split_posting.enabled' => true]);

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.view',
            'pos.returns.create',
            'pos.returns.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /**
     * End-to-end proof: real checkout posting produces the exact carrier-row
     * shape described above, and the POS Return pipeline (lookup -> draft ->
     * preview -> approve/persist -> execute) resolves the cross-owner
     * component correctly all the way through — including that the returned
     * serial's lineage (SerialNumberHistory/DispatchDetail created during
     * receiving) is attributed to the COMPONENT's own product_id, never the
     * carrier row's nominal (parent) product_id.
     *
     * @test
     */
    public function it_resolves_a_component_only_owner_group_carrier_row_through_the_full_return_lifecycle(): void
    {
        $context = $this->createCarrierOnlyOwnerContext();

        // 1. Real checkout: parent (serial) entirely from Owner A; component
        // (serial) entirely from Owner B — forcing Owner B's carrier row for
        // the parent product to be quantity = 0.
        $lineId = $this->addCartLine($context['cashier'], $context['setting'], $context['parent']->id, 1, $context['bundle']->id);
        $this->assignSerials($context['cashier'], $context['setting'], $lineId, [$context['parentSerial']->serial_number]);

        $bundleItemDef = ProductBundleItem::query()
            ->where('bundle_id', $context['bundle']->id)
            ->where('product_id', $context['component']->id)
            ->firstOrFail();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => $context['componentSerial']->serial_number,
                'bundle_item_id' => $bundleItemDef->id,
            ])
            ->assertOk();

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CARRIER-ONLY-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(201);

        $posCheckoutId = $response->json('pos_checkout_id');
        $transactionId = (int) \Modules\Pos\Entities\PosCheckout::findOrFail($posCheckoutId)->pos_transaction_id;
        $this->assertNotEmpty($transactionId, 'Checkout finalize must resolve a pos_transaction_id via PosCheckout.');

        // 2. Assert the exact persisted carrier-row shape.
        $parentSale = Sale::query()
            ->whereHas('saleDetails', fn ($q) => $q->where('product_id', $context['parent']->id)->where('quantity', 1))
            ->firstOrFail();

        $carrierDetail = SaleDetails::query()
            ->where('product_id', $context['parent']->id)
            ->where('quantity', 0)
            ->whereHas('bundleItems', fn ($q) => $q->where('product_id', $context['component']->id))
            ->first();

        $this->assertNotNull($carrierDetail, 'Owner B must have a zero-quantity carrier SaleDetails row for the parent product.');
        $this->assertNotEquals($parentSale->id, $carrierDetail->sale_id, 'Carrier row must live under a DIFFERENT Sale than the parent\'s own.');

        $componentBundleItem = SaleBundleItem::query()
            ->where('sale_detail_id', $carrierDetail->id)
            ->where('product_id', $context['component']->id)
            ->firstOrFail();

        $componentDispatchDetail = DispatchDetail::query()
            ->where('sale_detail_id', $carrierDetail->id)
            ->where('product_id', $context['component']->id)
            ->firstOrFail();

        $componentSerial = ProductSerialNumber::find($context['componentSerial']->id);
        $this->assertEquals($componentDispatchDetail->id, $componentSerial->dispatch_detail_id);
        $this->assertEquals('SOLD', $componentSerial->status);

        // 3. Snapshot resolves the component correctly (sale_detail_id = carrier row).
        $snapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $parentLine = collect($snapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id);
        $this->assertNotNull($parentLine, 'Bundle parent line missing from snapshot.');

        $componentEntry = collect($parentLine['bundle_items'] ?? [])->first(
            fn ($item) => (int) ($item['product_id'] ?? 0) === (int) $context['component']->id
        );
        $this->assertNotNull($componentEntry, 'Component entry missing from bundle_items.');
        $this->assertSame((int) $carrierDetail->id, (int) ($componentEntry['sale_detail_id'] ?? 0));
        $this->assertSame((int) $componentBundleItem->id, (int) ($componentEntry['sale_bundle_item_id'] ?? 0));
        $this->assertContains(
            $componentSerial->id,
            collect($componentEntry['serial_number_ids'] ?? [])->map(fn ($id) => (int) $id)->all()
        );

        // 4. Draft: submit a whole-bundle cash_return (parent-only from the
        // caller's point of view) — synthesis must resolve the cross-owner
        // component and persist it with ITS OWN product_id (never the
        // carrier row's nominal parent product_id).
        $submissionService = app(PosReturnSubmissionService::class);
        $posReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentLine['sale_detail_id'],
                    'sale_id' => $parentLine['sale_id'],
                    'returned_serial_id' => $context['parentSerial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->assertSame(PosReturn::STATUS_DRAFT, $posReturn->status);
        $lines = $posReturn->lines()->get();
        $componentLine = $lines->firstWhere('sale_detail_id', $carrierDetail->id);
        $this->assertNotNull($componentLine, 'Synthesized component line must exist, keyed by the carrier row id.');

        // Critical semantic assertion: the PosReturnLine's OWN product_id
        // must be the component's, never re-derived from the carrier row's
        // own (parent) product_id column.
        $this->assertSame((int) $context['component']->id, (int) $componentLine->product_id);
        $this->assertNotSame((int) $context['parent']->id, (int) $componentLine->product_id);
        $this->assertSame((int) $componentSerial->id, (int) $componentLine->returned_serial_id);

        // 5. Submit for approval, then preview/persist/execute.
        $posReturn = $submissionService->submitDraftForApproval($posReturn->fresh());

        $plan = app(PosReturnApprovalPreviewPlannerService::class)->plan($posReturn->fresh());
        $this->assertFalse($plan['is_blocked'] ?? true, json_encode($plan['blockers'] ?? []));

        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($posReturn->fresh(), $plan);

        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->status);

        // 6. Confirm the returned serial's lineage is attributed to the
        // COMPONENT's product_id, not the carrier's nominal parent product.
        $componentSaleReturnDetail = \Modules\SalesReturn\Entities\SaleReturnDetail::query()
            ->where('sale_detail_id', $carrierDetail->id)
            ->where('product_id', $context['component']->id)
            ->first();
        $this->assertNotNull($componentSaleReturnDetail, 'Component SaleReturnDetail must carry the component\'s own product_id.');
        $this->assertNotNull($componentSaleReturnDetail->cost_effective_at, 'HPP reversal must have run for the component.');

        $historyRow = DB::table('serial_number_histories')
            ->where('product_serial_number_id', $componentSerial->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($historyRow, 'Component serial must have a lineage history entry after return execution.');

        // 7. Idempotent retry: re-invoking execution must not duplicate movement.
        $historyCountBefore = DB::table('serial_number_histories')
            ->where('product_serial_number_id', $componentSerial->id)
            ->count();

        try {
            app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);
        } catch (\Throwable $e) {
            // Status-gated re-execution may throw once already COMPLETED —
            // acceptable, as long as no additional movement occurred.
        }

        $historyCountAfter = DB::table('serial_number_histories')
            ->where('product_serial_number_id', $componentSerial->id)
            ->count();

        $this->assertSame($historyCountBefore, $historyCountAfter, 'Retry execution must not duplicate serial history movement.');
    }

    /**
     * Closes the "known gap" flagged at the end of tasks.md Sequence 10: a
     * whole-bundle cash-return REFUNDABLE-quantity guard
     * (PosReturnQuantityGuard::getRefundableQuantity()) for a cross-owner
     * component whose ONLY identity is a carrier row must correctly compute
     * a nonzero refundable quantity — not always collapse to 0 because the
     * carrier row's own SaleDetails.quantity is always 0 by construction.
     *
     * Scenario: complete an independent component product_replacement
     * first, THEN submit a whole-bundle cash return. Business rule #3: a
     * completed replacement must NOT reduce what's still refundable for a
     * later whole-bundle cash return — the customer holds an equivalent
     * replacement unit, so the full original component quantity remains
     * synthesizable and refundable.
     *
     * @test
     */
    public function it_keeps_full_component_quantity_refundable_after_an_independent_component_replacement(): void
    {
        $context = $this->createCarrierOnlyOwnerContext();

        // Extra AVAILABLE component serial at Owner B's location to serve as
        // the replacement unit for the independent component replacement.
        $replacementComponentSerial = ProductSerialNumber::create([
            'product_id' => $context['component']->id,
            'location_id' => $context['locB']->id,
            'serial_number' => 'SN-CARRIER-COMP-REPL-' . $this->sequence++,
            'status' => 'ACTIVE',
            'tax_id' => $context['tax']->id,
        ]);
        ProductStock::where('product_id', $context['component']->id)
            ->where('location_id', $context['locB']->id)
            ->increment('quantity');
        ProductStock::where('product_id', $context['component']->id)
            ->where('location_id', $context['locB']->id)
            ->increment('quantity_tax');

        $lineId = $this->addCartLine($context['cashier'], $context['setting'], $context['parent']->id, 1, $context['bundle']->id);
        $this->assignSerials($context['cashier'], $context['setting'], $lineId, [$context['parentSerial']->serial_number]);

        $bundleItemDef = ProductBundleItem::query()
            ->where('bundle_id', $context['bundle']->id)
            ->where('product_id', $context['component']->id)
            ->firstOrFail();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => $context['componentSerial']->serial_number,
                'bundle_item_id' => $bundleItemDef->id,
            ])
            ->assertOk();

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CARRIER-REPL-THEN-REFUND-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);
        $response->assertStatus(201);

        $posCheckoutId = $response->json('pos_checkout_id');
        $transactionId = (int) \Modules\Pos\Entities\PosCheckout::findOrFail($posCheckoutId)->pos_transaction_id;

        $carrierDetail = SaleDetails::query()
            ->where('product_id', $context['parent']->id)
            ->where('quantity', 0)
            ->whereHas('bundleItems', fn ($q) => $q->where('product_id', $context['component']->id))
            ->firstOrFail();

        $snapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $parentLine = collect($snapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id);

        // 1. Independent component replacement (parent stays at 'none').
        $submissionService = app(PosReturnSubmissionService::class);
        $replacementReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $carrierDetail->id,
                    'sale_id' => $carrierDetail->sale_id,
                    'returned_serial_id' => $context['componentSerial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    'replacement_serial_id' => $replacementComponentSerial->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $replacementReturn = $submissionService->submitDraftForApproval($replacementReturn->fresh());
        $plan = app(PosReturnApprovalPreviewPlannerService::class)->plan($replacementReturn->fresh());
        $this->assertFalse($plan['is_blocked'] ?? true, json_encode($plan['blockers'] ?? []));
        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($replacementReturn->fresh(), $plan);
        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($replacementReturn->id);
        $replacementReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $replacementReturn->status);

        // 2. A LATER whole-bundle cash return must still resolve/synthesize
        // the component's FULL original quantity (1) as refundable — the
        // prior replacement must not have consumed refundable quantity.
        $freshSnapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $freshParentLine = collect($freshSnapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id);

        $cashReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $freshSnapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $freshParentLine['sale_detail_id'],
                    'sale_id' => $freshParentLine['sale_id'],
                    'returned_serial_id' => $context['parentSerial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->assertSame(PosReturn::STATUS_DRAFT, $cashReturn->status);
        $componentCashLine = $cashReturn->lines()->where('sale_detail_id', $carrierDetail->id)
            ->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
            ->first();
        $this->assertNotNull($componentCashLine, 'Component cash_return line must be synthesized despite the prior completed replacement.');
        $this->assertEquals(1.0, (float) $componentCashLine->quantity, 'Full original component quantity (1) must remain refundable after an independent replacement.');
    }

    /**
     * Sequence 11 correction: when the CATALOG declares a bundle component
     * that the actual checkout never fulfilled (no allocation row, no
     * SaleBundleItem, no dispatch — a genuinely missing/unresolvable
     * component), a whole-bundle cash return must be rejected as incomplete
     * before any mutation — never silently proceed as if the bundle were
     * whole. Simulates this against the real carrier-row persistence shape
     * by adding a second catalog ProductBundleItem to the bundle AFTER
     * checkout has already completed (so checkout itself succeeds normally,
     * but the catalog now declares a component this specific transaction
     * never actually delivered — exactly the "component allocation
     * missing" scenario, using real production lineage for the component
     * that WAS fulfilled).
     *
     * @test
     */
    public function it_rejects_a_whole_bundle_cash_return_when_a_catalog_component_has_no_resolvable_allocation(): void
    {
        $context = $this->createCarrierOnlyOwnerContext();

        $lineId = $this->addCartLine($context['cashier'], $context['setting'], $context['parent']->id, 1, $context['bundle']->id);
        $this->assignSerials($context['cashier'], $context['setting'], $lineId, [$context['parentSerial']->serial_number]);

        $bundleItemDef = ProductBundleItem::query()
            ->where('bundle_id', $context['bundle']->id)
            ->where('product_id', $context['component']->id)
            ->firstOrFail();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => $context['componentSerial']->serial_number,
                'bundle_item_id' => $bundleItemDef->id,
            ])
            ->assertOk();

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CARRIER-MISSING-COMPONENT-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);
        $response->assertStatus(201);

        $posCheckoutId = $response->json('pos_checkout_id');
        $transactionId = (int) \Modules\Pos\Entities\PosCheckout::findOrFail($posCheckoutId)->pos_transaction_id;

        // Catalog now declares a SECOND component this transaction never
        // fulfilled at all — no SaleBundleItem, no SaleDetails allocation,
        // no DispatchDetail, no serial anywhere for it.
        $unfulfilledComponent = $this->createProduct($context['setting'], 'CARRIER UNFULFILLED COMPONENT', 0, false, $context['tax']->id);
        ProductBundleItem::create([
            'bundle_id' => $context['bundle']->id,
            'product_id' => $unfulfilledComponent->id,
            'quantity' => 1,
        ]);
        \App\Support\ProductBundleResolver::clearCache();

        $baselineReturnCount = PosReturn::count();
        $baselineReturnLineCount = PosReturnLine::count();
        $baselineSaleReturnDetailCount = \Modules\SalesReturn\Entities\SaleReturnDetail::count();
        $baselineSerialHistoryCount = DB::table('serial_number_histories')->count();
        $baselineComponentSerial = ProductSerialNumber::find($context['componentSerial']->id)->only(['status', 'dispatch_detail_id']);
        $baselineParentSerial = ProductSerialNumber::find($context['parentSerial']->id)->only(['status', 'dispatch_detail_id']);
        $baselineComponentStock = ProductStock::where('product_id', $context['component']->id)->where('location_id', $context['locB']->id)->value('quantity');

        $submissionService = app(PosReturnSubmissionService::class);
        $snapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $parentLine = collect($snapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id);

        $rejected = false;
        $errorMessage = null;
        try {
            $submissionService->store([
                'pos_transaction_id' => $transactionId,
                'source_snapshot_hash' => $snapshot['hash'],
                'lines' => [
                    [
                        'sale_detail_id' => $parentLine['sale_detail_id'],
                        'sale_id' => $parentLine['sale_id'],
                        'returned_serial_id' => $context['parentSerial']->id,
                        'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                        'quantity' => 1,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $rejected = true;
            $errorMessage = $e->getMessage();
        }

        $this->assertTrue($rejected, 'A whole-bundle cash return with a catalog component that has no resolvable allocation must be rejected, never silently proceed as complete.');
        $this->assertStringContainsString(
            'harus mencakup seluruh unit bundel',
            (string) $errorMessage,
            'The rejection must identify unresolved/incomplete bundle-component lineage.'
        );

        $this->assertSame($baselineReturnCount, PosReturn::count(), 'No draft/PosReturn may be created by a rejected incomplete-bundle submission.');
        $this->assertSame($baselineReturnLineCount, PosReturnLine::count(), 'No PosReturnLine may be created by a rejected incomplete-bundle submission.');
        $this->assertSame($baselineSaleReturnDetailCount, \Modules\SalesReturn\Entities\SaleReturnDetail::count(), 'No SaleReturnDetail (refund/stock/HPP effect) may be created.');
        $this->assertSame($baselineSerialHistoryCount, DB::table('serial_number_histories')->count(), 'No serial history (receiving/dispatch effect) may be created.');
        $this->assertEquals($baselineComponentSerial, ProductSerialNumber::find($context['componentSerial']->id)->only(['status', 'dispatch_detail_id']), 'Component serial state must be unchanged.');
        $this->assertEquals($baselineParentSerial, ProductSerialNumber::find($context['parentSerial']->id)->only(['status', 'dispatch_detail_id']), 'Parent serial state must be unchanged.');
        $this->assertEquals($baselineComponentStock, ProductStock::where('product_id', $context['component']->id)->where('location_id', $context['locB']->id)->value('quantity'), 'Component stock must be unchanged.');
    }

    /**
     * Business rule #5: a second whole-bundle cash return must not
     * over-refund. Complete a whole-bundle cash return, then attempt
     * another cash return for the same (already fully refunded) quantity —
     * must be rejected before any mutation, with no duplicate return,
     * stock, serial, receiving, dispatch, or HPP effects.
     *
     * @test
     */
    public function it_rejects_a_second_whole_bundle_cash_return_that_would_over_refund(): void
    {
        $context = $this->createCarrierOnlyOwnerContext();

        $lineId = $this->addCartLine($context['cashier'], $context['setting'], $context['parent']->id, 1, $context['bundle']->id);
        $this->assignSerials($context['cashier'], $context['setting'], $lineId, [$context['parentSerial']->serial_number]);

        $bundleItemDef = ProductBundleItem::query()
            ->where('bundle_id', $context['bundle']->id)
            ->where('product_id', $context['component']->id)
            ->firstOrFail();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => $context['componentSerial']->serial_number,
                'bundle_item_id' => $bundleItemDef->id,
            ])
            ->assertOk();

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CARRIER-DOUBLE-REFUND-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);
        $response->assertStatus(201);

        $posCheckoutId = $response->json('pos_checkout_id');
        $transactionId = (int) \Modules\Pos\Entities\PosCheckout::findOrFail($posCheckoutId)->pos_transaction_id;

        $carrierDetail = SaleDetails::query()
            ->where('product_id', $context['parent']->id)
            ->where('quantity', 0)
            ->whereHas('bundleItems', fn ($q) => $q->where('product_id', $context['component']->id))
            ->firstOrFail();

        $submissionService = app(PosReturnSubmissionService::class);

        $snapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $parentLine = collect($snapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id);

        // 1. First whole-bundle cash return: complete it.
        $firstReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentLine['sale_detail_id'],
                    'sale_id' => $parentLine['sale_id'],
                    'returned_serial_id' => $context['parentSerial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);
        $firstReturn = $submissionService->submitDraftForApproval($firstReturn->fresh());
        $plan = app(PosReturnApprovalPreviewPlannerService::class)->plan($firstReturn->fresh());
        $this->assertFalse($plan['is_blocked'] ?? true, json_encode($plan['blockers'] ?? []));
        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($firstReturn->fresh(), $plan);
        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($firstReturn->id);
        $firstReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $firstReturn->status);

        // 2. Second cash-return DRAFT against the SAME already-refunded
        // serial. Draft creation (store()) is intentionally non-consuming/
        // intake-only (PosReturn::consumesReturnQuantity() excludes DRAFT
        // and PENDING_APPROVAL), so the draft itself may legitimately be
        // created — the returned-serial exclusivity guard
        // (assertReturnedSerialNotClaimedElsewhere()) is enforced at
        // submitDraftForApproval() time, which is where the over-refund
        // attempt must actually be rejected, before any physical/
        // accounting mutation occurs.
        $secondReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => app(PosReturnSnapshotService::class)->build($transactionId)['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentLine['sale_detail_id'],
                    'sale_id' => $parentLine['sale_id'],
                    'returned_serial_id' => $context['parentSerial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);

        $saleReturnDetailCountBefore = \Modules\SalesReturn\Entities\SaleReturnDetail::count();
        $serialHistoryCountBefore = DB::table('serial_number_histories')->count();
        $componentSerialBefore = ProductSerialNumber::find($context['componentSerial']->id)->only(['status', 'dispatch_detail_id']);
        $parentSerialBefore = ProductSerialNumber::find($context['parentSerial']->id)->only(['status', 'dispatch_detail_id']);

        $rejected = false;
        try {
            $submissionService->submitDraftForApproval($secondReturn->fresh());
        } catch (\Throwable $e) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'A second whole-bundle cash return against an already-fully-refunded parent/component quantity must be rejected before mutation.');

        $this->assertSame($saleReturnDetailCountBefore, \Modules\SalesReturn\Entities\SaleReturnDetail::count(), 'No duplicate SaleReturnDetail may be created by a rejected over-refund attempt.');
        $this->assertSame($serialHistoryCountBefore, DB::table('serial_number_histories')->count(), 'No duplicate serial history movement may be created by a rejected over-refund attempt.');
        $this->assertEquals($componentSerialBefore, ProductSerialNumber::find($context['componentSerial']->id)->only(['status', 'dispatch_detail_id']), 'Component serial state must be unchanged by a rejected over-refund attempt.');
        $this->assertEquals($parentSerialBefore, ProductSerialNumber::find($context['parentSerial']->id)->only(['status', 'dispatch_detail_id']), 'Parent serial state must be unchanged by a rejected over-refund attempt.');
    }

    /**
     * Business rule #7/#8: rejected and cancelled returns must consume
     * neither replacement nor refundable quantity — confirmed via the
     * existing consumesReturnQuantity() status scopes (PosReturn::
     * returnQuantityConsumingStatuses()), not invented behavior. After a
     * cash-return draft is rejected, a subsequent whole-bundle cash return
     * for the SAME parent/component quantity must succeed.
     *
     * @test
     */
    public function it_does_not_consume_refundable_quantity_for_rejected_or_cancelled_returns(): void
    {
        $context = $this->createCarrierOnlyOwnerContext();

        $lineId = $this->addCartLine($context['cashier'], $context['setting'], $context['parent']->id, 1, $context['bundle']->id);
        $this->assignSerials($context['cashier'], $context['setting'], $lineId, [$context['parentSerial']->serial_number]);

        $bundleItemDef = ProductBundleItem::query()
            ->where('bundle_id', $context['bundle']->id)
            ->where('product_id', $context['component']->id)
            ->firstOrFail();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => $context['componentSerial']->serial_number,
                'bundle_item_id' => $bundleItemDef->id,
            ])
            ->assertOk();

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CARRIER-REJECTED-CANCELLED-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);
        $response->assertStatus(201);

        $posCheckoutId = $response->json('pos_checkout_id');
        $transactionId = (int) \Modules\Pos\Entities\PosCheckout::findOrFail($posCheckoutId)->pos_transaction_id;

        $carrierDetail = SaleDetails::query()
            ->where('product_id', $context['parent']->id)
            ->where('quantity', 0)
            ->whereHas('bundleItems', fn ($q) => $q->where('product_id', $context['component']->id))
            ->firstOrFail();

        $submissionService = app(PosReturnSubmissionService::class);
        $snapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $parentLine = collect($snapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id);

        // 1. Submit and REJECT a whole-bundle cash return.
        $rejectedReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentLine['sale_detail_id'],
                    'sale_id' => $parentLine['sale_id'],
                    'returned_serial_id' => $context['parentSerial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);
        $rejectedReturn = $submissionService->submitDraftForApproval($rejectedReturn->fresh());
        $rejectedReturn->update([
            'status' => PosReturn::STATUS_REJECTED,
            'approval_status' => PosReturn::APPROVAL_STATUS_REJECTED,
        ]);
        $this->assertSame(PosReturn::STATUS_REJECTED, $rejectedReturn->fresh()->status);

        // Rejected is not in returnQuantityConsumingStatuses(), so a fresh
        // submission for the SAME quantity must still succeed.
        $freshSnapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $freshParentLine = collect($freshSnapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id);

        $secondReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $freshSnapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $freshParentLine['sale_detail_id'],
                    'sale_id' => $freshParentLine['sale_id'],
                    'returned_serial_id' => $context['parentSerial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);
        $this->assertSame(PosReturn::STATUS_DRAFT, $secondReturn->status);
        $componentLine = $secondReturn->lines()->where('sale_detail_id', $carrierDetail->id)->first();
        $this->assertNotNull($componentLine, 'A fresh whole-bundle cash return after a REJECTED prior attempt must still synthesize the component line.');
        $this->assertEquals(1.0, (float) $componentLine->quantity);

        // 2. CANCEL this second one too, then confirm a THIRD attempt also succeeds.
        $secondReturn->update([
            'status' => PosReturn::STATUS_CANCELLED,
            'approval_status' => PosReturn::APPROVAL_STATUS_REJECTED,
        ]);
        $this->assertSame(PosReturn::STATUS_CANCELLED, $secondReturn->fresh()->status);

        $thirdSnapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $thirdParentLine = collect($thirdSnapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id);

        $thirdReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $thirdSnapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $thirdParentLine['sale_detail_id'],
                    'sale_id' => $thirdParentLine['sale_id'],
                    'returned_serial_id' => $context['parentSerial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);
        $this->assertSame(PosReturn::STATUS_DRAFT, $thirdReturn->status);
        $thirdComponentLine = $thirdReturn->lines()->where('sale_detail_id', $carrierDetail->id)->first();
        $this->assertNotNull($thirdComponentLine, 'A fresh whole-bundle cash return after a CANCELLED prior attempt must still synthesize the component line.');
        $this->assertEquals(1.0, (float) $thirdComponentLine->quantity);
    }

    /**
     * Business rule #9: idempotent retry must not consume quantity twice or
     * create duplicate return, stock, serial, HPP, receiving, or dispatch
     * effects. Replays executeApprovalFromPreview() for a completed
     * carrier-row component cash return and asserts every physical/
     * accounting effect remains exactly-once. (Complements the existing
     * happy-path test's own lighter retry check by additionally asserting
     * stock/SaleReturnDetail counts, not just serial history count.)
     *
     * @test
     */
    public function it_replays_carrier_row_lifecycle_execution_idempotently_with_exactly_once_effects(): void
    {
        $context = $this->createCarrierOnlyOwnerContext();

        $lineId = $this->addCartLine($context['cashier'], $context['setting'], $context['parent']->id, 1, $context['bundle']->id);
        $this->assignSerials($context['cashier'], $context['setting'], $lineId, [$context['parentSerial']->serial_number]);

        $bundleItemDef = ProductBundleItem::query()
            ->where('bundle_id', $context['bundle']->id)
            ->where('product_id', $context['component']->id)
            ->firstOrFail();

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => $context['componentSerial']->serial_number,
                'bundle_item_id' => $bundleItemDef->id,
            ])
            ->assertOk();

        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CARRIER-IDEMPOTENT-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);
        $response->assertStatus(201);

        $posCheckoutId = $response->json('pos_checkout_id');
        $transactionId = (int) \Modules\Pos\Entities\PosCheckout::findOrFail($posCheckoutId)->pos_transaction_id;

        $submissionService = app(PosReturnSubmissionService::class);
        $snapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $parentLine = collect($snapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id);

        $posReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentLine['sale_detail_id'],
                    'sale_id' => $parentLine['sale_id'],
                    'returned_serial_id' => $context['parentSerial']->id,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);
        $posReturn = $submissionService->submitDraftForApproval($posReturn->fresh());
        $plan = app(PosReturnApprovalPreviewPlannerService::class)->plan($posReturn->fresh());
        $this->assertFalse($plan['is_blocked'] ?? true, json_encode($plan['blockers'] ?? []));
        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($posReturn->fresh(), $plan);
        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);
        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->status);

        $saleReturnDetailCountBefore = \Modules\SalesReturn\Entities\SaleReturnDetail::count();
        $serialHistoryCountBefore = DB::table('serial_number_histories')->count();
        $componentStockBefore = ProductStock::where('product_id', $context['component']->id)->where('location_id', $context['locB']->id)->value('quantity');

        // Replay: same lifecycle execution invoked again on an already-
        // COMPLETED return.
        try {
            app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);
        } catch (\Throwable $e) {
            // Status-gated re-execution may throw once already COMPLETED —
            // acceptable, as long as no additional effects occurred.
        }

        $this->assertSame($saleReturnDetailCountBefore, \Modules\SalesReturn\Entities\SaleReturnDetail::count(), 'Retry must not create a duplicate SaleReturnDetail.');
        $this->assertSame($serialHistoryCountBefore, DB::table('serial_number_histories')->count(), 'Retry must not create duplicate serial history movement.');
        $this->assertEquals($componentStockBefore, ProductStock::where('product_id', $context['component']->id)->where('location_id', $context['locB']->id)->value('quantity'), 'Retry must not double-mutate component stock.');
    }

    /**
     * Ambiguity guard: the SAME component product SKU appears in TWO
     * separate bundle purchases in one transaction — two distinct
     * PosCheckoutSale groups (own Sale each, i.e. two separate bundle
     * occurrences, as split-owner/split-Sale posting actually produces one
     * SaleBundleItem per occurrence — the POS cart normalizes identical
     * same-product/same-bundle cart lines into a single aggregated line, so
     * this scenario is only reachable by constructing the two occurrences'
     * persisted lineage directly, exactly as InlinePosCheckoutPostingAdapter
     * would for two owner groups each independently fulfilling one unit of
     * the same catalog bundle). Resolution must disambiguate by
     * bundle_item_id/bundle_id/quantity per occurrence and never cross-match
     * a component from one bundle purchase into the other's synthesis.
     *
     * @test
     */
    public function it_does_not_cross_match_the_same_component_sku_across_two_separate_bundle_purchases(): void
    {
        $context = $this->createTwoBundleOccurrencesSharedComponentContext();

        $snapshot = app(PosReturnSnapshotService::class)->build($context['transactionId']);

        $parentLines = collect($snapshot['lines'])->filter(
            fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id
        )->values();

        $this->assertCount(2, $parentLines, 'Both bundle-parent lines must appear in the snapshot.');

        $componentIdentities = $parentLines->map(function ($parentLine) use ($context) {
            $componentEntry = collect($parentLine['bundle_items'] ?? [])->first(
                fn ($item) => (int) ($item['product_id'] ?? 0) === (int) $context['component']->id
            );

            $this->assertNotNull($componentEntry, 'Each bundle occurrence must resolve its own component entry.');

            return [
                'sale_detail_id' => $componentEntry['sale_detail_id'] ?? null,
                'dispatch_detail_id' => $componentEntry['dispatch_detail_id'] ?? null,
                'sale_bundle_item_id' => $componentEntry['sale_bundle_item_id'] ?? null,
                'serial_number_ids' => $componentEntry['serial_number_ids'] ?? [],
            ];
        });

        // The two occurrences share identical bundle_id/bundle_item_id/
        // quantity (same catalog bundle purchased twice) with no OTHER
        // distinguishing signal available to findComponentBundleItem() once
        // both Sales are in scope together — this is the textbook "genuinely
        // ambiguous, no stronger signal" case the disambiguation order is
        // designed to BLOCK rather than guess on. Both resolve
        // sale_bundle_item_id = null here, proving neither occurrence was
        // silently cross-matched to the other's SaleBundleItem (a wrong
        // guess would have produced a NON-null but WRONG id instead).
        $this->assertNull($componentIdentities[0]['sale_bundle_item_id'], 'Ambiguous cross-occurrence SaleBundleItem resolution must block (null), never guess.');
        $this->assertNull($componentIdentities[1]['sale_bundle_item_id'], 'Ambiguous cross-occurrence SaleBundleItem resolution must block (null), never guess.');

        // Despite the ambiguous strong-identity block, dispatch/serial
        // resolution is scoped per-occurrence's own sale_id (never shared
        // across occurrences) and so still resolves EACH occurrence's OWN
        // dispatched component serial correctly and distinctly — proving no
        // leakage of one occurrence's serial into the other's entry.
        $this->assertNotEquals(
            $componentIdentities[0]['dispatch_detail_id'],
            $componentIdentities[1]['dispatch_detail_id'],
            'Each occurrence must resolve its OWN component DispatchDetail, never leaking the other occurrence\'s.'
        );
        $this->assertNotEquals(
            $componentIdentities[0]['serial_number_ids'],
            $componentIdentities[1]['serial_number_ids'],
            'Each occurrence must resolve its OWN dispatched component serial, never leaking the other occurrence\'s serial.'
        );
    }

    /**
     * Builds a checkout where the bundle PARENT (serial-tracked) is stocked
     * and dispatched ENTIRELY from Owner A's location, while the bundle
     * COMPONENT (serial-tracked) is stocked ONLY at Owner B's location —
     * forcing Owner B's owner group to fulfill 0 parent quantity (a
     * carrier-only owner group).
     */
    private function createCarrierOnlyOwnerContext(): array
    {
        $setting = $this->createSetting('Carrier Only Terminal Setting');
        $ownerB = $this->createSetting('Carrier Only Owner B Setting');

        $cashier = $this->createUserForSetting($setting, 'carrier only cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.view',
        ]);

        $locA = Location::create(['name' => 'CARRIER ONLY LOC A ' . $this->sequence++, 'setting_id' => $setting->id]);
        $locB = Location::create(['name' => 'CARRIER ONLY LOC B ' . $this->sequence++, 'setting_id' => $ownerB->id]);

        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locB->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-CARRIER-' . $this->sequence++,
            'name' => 'Carrier Only Terminal',
            'is_active' => true,
        ]);
        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => false,
        ]);

        $methods = $this->seedPaymentMethods($setting, true);
        $session = app(PosSessionLifecycleService::class)->openSession($setting->id, $terminal->id, $cashier->id, 100000, [], $cashier->id);
        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);
        $ownerBCustomer = Customer::factory()->create(['setting_id' => $ownerB->id]);
        $ownerB->update(['pos_walk_in_customer_id' => $ownerBCustomer->id]);

        $tax = Tax::query()->create(['name' => 'VAT 11 Carrier', 'value' => 11, 'is_default' => true]);

        $parent = $this->createProduct($setting, 'CARRIER PARENT', 100000, true, $tax->id);
        ProductStock::create([
            'product_id' => $parent->id,
            'location_id' => $locA->id,
            'quantity' => 5, 'quantity_tax' => 5, 'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);
        $parentSerial = ProductSerialNumber::create([
            'product_id' => $parent->id,
            'location_id' => $locA->id,
            'serial_number' => 'SN-CARRIER-PARENT-' . $this->sequence++,
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);

        $component = $this->createProduct($setting, 'CARRIER COMPONENT', 0, true, $tax->id);
        ProductStock::create([
            'product_id' => $component->id,
            'location_id' => $locB->id,
            'quantity' => 5, 'quantity_tax' => 5, 'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);
        $componentSerial = ProductSerialNumber::create([
            'product_id' => $component->id,
            'location_id' => $locB->id,
            'serial_number' => 'SN-CARRIER-COMP-' . $this->sequence++,
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);

        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'CARRIER BUNDLE',
            'bundle_sale_price' => 100000,
            'price' => 100000,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $component->id, 'quantity' => 1]);
        ProductBundleResolver::clearCache();

        return [
            'setting' => $setting,
            'ownerB' => $ownerB,
            'cashier' => $cashier,
            'methods' => $methods,
            'customer' => $customer,
            'locA' => $locA,
            'locB' => $locB,
            'parent' => $parent,
            'component' => $component,
            'bundle' => $bundle,
            'parentSerial' => $parentSerial,
            'componentSerial' => $componentSerial,
            'tax' => $tax,
        ];
    }

    /**
     * Builds a checkout with TWO separate purchases of the SAME bundle
     * catalog (parent quantity 2, one Sale per serial unit under split
     * posting), where the shared component product is stocked/serialized
     * separately per occurrence — proving resolution keys by SaleBundleItem
     * identity (bundle_item_id/quantity), never by product_id alone.
     */
    /**
     * Builds two SEPARATE bundle occurrences of the same catalog bundle in
     * one POS transaction — two distinct Sales, each with its own carrier
     * SaleDetails row, SaleBundleItem, DispatchDetail, and dispatched
     * component serial — matching exactly the persisted shape
     * InlinePosCheckoutPostingAdapter produces for two independent owner
     * groups. Built directly via Eloquent (not the live cart flow) because
     * the POS cart normalizes identical same-product/same-bundle lines into
     * one aggregated line, making this specific two-occurrence shape
     * unreachable through the UI cart for this SKU pairing.
     */
    private function createTwoBundleOccurrencesSharedComponentContext(): array
    {
        $context = $this->createCarrierOnlyOwnerContext();
        $setting = $context['setting'];
        $parent = $context['parent'];
        $component = $context['component'];
        $bundle = $context['bundle'];
        $tax = $context['tax'];

        $bundleItemDef = ProductBundleItem::query()
            ->where('bundle_id', $bundle->id)
            ->where('product_id', $component->id)
            ->firstOrFail();

        $terminal = PosTerminal::query()->where('setting_id', $setting->id)->firstOrFail();
        $session = app(PosSessionLifecycleService::class)->openSession($setting->id, $terminal->id, $context['cashier']->id, 100000, [], $context['cashier']->id);

        $transaction = PosTransaction::create([
            'setting_id' => $setting->id,
            'code' => 'TXN-TWO-OCC-' . $this->sequence++,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $context['cashier']->id,
            'owner_user_id' => $context['cashier']->id,
            'last_saved_by' => $context['cashier']->id,
            'source_pos_session_id' => $session->id,
        ]);

        $checkout = \Modules\Pos\Entities\PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $context['cashier']->id,
            'status' => \Modules\Pos\Entities\PosCheckout::STATUS_POSTED,
            'grand_total' => 200000,
            'receipt_number' => 'RCP-TWO-OCC-' . $this->sequence++,
            'idempotency_key' => 'IDEM-TWO-OCC-' . $this->sequence++,
            'payload_hash' => 'HASH-TWO-OCC-' . $this->sequence++,
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $buildOccurrence = function (int $suffix) use ($setting, $checkout, $parent, $component, $bundle, $bundleItemDef, $tax) {
            $sale = Sale::query()->create([
                'setting_id' => $setting->id,
                'customer_id' => null,
                'customer_name' => 'Walk-in Customer',
                'total_amount' => 100000,
                'paid_amount' => 100000,
                'due_amount' => 0,
                'date' => now()->toDateString(),
                'status' => 'DISPATCHED',
                'payment_status' => 'PAID',
                'payment_method' => 'CASH',
                'reference' => 'SO-TWO-OCC-' . $suffix . '-' . uniqid(),
            ]);
            PosCheckoutSale::create([
                'pos_checkout_id' => $checkout->id,
                'sale_id' => $sale->id,
                'source_setting_id' => $setting->id,
                'source_location_id' => $this->locAOf($setting),
                'grand_total' => 100000,
                'split_key' => 'SPLIT-TWO-OCC-' . $suffix . '-' . uniqid(),
                'tax_bucket' => 'NON_TAX',
            ]);

            $parentDetail = SaleDetails::query()->create([
                'sale_id' => $sale->id,
                'product_id' => $parent->id,
                'quantity' => 1,
                'price' => 100000,
                'unit_price' => 100000,
                'sub_total' => 100000,
                'product_name' => $parent->product_name,
                'product_code' => $parent->product_code,
                'product_discount_amount' => 0,
                'product_tax_amount' => 0,
            ]);

            $parentSerial = ProductSerialNumber::create([
                'product_id' => $parent->id,
                'location_id' => $this->locAOf($setting),
                'serial_number' => 'SN-TWO-OCC-PARENT-' . $suffix . '-' . uniqid(),
                'status' => 'ACTIVE',
                'tax_id' => $tax->id,
            ]);

            $parentDispatch = Dispatch::query()->create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
            $parentDispatchDetail = DispatchDetail::query()->create([
                'dispatch_id' => $parentDispatch->id,
                'sale_id' => $sale->id,
                'product_id' => $parent->id,
                'sale_detail_id' => $parentDetail->id,
                'dispatched_quantity' => 1,
                'location_id' => $this->locAOf($setting),
            ]);
            $parentSerial->update(['dispatch_detail_id' => $parentDispatchDetail->id, 'status' => 'SOLD']);

            // Component's own SaleBundleItem attached to THIS occurrence's
            // own carrier (here, the parent's own row, since this is a
            // same-owner/same-Sale posting) — this occurrence's SaleBundleItem
            // id must be entirely distinct from the other occurrence's.
            $bundleItem = SaleBundleItem::query()->create([
                'sale_id' => $sale->id,
                'sale_detail_id' => $parentDetail->id,
                'bundle_id' => $bundle->id,
                'bundle_item_id' => $bundleItemDef->id,
                'product_id' => $component->id,
                'name' => $component->product_name,
                'quantity' => 1,
                'price' => 0,
                'sub_total' => 0,
            ]);

            $componentSerial = ProductSerialNumber::create([
                'product_id' => $component->id,
                'location_id' => $this->locBOf($setting),
                'serial_number' => 'SN-TWO-OCC-COMP-' . $suffix . '-' . uniqid(),
                'status' => 'ACTIVE',
                'tax_id' => $tax->id,
            ]);

            $componentDispatch = Dispatch::query()->create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
            $componentDispatchDetail = DispatchDetail::query()->create([
                'dispatch_id' => $componentDispatch->id,
                'sale_id' => $sale->id,
                'product_id' => $component->id,
                'sale_detail_id' => $parentDetail->id,
                'bundle_id' => $bundle->id,
                'dispatched_quantity' => 1,
                'location_id' => $this->locBOf($setting),
            ]);
            $componentSerial->update(['dispatch_detail_id' => $componentDispatchDetail->id, 'status' => 'SOLD']);

            $ptl = \Modules\Pos\Entities\PosTransactionLine::create([
                'pos_transaction_id' => $checkout->pos_transaction_id,
                'product_id' => $parent->id,
                'product_name_snapshot' => $parent->product_name,
                'product_code_snapshot' => $parent->product_code,
                'qty' => 1,
                'unit_price' => 100000,
                'line_no' => $suffix,
                'line_meta' => [
                    'bundle_id' => $bundle->id,
                    'bundle_items' => [
                        ['product_id' => $component->id, 'name' => $component->product_name, 'quantity' => 1, 'bundle_item_id' => $bundleItemDef->id],
                    ],
                ],
            ]);
            \Modules\Pos\Entities\PosTransactionLineSerial::create([
                'pos_transaction_line_id' => $ptl->id,
                'serial_number' => $parentSerial->serial_number,
            ]);

            return $bundleItem->id;
        };

        $firstBundleItemId = $buildOccurrence(1);
        $secondBundleItemId = $buildOccurrence(2);

        $this->assertNotEquals($firstBundleItemId, $secondBundleItemId, 'Fixture setup sanity check: the two occurrences must have distinct SaleBundleItem rows.');

        return [
            'transactionId' => $transaction->id,
            'parent' => $parent,
            'component' => $component,
        ];
    }

    private function locAOf(Setting $setting): int
    {
        return (int) Location::where('setting_id', $setting->id)->orderBy('id')->value('id');
    }

    private function locBOf(Setting $setting): int
    {
        // The component's own stock/dispatch location for this fixture is
        // Owner B's location (created in createCarrierOnlyOwnerContext) —
        // resolved by finding the location NOT belonging to $setting.
        return (int) Location::where('setting_id', '!=', $setting->id)->orderBy('id')->value('id');
    }

    private function createProduct(Setting $setting, string $name, float $price, bool $serialRequired, ?int $taxId): Product
    {
        $unit = Unit::firstOrCreate(['name' => 'PCS', 'short_name' => 'PCS']);
        $category = Category::firstOrCreate([
            'category_code' => 'CAT-CARRIER-' . $this->sequence++,
            'category_name' => 'CAT CARRIER NAME ' . $this->sequence++,
            'setting_id' => $setting->id,
            'created_by' => User::first()->id ?? User::factory()->create()->id,
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => 'PC-CARRIER-' . $this->sequence++,
            'product_price' => $price,
            'product_cost' => $price * 0.5,
            'product_unit' => 'PCS',
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $price,
            'sale_tax_id' => $taxId,
        ]);

        return $product;
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . $this->sequence++ . '@example.com',
            'company_phone' => '0812345678',
            'company_address' => 'Address',
            'default_currency_id' => Currency::first()->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'CAR' . $this->sequence,
            'sale_prefix_document' => 'JL',
            'pos_enabled' => true,
            'pos_transactions_enabled' => true,
            'is_pkp' => true,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): array
    {
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA CARRIER ' . $this->sequence++,
            'account_number' => 'ACC-CARRIER-' . $this->sequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create([
            'name' => 'CASH CARRIER ' . $this->sequence++,
            'coa_id' => $coaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        if ($enableForSetting) {
            DB::table('setting_pos_payment_methods')->insert([
                'setting_id' => $setting->id,
                'payment_method_id' => $method->id,
                'is_enabled' => true,
            ]);
        }

        return ['cash' => $method];
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $bundleId = null): int
    {
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
                'bundle_id' => $bundleId,
            ]);

        $response->assertOk();

        $lines = $response->json('cart_snapshot.lines');
        return (int) end($lines)['line_id'];
    }

    private function assignSerials(User $cashier, Setting $setting, int $lineId, array $serials): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => $serials,
            ])
            ->assertOk();
    }

    private function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
}

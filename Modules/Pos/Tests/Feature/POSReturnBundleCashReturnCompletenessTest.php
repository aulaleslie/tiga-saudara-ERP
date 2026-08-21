<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Spatie\Permission\Models\Permission;

/**
 * Phase 2 (align-bundle-return-replacement-rules) — draft eligibility and
 * intent for bundle cash-return completeness and independent replacement.
 */
class POSReturnBundleCashReturnCompletenessTest extends PosTransactionFeatureTestCase
{
    protected $submissionService;
    protected $snapshotService;
    protected $user;
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        $this->setting = $this->createSetting('Bundle Completeness Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
        Permission::findOrCreate('pos.returns.edit', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'POS Clerk', [
            'pos.access',
            'pos.returns.create',
            'pos.returns.edit',
        ]);

        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /**
     * Builds a non-serial bundle purchase: one parent SaleDetail (bundle
     * parent, quantity 1) with two SaleBundleItem rows, plus each
     * component's own separate SaleDetails row (unit_price = 0), matching
     * the persisted lineage pattern used elsewhere in this suite
     * (POSReturnBundleRegressionTest) and read by
     * PosReturnSnapshotService::findComponentAllocation().
     *
     * @return array{0: PosTransaction, 1: SaleDetails, 2: SaleDetails, 3: SaleDetails, 4: Sale}
     */
    protected function createNonSerialBundlePurchase(string $suffix = ''): array
    {
        $comp1 = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Component One' . $suffix,
            'product_code' => 'COMP1' . $suffix,
            'stock_qty' => 30,
        ]);
        $comp2 = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Component Two' . $suffix,
            'product_code' => 'COMP2' . $suffix,
            'stock_qty' => 30,
        ]);
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Bundle Parent' . $suffix,
            'product_code' => 'BPARENT' . $suffix,
            'sale_price' => 1000000,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Bundle Header' . $suffix,
            'is_active' => true,
        ]);

        $bi1 = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp1->id, 'quantity' => 1]);
        $bi2 = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp2->id, 'quantity' => 2]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-BUNDLE' . $suffix,
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
            'receipt_number' => 'RCP-BUNDLE' . $suffix,
            'idempotency_key' => 'IDEM-BUNDLE' . $suffix,
            'payload_hash' => 'HASH-BUNDLE' . $suffix,
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
            'reference' => 'SO-BUNDLE' . $suffix,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000000,
            'split_key' => 'SPLIT-BUNDLE' . $suffix,
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
            'bundle_item_id' => $bi1->id,
            'product_id' => $comp1->id,
            'name' => $comp1->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bi2->id,
            'product_id' => $comp2->id,
            'name' => $comp2->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
        ]);

        // Each component's own persisted allocation row (unit_price = 0),
        // discoverable by PosReturnSnapshotService::findComponentAllocation
        // and by the completeness check added in Phase 2.
        $comp1Detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $comp1->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $comp1->product_name,
            'product_code' => $comp1->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $comp2Detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $comp2->id,
            'quantity' => 2,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $comp2->product_name,
            'product_code' => $comp2->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Note: sale_details has no dispatch_detail_id column in this schema —
        // dispatch linkage is resolved dynamically via sale_id+product_id (see
        // PosReturnSnapshotService/PosReturnSubmissionService), not stored on
        // the SaleDetails row itself.
        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $comp1->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $comp2->id,
            'dispatched_quantity' => 2,
            'location_id' => $this->location->id,
        ]);

        return [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale];
    }

    /**
     * Semantic correction: product_replacement never consumes refundable
     * commercial quantity. A component independently replaced (and approved)
     * leaves the customer holding an equivalent replacement unit — the whole
     * bundle therefore remains eligible for a later cash return covering that
     * same logical component quantity. Only cash_return itself (or another
     * pending/active cash_return claim) can reduce what's refundable; a
     * rejected/cancelled document consumes nothing at all.
     *
     * @test
     */
    public function component_replacement_does_not_block_a_later_whole_bundle_cash_return()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createNonSerialBundlePurchase('-REPLTHENREFUND');

        // Independently replace component 1 (product_replacement, allowed on
        // its own per 2.3) and approve it. This does not touch the parent
        // line and must not reduce comp1's refundable quantity.
        $componentSnapshot = $this->snapshotService->build($transaction->id);
        $componentReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $componentSnapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $comp1Detail->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    'replacement_reason' => 'Warna salah dikirim',
                ],
            ],
        ]);
        $componentReturn->update([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        // A whole-bundle cash return on the parent must still succeed and
        // still synthesize comp1's full original quantity (not reduced by
        // the earlier replacement).
        $freshSnapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $freshSnapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $lines = $posReturn->lines()->get()->keyBy('sale_detail_id');
        $this->assertEquals(1.0, (float) $lines->get($comp1Detail->id)->quantity);
        $this->assertEquals(2.0, (float) $lines->get($comp2Detail->id)->quantity);
    }

    /**
     * A component whose full quantity was already CASH-RETURNED (the only
     * resolution that consumes refundable commercial quantity) — via a first
     * approved whole-bundle cash return, the only way a component can
     * legitimately become cash-returned since component-only cash_return is
     * itself rejected (2.2) — must block a subsequent whole-bundle cash
     * return from synthesizing a duplicate refund for that same component.
     *
     * @test
     */
    public function whole_bundle_cash_return_cannot_over_refund_a_component_already_cash_returned()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, , , $sale] = $this->createNonSerialBundlePurchase('-COMPREFUNDED');

        // First whole-bundle cash return: synthesizes and consumes the full
        // refundable quantity for the parent and every component.
        $firstSnapshot = $this->snapshotService->build($transaction->id);
        $firstReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $firstSnapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
        $firstReturn->update([
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        // A second whole-bundle cash return on the same transaction must be
        // rejected — the parent and every component are already fully
        // refunded.
        $freshSnapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Kuantitas retur melebihi batas yang diizinkan');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $freshSnapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
    }

    /**
     * Corrections/1: parent-only cash_return submission must automatically
     * synthesize proportional component cash_return lines server-side — the
     * caller (UI/API) never has to explicitly submit component lines.
     *
     * @test
     */
    public function whole_bundle_cash_return_is_accepted()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createNonSerialBundlePurchase('-WHOLE');
        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $this->assertEquals(3, $posReturn->lines()->count());

        $lines = $posReturn->lines()->get()->keyBy('sale_detail_id');

        $parentLine = $lines->get($parentDetail->id);
        $comp1Line = $lines->get($comp1Detail->id);
        $comp2Line = $lines->get($comp2Detail->id);

        $this->assertNotNull($parentLine);
        $this->assertNotNull($comp1Line);
        $this->assertNotNull($comp2Line);

        $this->assertEquals(PosReturnLine::RESOLUTION_CASH_RETURN, $parentLine->resolution);
        $this->assertEquals(PosReturnLine::RESOLUTION_CASH_RETURN, $comp1Line->resolution);
        $this->assertEquals(PosReturnLine::RESOLUTION_CASH_RETURN, $comp2Line->resolution);

        // Component quantities synthesized proportionally: quantity_per_bundle * parent qty.
        $this->assertEquals(1, (float) $comp1Line->quantity);
        $this->assertEquals(2, (float) $comp2Line->quantity);

        // Corrections/1: component lines must NOT carry customer-facing refund
        // value — the parent line already reflects the full bundle price, so
        // expected_cash_amount on synthesized components is zero to avoid
        // double-counting the refund.
        $this->assertEquals(0.0, (float) $comp1Line->expected_cash_amount);
        $this->assertEquals(0.0, (float) $comp2Line->expected_cash_amount);
        $this->assertGreaterThan(0.0, (float) $parentLine->expected_cash_amount);

        // total_amount must equal the original bundle price, not double it.
        $this->assertEquals((float) $parentLine->expected_cash_amount, (float) $posReturn->total_amount);
        $this->assertEquals(1000000.0, (float) $posReturn->total_amount);

        // Corrections/3: identity columns populated on parent + components.
        $this->assertNotNull($parentLine->bundle_group_key);
        $this->assertEquals($parentLine->bundle_group_key, $comp1Line->bundle_group_key);
        $this->assertEquals($parentLine->bundle_group_key, $comp2Line->bundle_group_key);
        $this->assertEquals($parentDetail->id, $comp1Line->bundle_parent_sale_detail_id);
        $this->assertEquals($parentDetail->id, $comp2Line->bundle_parent_sale_detail_id);
        $this->assertEquals(1.0, (float) $comp1Line->component_quantity_per_bundle);
        $this->assertEquals(2.0, (float) $comp2Line->component_quantity_per_bundle);

        // Corrections/3: dispatch_detail_id must be the COMPONENT's own
        // (resolved via sale_id+product_id lookup, since sale_details has no
        // dispatch_detail_id column in this schema), not the parent's.
        $comp1DispatchDetailId = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $sale->id)
            ->where('product_id', $comp1Detail->product_id)->value('id');
        $comp2DispatchDetailId = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $sale->id)
            ->where('product_id', $comp2Detail->product_id)->value('id');
        $parentDispatchDetailId = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $sale->id)
            ->where('product_id', $parentDetail->product_id)->value('id');

        $this->assertEquals($comp1DispatchDetailId, $comp1Line->dispatch_detail_id);
        $this->assertEquals($comp2DispatchDetailId, $comp2Line->dispatch_detail_id);
        $this->assertEquals($parentDispatchDetailId, $parentLine->dispatch_detail_id);
        $this->assertNotEquals($parentLine->dispatch_detail_id, $comp1Line->dispatch_detail_id);
        $this->assertNotEquals($parentLine->dispatch_detail_id, $comp2Line->dispatch_detail_id);
    }

    /**
     * Correction: a non-serial component must resolve its OWN owner/location
     * via its own persisted DispatchDetail, not inherit the parent's
     * checkout-sale owner. When the parent is dispatched from Setting A but a
     * component is dispatched from a location belonging to a DIFFERENT
     * Setting B, the synthesized component line must carry Setting B's
     * source_setting_id/source_location_id — otherwise Phase 3 execution
     * grouping would misattribute the component's Sales Return to Setting A.
     *
     * @test
     */
    public function non_serial_component_synthesis_uses_its_own_dispatch_owner_not_the_parents()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $comp1Detail, , $sale] = $this->createNonSerialBundlePurchase('-CROSSOWNER');

        // A second, distinct owner/location that the component (not the
        // parent) was actually dispatched from.
        $otherSetting = $this->createSetting('Bundle Component Cross Owner Test');
        [, $otherLocation] = $this->createTerminalWithLocation($otherSetting);

        // Re-point comp1's own DispatchDetail to the other owner's location —
        // this is comp1's OWN persisted dispatch lineage, independent of the
        // parent's checkout_sale source_setting_id/source_location_id (both
        // still pointing at $this->setting/$this->location).
        \Modules\Sale\Entities\DispatchDetail::where('sale_id', $sale->id)
            ->where('product_id', $comp1Detail->product_id)
            ->update(['location_id' => $otherLocation->id]);

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $lines = $posReturn->lines()->get()->keyBy('sale_detail_id');
        $parentLine = $lines->get($parentDetail->id);
        $comp1Line = $lines->get($comp1Detail->id);

        $this->assertNotNull($comp1Line);
        // Parent keeps the original checkout-sale owner (Setting A / $this->setting).
        $this->assertEquals($this->setting->id, $parentLine->source_setting_id);
        $this->assertEquals($this->location->id, $parentLine->source_location_id);
        // Component resolves to its OWN dispatch lineage (Setting B), not the
        // parent's owner.
        $this->assertEquals($otherSetting->id, $comp1Line->source_setting_id);
        $this->assertEquals($otherLocation->id, $comp1Line->source_location_id);
        $this->assertNotEquals($parentLine->source_setting_id, $comp1Line->source_setting_id);
    }

    /** @test */
    /**
     * Corrections/1: a parent-only cash_return submission is no longer
     * rejected — it must be automatically synthesized into a complete
     * parent+components set (see whole_bundle_cash_return_is_accepted for
     * the full assertion coverage of that synthesis). This test asserts the
     * "parent only, from the caller's point of view" path specifically
     * succeeds rather than throwing, preserving the original test's intent
     * of exercising a bare parent-only submission end to end.
     */
    public function parent_only_cash_return_on_a_bundle_is_accepted_via_synthesis()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $comp1Detail, $comp2Detail, $sale] = $this->createNonSerialBundlePurchase('-PARENTONLY');
        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $lines = $posReturn->lines()->get();
        $this->assertEquals(3, $lines->count());
        $this->assertTrue($lines->contains('sale_detail_id', $comp1Detail->id));
        $this->assertTrue($lines->contains('sale_detail_id', $comp2Detail->id));
    }

    /** @test */
    public function component_only_cash_return_on_a_bundle_is_rejected()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, , $comp1Detail, , $sale] = $this->createNonSerialBundlePurchase('-COMPONLY');
        $snapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Retur uang kembali untuk paket bundel harus mencakup seluruh unit bundel');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $comp1Detail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
    }

    /** @test */
    public function independent_parent_only_product_replacement_is_accepted()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, , , $sale] = $this->createNonSerialBundlePurchase('-REPLPARENT');
        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, 'replacement_reason' => 'Test replacement reason'],
            ],
        ]);

        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $line = $posReturn->lines()->first();
        $this->assertEquals(PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, $line->resolution);
        $this->assertEquals('non_serial_note_only', $line->line_meta['execution_mode'] ?? null);
    }

    /** @test */
    public function independent_component_only_product_replacement_is_accepted()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, , $comp1Detail, , $sale] = $this->createNonSerialBundlePurchase('-REPLCOMP');
        $snapshot = $this->snapshotService->build($transaction->id);

        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $comp1Detail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, 'replacement_reason' => 'Test replacement reason'],
            ],
        ]);

        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $line = $posReturn->lines()->first();
        $this->assertEquals(PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, $line->resolution);
        $this->assertEquals('non_serial_note_only', $line->line_meta['execution_mode'] ?? null);
    }

    /** @test */
    public function same_sku_standalone_and_component_are_disambiguated_by_sale_detail_identity()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, , $comp1Detail, , $sale] = $this->createNonSerialBundlePurchase('-SAMESKU');

        // Sell the same component product again on its own (a standalone
        // sale detail, unit_price > 0, not linked to any bundle) within the
        // same sale.
        $standaloneDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $comp1Detail->product_id,
            'quantity' => 5,
            'price' => 20000,
            'unit_price' => 20000,
            'sub_total' => 100000,
            'product_name' => $comp1Detail->product_name . ' Standalone',
            'product_code' => $comp1Detail->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        // Cash-returning ONLY the standalone quantity of the same SKU must
        // not be treated as a component-only bundle cash return — it should
        // succeed because sale_detail_id (not product_id) drives identity.
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $standaloneDetail->id, 'quantity' => 2, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $line = $posReturn->lines()->first();
        $this->assertEquals($standaloneDetail->id, $line->sale_detail_id);
        $this->assertEquals(2, (float) $line->quantity);
    }

    /**
     * Regression check: two SEPARATE bundle purchases in the same
     * transaction/sale, each containing a component of the SAME product SKU,
     * must not leak serials, quantity, dispatch lineage, or bundle identity
     * into each other during synthesis. Exact SaleBundleItem/sale_detail_id
     * lineage (not product_id) must keep the two purchases fully isolated.
     *
     * @test
     */
    public function two_bundle_purchases_with_the_same_component_sku_remain_isolated_during_synthesis()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transactionA, $parentDetailA, $comp1DetailA, $comp2DetailA, $saleA] = $this->createNonSerialBundlePurchase('-DUALBUNDLE-A');

        // Second, independent bundle purchase of the SAME shared component
        // product (product_id matches comp1DetailA's product), but its own
        // distinct SaleDetails/SaleBundleItem/DispatchDetail rows.
        $sharedComponentProduct = $comp1DetailA->product;
        $parentB = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Bundle Parent-DUALBUNDLE-B',
            'product_code' => 'BPARENT-DUALBUNDLE-B',
            'sale_price' => 2000000,
        ]);
        $bundleB = ProductBundle::create([
            'parent_product_id' => $parentB->id,
            'setting_id' => $this->setting->id,
            'name' => 'Bundle Header-DUALBUNDLE-B',
            'is_active' => true,
        ]);
        $biB = ProductBundleItem::create(['bundle_id' => $bundleB->id, 'product_id' => $sharedComponentProduct->id, 'quantity' => 1]);

        $saleB = Sale::create([
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
            'reference' => 'SO-DUALBUNDLE-B',
        ]);
        PosCheckoutSale::create([
            'pos_checkout_id' => $transactionA->completedCheckout->id,
            'sale_id' => $saleB->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 2000000,
            'split_key' => 'SPLIT-DUALBUNDLE-B',
            'tax_bucket' => 'NON_TAX',
        ]);

        $parentDetailB = SaleDetails::create([
            'sale_id' => $saleB->id,
            'product_id' => $parentB->id,
            'quantity' => 1,
            'price' => 2000000,
            'unit_price' => 2000000,
            'sub_total' => 2000000,
            'product_name' => $parentB->product_name,
            'product_code' => $parentB->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        SaleBundleItem::create([
            'sale_id' => $saleB->id,
            'sale_detail_id' => $parentDetailB->id,
            'bundle_id' => $bundleB->id,
            'bundle_item_id' => $biB->id,
            'product_id' => $sharedComponentProduct->id,
            'name' => $sharedComponentProduct->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);
        $compDetailB = SaleDetails::create([
            'sale_id' => $saleB->id,
            'product_id' => $sharedComponentProduct->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_name' => $sharedComponentProduct->product_name,
            'product_code' => $sharedComponentProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        $dispatchB = Dispatch::create(['sale_id' => $saleB->id, 'status' => Dispatch::STATUS_APPROVED]);
        DispatchDetail::create([
            'dispatch_id' => $dispatchB->id,
            'sale_id' => $saleB->id,
            'product_id' => $parentB->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatchB->id,
            'sale_id' => $saleB->id,
            'product_id' => $sharedComponentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $snapshotB = $this->snapshotService->build($transactionA->id);
        $returnB = $this->submissionService->store([
            'pos_transaction_id' => $transactionA->id,
            'source_snapshot_hash' => $snapshotB['hash'],
            'lines' => [
                ['sale_id' => $saleB->id, 'sale_detail_id' => $parentDetailB->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $linesB = $returnB->lines()->get();
        $this->assertEquals(2, $linesB->count()); // parentB + its own shared-SKU component only
        $componentLineB = $linesB->firstWhere('sale_detail_id', $compDetailB->id);
        $this->assertNotNull($componentLineB);
        $this->assertEquals(1.0, (float) $componentLineB->quantity);
        $this->assertEquals($parentDetailB->id, $componentLineB->bundle_parent_sale_detail_id);
        // Bundle A's own component rows must not appear in Bundle B's return.
        $this->assertNull($linesB->firstWhere('sale_detail_id', $comp1DetailA->id));
        $this->assertNull($linesB->firstWhere('sale_detail_id', $comp2DetailA->id));

        // Bundle A must still resolve independently and correctly on its own.
        $snapshotA = $this->snapshotService->build($transactionA->id);
        $returnA = $this->submissionService->store([
            'pos_transaction_id' => $transactionA->id,
            'source_snapshot_hash' => $snapshotA['hash'],
            'lines' => [
                ['sale_id' => $saleA->id, 'sale_detail_id' => $parentDetailA->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
        $linesA = $returnA->lines()->get();
        $this->assertEquals(3, $linesA->count()); // parentA + comp1A + comp2A
        $this->assertNull($linesA->firstWhere('sale_detail_id', $compDetailB->id));
        $this->assertEquals($parentDetailA->id, $linesA->firstWhere('sale_detail_id', $comp1DetailA->id)->bundle_parent_sale_detail_id);
    }

    /**
     * Builds a bundle purchase with ONE serial-tracked component (plus one
     * non-serial component, to exercise mixed synthesis) and a serial
     * originally dispatched with that specific bundle instance.
     *
     * @param string $suffix
     * @param int $serialQuantityPerBundle number of ORIGINAL serials to
     *        create/dispatch for the serial component per bundle unit
     *        (default 1 — the common, unambiguous case).
     * @return array{0: PosTransaction, 1: SaleDetails, 2: SaleDetails, 3: SaleDetails, 4: Sale, 5: array<int, ProductSerialNumber>}
     *         [transaction, parentDetail, serialComponentDetail, nonSerialComponentDetail, sale, originalSerials]
     */
    protected function createSerialBundlePurchase(string $suffix = '', int $serialQuantityPerBundle = 1): array
    {
        $serialComp = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serial Component' . $suffix,
            'product_code' => 'SERCOMP' . $suffix,
            'serial_number_required' => true,
            'stock_qty' => 30,
        ]);
        $plainComp = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Plain Component' . $suffix,
            'product_code' => 'PLAINCOMP' . $suffix,
            'stock_qty' => 30,
        ]);
        $parent = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serial Bundle Parent' . $suffix,
            'product_code' => 'SBPARENT' . $suffix,
            'sale_price' => 1000000,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $this->setting->id,
            'name' => 'Serial Bundle Header' . $suffix,
            'is_active' => true,
        ]);

        $biSerial = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $serialComp->id, 'quantity' => $serialQuantityPerBundle]);
        $biPlain = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $plainComp->id, 'quantity' => 1]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SBUNDLE' . $suffix,
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
            'receipt_number' => 'RCP-SBUNDLE' . $suffix,
            'idempotency_key' => 'IDEM-SBUNDLE' . $suffix,
            'payload_hash' => 'HASH-SBUNDLE' . $suffix,
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
            'reference' => 'SO-SBUNDLE' . $suffix,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000000,
            'split_key' => 'SPLIT-SBUNDLE' . $suffix,
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
            'quantity' => $serialQuantityPerBundle,
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
            'quantity' => $serialQuantityPerBundle,
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
            'dispatched_quantity' => $serialQuantityPerBundle,
            'location_id' => $this->location->id,
        ]);
        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $plainComp->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        $originalSerials = [];
        for ($i = 1; $i <= $serialQuantityPerBundle; $i++) {
            $serial = $this->createSerialNumber($serialComp, $this->location, 'SN-' . $suffix . '-ORIG-' . $i);
            $serial->update(['dispatch_detail_id' => $serialDispatchDetail->id, 'status' => 'SOLD']);
            $originalSerials[] = $serial;
        }

        return [$transaction, $parentDetail, $serialCompDetail, $plainCompDetail, $sale, $originalSerials];
    }

    /**
     * Approve a product_replacement for a bundle component serial, mirroring
     * how an approved replacement would look after lifecycle execution
     * (Phase 3/4 territory — this test only needs the persisted
     * PosReturnLine row with an APPROVED PosReturn to exercise chain
     * resolution, not the full execution side effects).
     */
    /**
     * Builds a product_replacement PosReturn for a serial bundle component at
     * the given lifecycle $status. Default STATUS_COMPLETED represents a
     * FINISHED replacement — the replacement dispatch has actually executed
     * and the customer physically holds the replacement serial. Pass
     * STATUS_APPROVED or STATUS_AWAITING_DISPATCH to represent an in-flight
     * replacement (approved but not yet dispatched), which must NOT be
     * treated as "customer holds the replacement" by chain resolution.
     */
    protected function approveSerialReplacement(PosTransaction $transaction, Sale $sale, SaleDetails $componentDetail, int $returnedSerialId, int $replacementSerialId, string $status = PosReturn::STATUS_COMPLETED): PosReturn
    {
        $posReturn = PosReturn::create([
            'reference' => 'POSRT-REPL-' . uniqid(),
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $sale->reference,
            'receipt_number' => $sale->reference,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => $status,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'total_amount' => 0,
            'created_by' => $this->user->id,
        ]);

        $checkoutSaleId = PosCheckoutSale::where('sale_id', $sale->id)->value('id');

        PosReturnLine::create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSaleId,
            'sale_id' => $sale->id,
            'sale_detail_id' => $componentDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'product_id' => $componentDetail->product_id,
            'product_name' => $componentDetail->product_name,
            'product_code' => $componentDetail->product_code,
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'returned_serial_id' => $returnedSerialId,
            'replacement_serial_id' => $replacementSerialId,
            'replacement_quantity' => 1,
            'expected_cash_amount' => 0,
        ]);

        return $posReturn;
    }

    /** @test */
    public function whole_bundle_cash_return_synthesizes_original_serial_when_no_replacement_occurred()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, , $sale, $originalSerials] = $this->createSerialBundlePurchase('-ORIGONLY');
        $originalSerial = $originalSerials[0];

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $serialLine = $posReturn->lines()->where('sale_detail_id', $serialCompDetail->id)->first();
        $this->assertNotNull($serialLine);
        $this->assertEquals($originalSerial->id, $serialLine->returned_serial_id);
        $this->assertEquals(PosReturnLine::RESOLUTION_CASH_RETURN, $serialLine->resolution);
        $this->assertEquals(0.0, (float) $serialLine->expected_cash_amount);
    }

    /** @test */
    public function whole_bundle_cash_return_synthesizes_replacement_serial_not_the_original()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, , $sale, $originalSerials] = $this->createSerialBundlePurchase('-ONEREPL');
        $bOld = $originalSerials[0];
        $bNew = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-ONEREPL-NEW');

        // STATUS_COMPLETED: the replacement dispatch has actually executed —
        // the customer physically holds B-NEW now.
        $this->approveSerialReplacement($transaction, $sale, $serialCompDetail, $bOld->id, $bNew->id, PosReturn::STATUS_COMPLETED);

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $serialLine = $posReturn->lines()->where('sale_detail_id', $serialCompDetail->id)->first();
        $this->assertNotNull($serialLine);
        $this->assertEquals($bNew->id, $serialLine->returned_serial_id);
        $this->assertNotEquals($bOld->id, $serialLine->returned_serial_id);
    }

    /** @test */
    public function whole_bundle_cash_return_synthesizes_the_deepest_serial_after_multiple_replacements()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, , $sale, $originalSerials] = $this->createSerialBundlePurchase('-MULTIREPL');
        $bOld = $originalSerials[0];
        $bNew = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-MULTIREPL-NEW');
        $bNewer = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-MULTIREPL-NEWER');

        // Both replacements COMPLETED (dispatch executed) — chain resolution
        // must walk through both to the deepest leaf.
        $this->approveSerialReplacement($transaction, $sale, $serialCompDetail, $bOld->id, $bNew->id, PosReturn::STATUS_COMPLETED);
        $this->approveSerialReplacement($transaction, $sale, $serialCompDetail, $bNew->id, $bNewer->id, PosReturn::STATUS_COMPLETED);

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $serialLine = $posReturn->lines()->where('sale_detail_id', $serialCompDetail->id)->first();
        $this->assertNotNull($serialLine);
        $this->assertEquals($bNewer->id, $serialLine->returned_serial_id);
        $this->assertNotEquals($bOld->id, $serialLine->returned_serial_id);
        $this->assertNotEquals($bNew->id, $serialLine->returned_serial_id);
    }

    /**
     * Corrections/3 (in-flight replacement): an approved-but-not-yet-
     * dispatched replacement (STATUS_APPROVED, prior to receive()/
     * dispatchReplacement() actually running) must NOT be treated as
     * "customer holds the replacement serial" — the customer still
     * physically holds B-OLD at this point. A whole-bundle cash return must
     * block rather than silently returning B-OLD (which is itself
     * mid-replacement and will be received back into stock by the other
     * document once it completes) or guessing that B-NEW is already held.
     *
     * @test
     */
    public function whole_bundle_cash_return_is_blocked_while_component_replacement_is_approved_but_not_dispatched()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, , $sale, $originalSerials] = $this->createSerialBundlePurchase('-INFLIGHTAPPROVED');
        $bOld = $originalSerials[0];
        $bNew = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-INFLIGHTAPPROVED-NEW');

        // Approved but NOT completed — the replacement dispatch has not run.
        $this->approveSerialReplacement($transaction, $sale, $serialCompDetail, $bOld->id, $bNew->id, PosReturn::STATUS_APPROVED);

        $snapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('component_replacement_in_flight');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
    }

    /**
     * Same in-flight blocking rule, exercised at STATUS_AWAITING_DISPATCH —
     * the replacement has been approved AND received, but its own dispatch
     * to the customer has not yet executed (dispatchReplacement() has not
     * run). The customer still holds B-OLD, not B-NEW.
     *
     * @test
     */
    public function whole_bundle_cash_return_is_blocked_while_component_replacement_is_awaiting_dispatch()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, , $sale, $originalSerials] = $this->createSerialBundlePurchase('-INFLIGHTAWAITING');
        $bOld = $originalSerials[0];
        $bNew = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-INFLIGHTAWAITING-NEW');

        $this->approveSerialReplacement($transaction, $sale, $serialCompDetail, $bOld->id, $bNew->id, PosReturn::STATUS_AWAITING_DISPATCH);

        $snapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('component_replacement_in_flight');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
    }

    /**
     * A CHAINED in-flight scenario: the first replacement B-OLD -> B-NEW has
     * COMPLETED (customer holds B-NEW), but a second replacement B-NEW ->
     * B-NEWER is still only approved/in-flight. Chain resolution must walk
     * the first (completed) edge to B-NEW, then detect the in-flight second
     * edge FROM B-NEW and block — proving in-flight detection applies at
     * every hop, not only the first.
     *
     * @test
     */
    public function whole_bundle_cash_return_is_blocked_when_a_later_hop_in_the_chain_is_in_flight()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, , $sale, $originalSerials] = $this->createSerialBundlePurchase('-CHAINEDINFLIGHT');
        $bOld = $originalSerials[0];
        $bNew = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-CHAINEDINFLIGHT-NEW');
        $bNewer = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-CHAINEDINFLIGHT-NEWER');

        $this->approveSerialReplacement($transaction, $sale, $serialCompDetail, $bOld->id, $bNew->id, PosReturn::STATUS_COMPLETED);
        $this->approveSerialReplacement($transaction, $sale, $serialCompDetail, $bNew->id, $bNewer->id, PosReturn::STATUS_APPROVED);

        $snapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('component_replacement_in_flight');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
    }

    /** @test */
    public function whole_bundle_cash_return_is_blocked_when_current_leaf_serial_already_claimed()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, , $sale, $originalSerials] = $this->createSerialBundlePurchase('-DUPCLAIM');
        $originalSerial = $originalSerials[0];

        // Claim the current leaf serial (the original, since no replacement
        // occurred) via an in-flight (pending_approval) return.
        $claimingReturn = PosReturn::create([
            'reference' => 'POSRT-CLAIM-' . uniqid(),
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'transaction_code' => $sale->reference,
            'receipt_number' => $sale->reference,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'total_amount' => 0,
            'created_by' => $this->user->id,
        ]);
        PosReturnLine::create([
            'pos_return_id' => $claimingReturn->id,
            'pos_checkout_sale_id' => PosCheckoutSale::where('sale_id', $sale->id)->value('id'),
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
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
            'returned_serial_id' => $originalSerial->id,
            'expected_cash_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('sudah diklaim oleh retur lain');

        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
    }

    /** @test */
    public function full_remaining_bundle_return_includes_every_current_leaf_serial()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        [$transaction, $parentDetail, $serialCompDetail, $plainCompDetail, $sale, $originalSerials] = $this->createSerialBundlePurchase('-FULLRETURN');
        $originalSerial = $originalSerials[0];
        $replacementSerial = $this->createSerialNumber($serialCompDetail->product, $this->location, 'SN-FULLRETURN-NEW');
        $this->approveSerialReplacement($transaction, $sale, $serialCompDetail, $originalSerial->id, $replacementSerial->id);

        $snapshot = $this->snapshotService->build($transaction->id);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);

        $lines = $posReturn->lines()->get();
        $this->assertEquals(3, $lines->count()); // parent + serial component + plain component

        $serialLine = $lines->firstWhere('sale_detail_id', $serialCompDetail->id);
        $this->assertNotNull($serialLine);
        $this->assertEquals($replacementSerial->id, $serialLine->returned_serial_id);

        $plainLine = $lines->firstWhere('sale_detail_id', $plainCompDetail->id);
        $this->assertNotNull($plainLine);
        $this->assertEquals(1.0, (float) $plainLine->quantity);
    }

    /** @test */
    public function ambiguous_partial_return_is_blocked_for_multi_serial_component()
    {
        $this->actingAsInSetting($this->user, $this->setting);
        // quantity_per_bundle = 2 for the serial component: two original
        // serials per single bundle unit. createSerialBundlePurchase()
        // builds a single parent SaleDetails row with quantity=1 — bump it
        // to 2 so this one row represents TWO bundle units, making a
        // cash_return of quantity=1 (half of the row) a genuine PARTIAL
        // return with no persisted pairing proving which of the 2
        // originally-dispatched component serials belongs to which bundle
        // unit.
        [$transaction, $parentDetail, $serialCompDetail, , $sale] = $this->createSerialBundlePurchase('-AMBIG', 2);
        $parentDetail->update(['quantity' => 2]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('component_serial_partial_return_ambiguous');

        // Cash-return only 1 of the parent's 2 units — a PARTIAL return
        // relative to the parent's own remaining refundable quantity (2).
        // The serial component has quantity_per_bundle=2 with 2
        // originally-dispatched serials for this row, and no persisted
        // pairing ties a SPECIFIC original serial to a SPECIFIC fractional
        // parent quantity, so this must be blocked as ambiguous rather than
        // guessed via scan/ID order.
        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                ['sale_id' => $sale->id, 'sale_detail_id' => $parentDetail->id, 'quantity' => 1, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN],
            ],
        ]);
    }

    /** @test */
    public function rejected_serial_claim_is_released_for_a_new_draft()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Serial Product',
            'product_code' => 'SERIALP',
            'serial_number_required' => true,
            'sale_price' => 500000,
        ]);

        $returnedSerial = $this->createSerialNumber($product, $this->location, 'SN-RETURNED');
        $replacementSerial = $this->createSerialNumber($product, $this->location, 'SN-REPLACEMENT');

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-SERIAL-CLAIM',
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
            'grand_total' => 500000,
            'receipt_number' => 'RCP-SERIALCLAIM',
            'idempotency_key' => 'IDEM-SERIALCLAIM',
            'payload_hash' => 'HASH-SERIALCLAIM',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-SERIALCLAIM',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 500000,
            'split_key' => 'SPLIT-SERIALCLAIM',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 500000,
            'unit_price' => 500000,
            'sub_total' => 500000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returnedSerial->id],
        ]);

        $dispatch = Dispatch::create(['sale_id' => $sale->id, 'status' => Dispatch::STATUS_APPROVED]);
        $dd = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);
        $returnedSerial->update(['dispatch_detail_id' => $dd->id, 'status' => 'SOLD']);

        $ptl = \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->product_name,
            'product_code_snapshot' => $product->product_code,
            'qty' => 1,
            'unit_price' => 500000,
            'line_no' => 1,
            'line_meta' => [],
        ]);
        \Modules\Pos\Entities\PosTransactionLineSerial::create([
            'pos_transaction_line_id' => $ptl->id,
            'serial_number' => 'SN-RETURNED',
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        // First draft claims the returned serial via product_replacement,
        // then submit for approval, then get it rejected.
        $firstDraft = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $saleDetail->id,
                    'pos_transaction_line_id' => $ptl->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    'returned_serial_id' => $returnedSerial->id,
                    'replacement_serial_id' => $replacementSerial->id,
                ],
            ],
        ]);

        $submitted = $this->submissionService->submitDraftForApproval($firstDraft);
        $submitted->update(['status' => PosReturn::STATUS_REJECTED, 'approval_status' => PosReturn::APPROVAL_STATUS_REJECTED]);

        // A brand new draft claiming the SAME returned/replacement serials
        // must not be blocked, since the prior draft's return is REJECTED.
        $freshSnapshot = $this->snapshotService->build($transaction->id);

        $secondDraft = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $freshSnapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $saleDetail->id,
                    'pos_transaction_line_id' => $ptl->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
                    'returned_serial_id' => $returnedSerial->id,
                    'replacement_serial_id' => $replacementSerial->id,
                ],
            ],
        ]);

        $this->assertEquals(PosReturn::STATUS_DRAFT, $secondDraft->status);

        // Submitting the second draft for approval (which enforces returned
        // serial exclusivity) must also succeed since the first return's
        // claim is released by rejection.
        $secondSubmitted = $this->submissionService->submitDraftForApproval($secondDraft);
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $secondSubmitted->status);
    }
}

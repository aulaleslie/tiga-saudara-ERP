<?php

namespace Modules\Consignment\Tests\Unit;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutReturnApportionment;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Services\ConsignmentBuyoutReversalService;
use Modules\Consignment\Services\ConsignmentReceivingService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Services\PurchasePaymentStoreService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Internal full reversal of a finalized buyout (disabled by default). Deterministic SQLite tests, not concurrency proof.
 */
class ConsignmentBuyoutReversalTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    private ConsignmentBuyoutReversalService $rev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
        $this->rev = new ConsignmentBuyoutReversalService();
    }

    /** @return array{0: ConsignmentBuyout, 1: Purchase} */
    private function finalized(): array
    {
        [$b] = $this->mixed(null, 120.0);
        $purchase = $this->fin->finalize($b, $this->user);

        return [$b->fresh(), $purchase];
    }

    private function blockerTypes(ConsignmentBuyout $b): array
    {
        return array_values(array_unique(array_column($this->rev->preview($b)['blockers'], 'type')));
    }

    private function method(): PaymentMethod
    {
        $coa = ChartOfAccount::create(['setting_id' => $this->setting->id, 'account_number' => 'COA-' . uniqid(), 'name' => 'Cash', 'category' => 'Kas & Bank']);

        return PaymentMethod::create(['name' => 'Bank Transfer', 'coa_id' => $coa->id, 'is_active' => true]);
    }

    /** @test */
    public function a_clean_full_reversal_restores_stock_cost_capacity_and_cancels_the_liability(): void
    {
        [$b, $purchase] = $this->finalized();
        $afterFinal = $this->snapshot();
        $this->assertTrue($this->rev->preview($b)['eligible']);

        $out = $this->rev->reverse($b, $this->user, 'salah supplier');

        $this->assertSame('REVERSED', $out->status);
        $this->assertNotNull($out->reversal_id);
        // Stock is back where it was before finalization; global/business quantity never moved.
        $this->assertEquals(5, ProductStock::where('product_id', $this->product->id)->where('location_id', $this->location->id)->value('quantity'));
        $this->assertEquals(0, ProductStock::where('product_id', $this->product->id)->where('location_id', $this->dest1->id)->value('quantity'));
        $this->assertEquals(10, ProductStock::where('product_id', $this->product->id)->sum('quantity'));
        $this->assertEquals($afterFinal['products'], $this->snapshot()['products']);
        // Carrying value restored once; last purchase price untouched.
        $price = ProductPrice::first();
        $this->assertEquals([100.0, 777.0], [(float) $price->average_purchase_price, (float) $price->last_purchase_price]);
        $this->assertSame(1, \DB::table('consignment_buyout_cost_events')->where('kind', 'REVERSAL')->count());
        // Liability cancelled but evidence preserved.
        $p = $purchase->fresh();
        $this->assertSame(Purchase::STATUS_CANCELLED, $p->status);
        $this->assertEquals(560.0, (float) $p->total_amount);
        $this->assertEquals(0.0, (float) $p->due_amount);
        $this->assertSame(0.0, $p->live_due_amount);
        $this->assertSame(2, $p->purchaseDetails()->count());
        $this->assertSame(2, \DB::table('consignment_buyout_purchase_lineages')->count());
        // Compensating evidence, immutable audit, capacity released exactly once.
        $this->assertSame(2, Transaction::where('type', 'CONSIGNMENT_BUYOUT_REVERSAL')->count());
        $this->assertEquals([3.0, -3.0], Transaction::where('type', 'CONSIGNMENT_BUYOUT_REVERSAL')->orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $cap = $this->capacity()->receiptCapacity($b->lines()->first()->consignment_receiving_detail_id);
        $this->assertEquals([0.0, 10.0, 5.0, 5.0], [$cap['buyout_finalized'], $cap['financial_available'], $cap['physical_residual'], $cap['unsold_available']]);
        $this->assertSame(['DRAFT_CREATED', 'SUBMITTED', 'APPROVED', 'FINALIZED', 'REVERSED'], $out->auditEvents()->orderBy('id')->pluck('action')->all());
        $this->assertSame('SALAH SUPPLIER', strtoupper(\DB::table('consignment_buyout_reversals')->value('reason')));   // text columns are upper-cased by BaseModel
    }

    /** @test */
    public function repeating_a_reversal_returns_the_same_result_without_reposting(): void
    {
        [$b] = $this->finalized();
        $this->rev->reverse($b, $this->user, 'x');
        $counts = [\DB::table('transactions')->count(), \DB::table('consignment_buyout_reversals')->count(), \DB::table('consignment_buyout_movements')->count()];
        $snap = $this->snapshot();

        $again = $this->rev->reverse($b->fresh(), $this->user, 'again');
        $stale = $this->rev->reverse($b, $this->user, 'stale');

        $this->assertSame('REVERSED', $again->status);
        $this->assertSame('REVERSED', $stale->status);
        $this->assertSame($counts, [\DB::table('transactions')->count(), \DB::table('consignment_buyout_reversals')->count(), \DB::table('consignment_buyout_movements')->count()]);
        $this->assertEquals($snap, $this->snapshot());
    }

    /** @test */
    public function reversal_is_blocked_by_payment_history_including_invalidated_payments(): void
    {
        [$b, $purchase] = $this->finalized();
        $pay = (new PurchasePaymentStoreService())->store(purchase: $purchase->fresh(), actor: $this->user, data: [
            'date' => now()->format('Y-m-d'), 'reference' => 'PAY-R1', 'amount' => 100.0, 'payment_method_id' => $this->method()->id]);
        $this->assertContains('PAYMENT', $this->blockerTypes($b));

        \DB::table('purchase_payments')->where('id', $pay->id)->update(['status' => 'INVALIDATED']);
        $this->assertContains('PAYMENT', $this->blockerTypes($b), 'an invalidated payment still blocks');

        $snap = $this->snapshot();
        try {
            $this->rev->reverse($b->fresh(), $this->user, 'x');
            $this->fail('reversed after payment history');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('PAYMENT', $e->getMessage());
        }
        $this->assertEquals($snap, $this->snapshot());
        $this->assertSame('FINALIZED', $b->fresh()->status);
        $this->assertNotSame(Purchase::STATUS_CANCELLED, $purchase->fresh()->status);
    }

    /** @test */
    public function later_movements_including_consumption_then_replenishment_block_the_reversal(): void
    {
        [$b] = $this->finalized();
        $own = Transaction::where('type', 'CONSIGNMENT_BUYOUT')->orderBy('id')->get();
        $dest = $own->firstWhere('quantity', '>', 0);

        // Consumption then replenishment: the balance matches again, but two later movements exist.
        foreach ([-1, 1] as $q) {
            Transaction::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'type' => 'MANUAL', 'quantity' => $q,
                'current_quantity' => 10, 'broken_quantity' => 0, 'location_id' => $dest->location_id, 'previous_quantity' => 10, 'after_quantity' => 10,
                'previous_quantity_at_location' => 3, 'after_quantity_at_location' => 3, 'quantity_tax' => 0, 'quantity_non_tax' => $q,
                'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);
        }
        $this->assertEquals(3, ProductStock::where('location_id', $this->dest1->id)->value('quantity'), 'balances look untouched');
        $this->assertContains('MOVEMENT', $this->blockerTypes($b));

        $snap = $this->snapshot();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->rev->reverse($b->fresh(), $this->user, 'x');
        } finally {
            $this->assertEquals($snap, $this->snapshot());
            $this->assertSame('FINALIZED', $b->fresh()->status);
        }
    }

    /** @test */
    public function a_changed_average_a_return_apportionment_or_an_unapproved_receipt_each_block(): void
    {
        [$b] = $this->finalized();
        $this->assertSame([], $this->blockerTypes($b));

        // (A changed average blocks; the ledger-based intervening-event rule is covered by its own test.)
        $price = ProductPrice::first();
        \DB::table('product_prices')->where('id', $price->id)->update(['average_purchase_price' => 111]);
        $this->assertContains('COST', $this->blockerTypes($b));
        // Restore the row AND wipe the ledger rows these edits caused, so the remaining checks are isolated.
        \DB::table('product_prices')->where('id', $price->id)->update(['average_purchase_price' => 106]);
        \DB::table('product_price_cost_events')->where('id', '>', $b->costEvents()->value('price_event_id'))->delete();
        $this->assertSame([], $this->blockerTypes($b));

        $soldLine = $b->lines()->where('line_type', 'SOLD_UNBILLED')->first();
        $id = \DB::table('consignment_buyout_return_apportionments')->insertGetId([
            'setting_id' => $this->setting->id, 'sale_return_detail_id' => 1, 'dispatch_detail_id' => \Modules\Consignment\Entities\ConsignmentSoldSource::find($soldLine->consignment_sold_source_id)->dispatch_detail_id,
            'consignment_sold_source_id' => $soldLine->consignment_sold_source_id, 'status' => 'APPLIED', 'origin' => 'ALL', 'returned_quantity' => 1,
            'acquired_quantity' => 1, 'unacquired_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains('RETURN', $this->blockerTypes($b));
        \DB::table('consignment_buyout_return_apportionments')->where('id', $id)->delete();

        \DB::table('consignment_receivings')->update(['status' => 'REVERSED']);
        $this->assertContains('RECEIPT', $this->blockerTypes($b));
        \DB::table('consignment_receivings')->update(['status' => 'APPROVED']);
        $this->assertSame([], $this->blockerTypes($b));
    }

    /** @test */
    public function an_average_that_moved_and_returned_to_the_same_value_still_blocks_through_the_ledger(): void
    {
        [$b] = $this->finalized();
        $price = ProductPrice::first();
        $this->assertSame([], $this->blockerTypes($b));
        $ledgerAtFinalization = \DB::table('product_price_cost_events')->max('id');
        $this->assertEquals($ledgerAtFinalization, \DB::table('consignment_buyout_cost_events')->value('price_event_id'));

        foreach ([
            'eloquent save' => fn () => ProductPrice::find($price->id)->update(['average_purchase_price' => 130]),
            'mass update' => fn () => ProductPrice::query()->whereKey($price->id)->update(['average_purchase_price' => 140]),
            'raw statement' => fn () => \DB::statement('UPDATE product_prices SET average_purchase_price = 150 WHERE id = ?', [$price->id]),
        ] as $label => $change) {
            $before = \DB::table('product_price_cost_events')->count();
            $change();
            $this->assertSame($before + 1, \DB::table('product_price_cost_events')->count(), "{$label} is captured by the ledger");
        }
        // The average now moved three times and comes back to exactly today's value.
        ProductPrice::find($price->id)->update(['average_purchase_price' => 106]);
        $this->assertEquals(106.0, (float) ProductPrice::find($price->id)->average_purchase_price);

        $blockers = $this->rev->preview($b)['blockers'];
        $cost = collect($blockers)->firstWhere('type', 'COST');
        $this->assertNotNull($cost, 'equal average today is not enough');
        $this->assertStringContainsString('intervening', $cost['reason']);

        $snap = $this->snapshot();
        try {
            $this->rev->reverse($b->fresh(), $this->user, 'x');
            $this->fail('reversed over intervening cost events');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('intervening', $e->getMessage());
        }
        $this->assertEquals($snap, $this->snapshot());
        $this->assertSame('FINALIZED', $b->fresh()->status);

    }

    /** @test */
    public function writes_that_leave_the_average_unchanged_do_not_create_ledger_noise(): void
    {
        [$b] = $this->finalized();
        $price = ProductPrice::first();
        $count = \DB::table('product_price_cost_events')->count();
        ProductPrice::find($price->id)->update(['sale_price' => 99999, 'last_purchase_price' => 123]);   // not a cost change
        ProductPrice::find($price->id)->update(['average_purchase_price' => 106]);                        // same value
        $this->assertSame($count, \DB::table('product_price_cost_events')->count());
        $this->assertTrue($this->rev->preview($b)['eligible']);
    }

    /** @test */
    public function injected_failures_after_each_reversal_write_roll_everything_back(): void
    {
        [$b, $purchase] = $this->finalized();
        $snap = $this->snapshot();
        $counts = fn () => [\DB::table('transactions')->count(), \DB::table('consignment_buyout_movements')->count(),
            \DB::table('consignment_buyout_cost_events')->count(), \DB::table('consignment_buyout_reversals')->count(),
            \DB::table('consignment_buyout_audit_events')->count(), \DB::table('consignment_buyout_ownership_events')->count()];
        $before = $counts();

        foreach (['liability', 'stock', 'serial', 'cost', 'audit'] as $stage) {
            $svc = new ConsignmentBuyoutReversalService();
            $svc->stageHook = fn (string $s) => $s === $stage ? throw new \RuntimeException("injected {$stage}") : null;
            try {
                $svc->reverse($b->fresh(), $this->user, 'x');
                $this->fail("no failure at {$stage}");
            } catch (\RuntimeException $e) {
                $this->assertSame("injected {$stage}", $e->getMessage());
            }
            $this->assertEquals($snap, $this->snapshot(), $stage);
            $this->assertSame($before, $counts(), $stage);
            $this->assertSame('FINALIZED', $b->fresh()->status);
            $this->assertSame(Purchase::STATUS_RECEIVED, $purchase->fresh()->status);
            $this->assertEquals(560.0, (float) $purchase->fresh()->due_amount);
        }
        $this->rev->reverse($b->fresh(), $this->user, 'clean');
        $this->assertSame('REVERSED', $b->fresh()->status);
    }

    /** @test */
    public function serialized_acquisitions_move_back_and_later_serial_history_blocks(): void
    {
        \DB::table('products')->where('id', $this->product->id)->update(['serial_number_required' => true]);
        $r = $this->receipt($this->supplier, '2');
        $this->price($r, 100);
        $this->stock('2');
        $this->std($this->otherStd, 2);
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1, 'last_purchase_price' => 1, 'average_purchase_price' => 100]);
        $mk = fn (string $sn) => ProductSerialNumber::create(['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'serial_number' => $sn, 'consignment_receiving_detail_id' => $r->id, 'status' => 'ACTIVE']);
        $s1 = $mk('RV-1');
        $s2 = $mk('RV-2');
        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest1->id,
            'base_quantity' => '2', 'serial_ids' => [$s1->id, $s2->id]]], $this->user, $this->meta());
        $b = $this->life->approve($this->life->submit($b, $this->user), $this->user);
        $this->fin->finalize($b, $this->user);
        $this->assertSame($this->dest1->id, (int) $s1->fresh()->location_id);

        // Later history on one serial blocks.
        \App\Services\SerialNumberHistoryService::record($s2->id, 'STATUS_CHANGED', $this->dest1->id, null, 'later', $this->user->id);
        $this->assertContains('SERIAL', $this->blockerTypes($b->fresh()));
        \DB::table('serial_number_histories')->where('product_serial_number_id', $s2->id)->where('event_type', 'STATUS_CHANGED')->delete();

        $this->rev->reverse($b->fresh(), $this->user, 'serial reversal');
        foreach ([$s1, $s2] as $s) {
            $this->assertSame($this->location->id, (int) $s->fresh()->location_id);
            $this->assertSame($r->id, (int) $s->fresh()->consignment_receiving_detail_id);
        }
        $this->assertSame(2, \DB::table('serial_number_histories')->where('event_type', 'CONSIGNMENT_BUYOUT_REVERSED')->count());
        $this->assertSame(0, \DB::table('consignment_active_serial_claims')->count(), 'claims released');
        $this->assertEquals(2, ProductStock::where('location_id', $this->location->id)->where('product_id', $this->product->id)->value('quantity'));
        $this->assertEquals(0, ProductStock::where('location_id', $this->dest1->id)->where('product_id', $this->product->id)->value('quantity'));
    }

    /** @test */
    public function a_cancelled_buyout_purchase_denies_payments_and_has_zero_collectible_due_everywhere(): void
    {
        [$b, $purchase] = $this->finalized();
        $filter = new \App\Services\Reports\AgedPayablesReportFilterData(asOfDate: now()->addDay()->toDateString(), scopeSettingId: $this->setting->id);
        $control = (new \App\Services\Reports\AgedPayablesReportQueryService())->build($filter)->get();
        $this->assertSame(1, $control->where('supplier_id', $this->supplier->id)->count(), 'control: the liability is outstanding before the reversal');
        $this->rev->reverse($b, $this->user, 'x');

        try {
            (new PurchasePaymentStoreService())->store(purchase: $purchase->fresh(), actor: $this->user, data: [
                'date' => now()->format('Y-m-d'), 'reference' => 'PAY-LATE', 'amount' => 10.0, 'payment_method_id' => $this->method()->id]);
            $this->fail('paid a cancelled Purchase');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('cancelled', strtolower($e->getMessage()));
        }
        $this->assertSame(0, \DB::table('purchase_payments')->count());

        // Canonical projections: live due, filtered/ordered due, the global-payment eligibility and aged payables.
        $this->assertSame(0, Purchase::whereLiveDueAmountGreaterThan(0)->where('id', $purchase->id)->count());
        $this->assertSame(1, Purchase::whereLiveDueAmountLessThanOrEqual(0)->where('id', $purchase->id)->count());
        $this->assertSame(0, Purchase::globalPaymentEligible()->where('id', $purchase->id)->count());
        $purchase->fresh()->reconcileFromActivePayments();
        $this->assertEquals([560.0, 0.0], [(float) $purchase->fresh()->total_amount, (float) $purchase->fresh()->due_amount]);

        $rows = (new \App\Services\Reports\AgedPayablesReportQueryService())->build($filter)->get();
        $this->assertSame(0, $rows->where('supplier_id', $this->supplier->id)->count(), 'aged payables exclude the cancelled liability');
    }

    /** @test */
    public function only_the_dedicated_reversal_can_cancel_a_buyout_purchase(): void
    {
        [, $purchase] = $this->finalized();

        foreach (['status' => Purchase::STATUS_CANCELLED, 'supplier_id' => 999] as $field => $value) {
            try {
                $purchase->fresh()->update([$field => $value]);
                $this->fail("{$field} was changed outside the reversal");
            } catch (\DomainException $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
        // Even inside the scope only CANCELLED is acceptable.
        try {
            Purchase::withBuyoutReversalScope((int) $purchase->id, fn () => $purchase->fresh()->update(['status' => Purchase::STATUS_APPROVED]));
            $this->fail('scope allowed another status');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('CANCELLED', $e->getMessage());
        }
        $this->assertSame(Purchase::STATUS_RECEIVED, $purchase->fresh()->status);
        // Commercial evidence stays immutable inside the scope too.
        try {
            Purchase::withBuyoutReversalScope((int) $purchase->id, fn () => $purchase->fresh()->update(['total_amount' => 1]));
            $this->fail('scope allowed a total change');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('total_amount', $e->getMessage());
        }
    }

    /** @test */
    public function reversal_is_disabled_unless_enabled_and_needs_its_own_permission_and_business_access(): void
    {
        [$b] = $this->finalized();
        $snap = $this->snapshot();

        $partial = User::factory()->create();
        $partial->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        try {
            $this->rev->reverse($b->fresh(), $partial, 'x');
            $this->fail('reversed without permission');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('reverse', $e->getMessage());
        }
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('consignments.buyouts.reverse');
        try {
            $this->rev->reverse($b->fresh(), $outsider, 'x');
            $this->fail('reversed without business access');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('business', $e->getMessage());
        }
        config(['consignment.buyouts.finalization_enabled' => false]);
        try {
            $this->rev->reverse($b->fresh(), $this->user, 'x');
            $this->fail('reversed while disabled');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }
        $this->assertEquals($snap, $this->snapshot());
        $this->assertSame('FINALIZED', $b->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------- 8.4

    /** @test */
    public function custody_receiving_reversal_sees_attribution_reservations_and_finalized_buyouts_and_cancellation_removes_only_its_own_blocker(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->price($r, 100);
        $receiving = ConsignmentReceiving::find($r->consignment_receiving_id);
        $checker = new \Modules\Consignment\Services\ConsignmentBuyoutDependencyChecker();
        $svc = new ConsignmentReceivingService();
        $flagged = fn (array $blockers, string $needle) => collect($blockers)->contains(fn ($m) => str_contains($m, $needle));

        $this->assertSame([], $checker->receivingBlockers($receiving));

        // Attribution (a sale attributed to this receipt) blocks on its own.
        $this->stock('5');
        $src = $this->soldSource('5');
        $this->capacity()->setAttribution($src->id, [$r->id => '5'], null, 'attribute');
        $this->assertTrue($flagged($checker->receivingBlockers($receiving), 'atribusi'));

        // An approved buyout adds a reservation blocker; cancelling it removes ONLY that blocker.
        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $r->id, 'consignment_sold_source_id' => $src->id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => '2']], $this->user, $this->meta());
        $b = $this->life->approve($this->life->submit($b, $this->user), $this->user);
        $blockers = $checker->receivingBlockers($receiving);
        $this->assertTrue($flagged($blockers, 'dicadangkan'));
        $this->assertTrue($flagged($blockers, 'atribusi'));
        $this->assertTrue($flagged($svc->previewReversal($receiving)['blockers'], 'dicadangkan'), 'surfaced by the real receiving preview');

        $this->life->cancel($b, 'release', $this->user);
        $after = $checker->receivingBlockers($receiving);
        $this->assertFalse($flagged($after, 'dicadangkan'));
        $this->assertTrue($flagged($after, 'atribusi'), 'attribution blocker is independent and remains');

        // A finalized sold-only buyout (no stock movement at all) blocks even though balances match.
        $b2 = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $r->id, 'consignment_sold_source_id' => $src->id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => '2']], $this->user, $this->meta());
        $b2 = $this->life->approve($this->life->submit($b2, $this->user), $this->user);
        $this->fin->finalize($b2, $this->user);
        $this->assertTrue($flagged($checker->receivingBlockers($receiving), 'final'));
        $this->assertTrue($flagged($svc->previewReversal($receiving)['blockers'], 'final'));

        // Reversing the buyout removes that blocker; attribution still keeps the receipt non-reversible.
        $this->rev->reverse($b2->fresh(), $this->user, 'x');
        $final = $checker->receivingBlockers($receiving);
        $this->assertFalse($flagged($final, 'final'));
        $this->assertTrue($flagged($final, 'atribusi'));
    }
}

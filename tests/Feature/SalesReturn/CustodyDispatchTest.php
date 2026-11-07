<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Support\SalesReturn\StandardReturnCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Modules\SalesReturn\Entities\SaleReturnCustodyMovement as Movement;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

/** Group 5: customer dispatch from / alongside held custody. */
class CustodyDispatchTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn ($user, $ability) => $ability === 'saleReturns.inspect' ? null : true);
        $this->actingAs(User::factory()->create());
        $this->app->instance(DocumentNotificationService::class, \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing());
    }

    private function http(): self
    {
        return $this->withSession(['setting_id' => 1]);
    }

    /** @return array{0: array, 1: SaleReturn, 2: SaleReturnCustody} */
    private function received(int $quantity, bool $serial, ?int $taxId = null): array
    {
        $flow = $this->makeDispatchedSale($quantity, $serial);
        if ($taxId) {
            $flow['dispatchDetail']->update(['tax_id' => $taxId]);
        }
        $ids = $serial ? collect($flow['serials'])->take($quantity)->pluck('id')->all() : [];
        $return = $this->makeReturnFor($flow, $quantity, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], $ids);
        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();
        $return = $return->fresh();

        return [$flow, $return, SaleReturnCustody::query()->where('sale_return_id', $return->id)->orderBy('id')->first()];
    }

    private function awaiting(SaleReturn $return, string $method = 'REPAIR', ?int $serialId = null): Settlement
    {
        return $this->settlementFor($return, Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $method, $serialId);
    }

    private function request(Settlement $row, array $data = []): void
    {
        $this->http()->post(route('sale-return-settlements.item.dispatch', $row), $data)->assertRedirect();
    }

    private function approve(Settlement $row): void
    {
        $this->http()->post(route('sale-return-settlements.item.dispatch.approve', $row))->assertRedirect();
    }

    private function stock(int $productId, int $locationId = 1, int $nonTax = 0, int $tax = 0, ?int $taxId = null): ProductStock
    {
        return ProductStock::create([
            'product_id' => $productId, 'location_id' => $locationId, 'quantity' => $nonTax + $tax,
            'quantity_tax' => $tax, 'quantity_non_tax' => $nonTax, 'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity' => 0, 'tax_id' => $taxId,
        ]);
    }

    private function snapshot(int $productId): array
    {
        return [
            ProductStock::query()->where('product_id', $productId)->orderBy('id')->get(['quantity', 'quantity_tax', 'quantity_non_tax'])->toArray(),
            (string) \Modules\Product\Entities\Product::query()->whereKey($productId)->value('product_quantity'),
            Transaction::query()->where('product_id', $productId)->count(),
        ];
    }

    /** @test */
    public function serial_original_dispatches_from_custody_once_without_touching_sellable_stock(): void
    {
        [$flow, $return, $custody] = $this->received(1, true);
        $this->stock($flow['product']->id, 1, 5);
        $flow['product']->forceFill(['product_quantity' => 5])->save();
        $before = $this->snapshot($flow['product']->id);

        $row = $this->awaiting($return, 'REPAIR', $custody->product_serial_number_id);
        $this->request($row, ['dispatched_serial_number' => $flow['serials'][0]->serial_number]);
        $this->assertSame('ORIGINAL', $row->fresh()->dispatch_kind);
        $this->assertSame($before, $this->snapshot($flow['product']->id), 'requesting moves nothing');

        $this->approve($row);
        $this->approve($row);   // replay

        $this->assertSame(Settlement::STATUS_DISPATCHED, $row->fresh()->status);
        $this->assertEquals(1, $custody->fresh()->quantity_dispatched);
        $this->assertEquals(0, $custody->fresh()->held_quantity);
        $this->assertSame(1, Movement::query()->where('type', 'DISPATCHED')->count(), 'consumed exactly once');
        $this->assertSame($before, $this->snapshot($flow['product']->id), 'no phantom or unrelated stock change');
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $flow['serials'][0]->fresh()->status);
        $this->assertFalse((bool) $flow['serials'][0]->fresh()->is_in_return_process);
    }

    /** @test */
    public function non_serial_original_dispatch_consumes_the_custody_quantity_only(): void
    {
        [$flow, $return, $custody] = $this->received(3, false);
        $this->stock($flow['product']->id, 1, 7);
        $flow['product']->forceFill(['product_quantity' => 7])->save();
        $before = $this->snapshot($flow['product']->id);

        $row = $this->awaiting($return, 'UNPROCESSED');
        $this->request($row, ['dispatch_mode' => 'ORIGINAL']);
        $this->approve($row);

        $this->assertEquals(3, $custody->fresh()->quantity_dispatched);
        $this->assertEquals(0, $custody->fresh()->held_quantity);
        $this->assertSame($before, $this->snapshot($flow['product']->id));
    }

    /** @test */
    public function serial_replacement_consumes_the_other_unit_and_preserves_the_originals_custody(): void
    {
        [$flow, $return, $custody] = $this->received(1, true);
        $b = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'REPL-B', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_ACTIVE]);
        $this->stock($flow['product']->id, 1, 4);
        $flow['product']->forceFill(['product_quantity' => 4])->save();

        $row = $this->awaiting($return, 'REPAIR', $custody->product_serial_number_id);
        $this->request($row, ['dispatched_serial_number' => 'REPL-B']);
        $this->assertSame('REPLACEMENT', $row->fresh()->dispatch_kind);
        $this->assertSame((int) $b->id, (int) $row->fresh()->dispatch_serial_number_id);
        $this->approve($row);

        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $b->fresh()->status);
        $this->assertSame(3, (int) ProductStock::query()->where('product_id', $flow['product']->id)->value('quantity_non_tax'));
        $this->assertEquals(3, (float) $flow['product']->fresh()->product_quantity);
        $this->assertSame(1, Transaction::query()->where('product_id', $flow['product']->id)->where('type', 'DISPATCH_RETURN')->count());
        // original A: still held, custody untouched, now releasable because of the persisted identity
        $this->assertEquals(1, $custody->fresh()->held_quantity);
        $this->assertEquals(0, $custody->fresh()->quantity_dispatched);
        $this->assertTrue(\App\Support\SalesReturn\HeldSerialGuard::isHeld($flow['serials'][0]->fresh()));
        $this->assertNull(app(StandardReturnCustodyService::class)->releaseBlocker($custody->fresh()));
    }

    /** @test */
    public function non_serial_replacement_uses_persisted_identity_and_the_originals_tax_bucket(): void
    {
        $tax = Tax::query()->create(['name' => 'PPN G5 ' . uniqid(), 'value' => 11, 'is_default' => false]);
        [$flow, $return, $custody] = $this->received(2, false, $tax->id);
        $this->stock($flow['product']->id, 1, 5, 3, $tax->id);
        $flow['product']->forceFill(['product_quantity' => 8])->save();

        $row = $this->awaiting($return, 'REPAIR');
        $this->request($row, ['dispatch_mode' => 'REPLACEMENT']);
        $this->assertSame('REPLACEMENT', $row->fresh()->dispatch_kind);
        $this->assertNull($row->fresh()->dispatched_serial_number, 'no serial string involved');
        $this->approve($row);

        $stock = ProductStock::query()->where('product_id', $flow['product']->id)->sole();
        $this->assertSame(1, (int) $stock->quantity_tax, 'taxed bucket consumed');
        $this->assertSame(5, (int) $stock->quantity_non_tax, 'other bucket untouched');
        $this->assertEquals(6, (float) $flow['product']->fresh()->product_quantity);
        $this->assertEquals(2, $custody->fresh()->held_quantity, 'original custody preserved');
        $this->assertNull(app(StandardReturnCustodyService::class)->releaseBlocker($custody->fresh()), 'eligibility from persisted identity');
    }

    /** @test */
    public function invalid_replacements_and_shortages_are_rejected_without_any_change(): void
    {
        [$flow, $return, $custody] = $this->received(1, true);
        $sold = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'USED-1', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_SOLD]);
        $otherOwner = $this->seedLocation(2, 2);
        $foreign = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'FOREIGN-1', 'location_id' => $otherOwner->id, 'status' => ProductSerialNumber::STATUS_ACTIVE]);
        $noStock = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'NOSTOCK-1', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_ACTIVE]);
        $before = $this->snapshot($flow['product']->id);

        $row = $this->awaiting($return, 'REPAIR', $custody->product_serial_number_id);
        foreach (['MISSING-X', 'USED-1', 'FOREIGN-1', 'NOSTOCK-1'] as $serial) {
            $this->request($row, ['dispatched_serial_number' => $serial]);
            $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status, $serial);
            $this->assertNull($row->fresh()->dispatch_kind);
        }
        $this->assertSame($before, $this->snapshot($flow['product']->id));
        $this->assertEquals(1, $custody->fresh()->held_quantity);

        // non-serial shortage
        [$flow2, $return2, $custody2] = $this->received(2, false);
        $this->stock($flow2['product']->id, 1, 1);
        $row2 = $this->awaiting($return2, 'REPAIR');
        $this->request($row2, ['dispatch_mode' => 'REPLACEMENT']);
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row2->fresh()->status);
    }

    /** @test */
    public function an_approval_time_failure_rolls_back_every_effect(): void
    {
        [$flow, $return, $custody] = $this->received(2, false);
        $stock = $this->stock($flow['product']->id, 1, 2);
        $flow['product']->forceFill(['product_quantity' => 2])->save();
        $row = $this->awaiting($return, 'REPAIR');
        $this->request($row, ['dispatch_mode' => 'REPLACEMENT']);
        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status);

        $stock->update(['quantity' => 1, 'quantity_non_tax' => 1]);   // stock vanished after the request
        $before = $this->snapshot($flow['product']->id);
        $this->approve($row);

        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status, 'still pending');
        $this->assertSame($before, $this->snapshot($flow['product']->id));
        $this->assertEquals(2, $custody->fresh()->held_quantity);
    }

    /** @test */
    public function release_and_original_dispatch_cannot_consume_the_same_goods(): void
    {
        Gate::define('saleReturns.inspect', fn () => true);
        [$flow, $return, $custody] = $this->received(1, true);
        $row = $this->awaiting($return, 'REPAIR', $custody->product_serial_number_id);
        $this->request($row, ['dispatched_serial_number' => $flow['serials'][0]->serial_number]);

        // 1. a pending original dispatch blocks release
        $this->assertNotNull(app(StandardReturnCustodyService::class)->releaseBlocker($custody->fresh()));

        // 2. custody consumed elsewhere (concurrent release) -> approval refuses and changes nothing
        SaleReturnCustody::query()->whereKey($custody->id)->update(['quantity_released' => 1]);
        $before = $this->snapshot($flow['product']->id);
        $this->approve($row);
        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status);
        $this->assertSame(0, Movement::query()->where('type', 'DISPATCHED')->count());
        $this->assertSame($before, $this->snapshot($flow['product']->id));

        // 3. after a real original dispatch, release is exhausted-rejected
        SaleReturnCustody::query()->whereKey($custody->id)->update(['quantity_released' => 0]);
        $this->approve($row);
        $this->assertSame(Settlement::STATUS_DISPATCHED, $row->fresh()->status);
        $this->assertStringContainsString('habis', (string) app(StandardReturnCustodyService::class)->releaseBlocker($custody->fresh()));
        $this->assertEquals(1, (float) $custody->fresh()->quantity_dispatched + (float) $custody->fresh()->quantity_released + $custody->fresh()->held_quantity);
    }

    /** @test */
    public function a_rejected_dispatch_returns_to_awaiting_dispatch_and_leaves_custody_intact(): void
    {
        [$flow, $return, $custody] = $this->received(1, true);
        $row = $this->awaiting($return, 'REPAIR', $custody->product_serial_number_id);
        $this->request($row, ['dispatched_serial_number' => $flow['serials'][0]->serial_number]);

        $this->http()->post(route('sale-return-settlements.item.dispatch.reject', $row), ['rejection_reason' => 'salah serial'])->assertRedirect();

        $fresh = $row->fresh();
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $fresh->status);
        $this->assertNull($fresh->dispatch_kind);
        $this->assertNull($fresh->dispatch_serial_number_id);
        $this->assertEquals(1, $custody->fresh()->held_quantity);
        $this->assertSame(0, Movement::query()->where('type', 'DISPATCHED')->count());

        // retry succeeds from the permitted state
        $this->request($row, ['dispatched_serial_number' => $flow['serials'][0]->serial_number]);
        $this->approve($row);
        $this->assertEquals(1, $custody->fresh()->quantity_dispatched);
    }

    private function claimByTransfer(ProductSerialNumber $sn): void
    {
        $transfer = \Modules\Adjustment\Entities\Transfer::create([
            'origin_location_id' => 1, 'destination_location_id' => 1,
            'stock_condition' => \Modules\Adjustment\Entities\Transfer::CONDITION_GOOD,
            'created_by' => auth()->id(), 'status' => \Modules\Adjustment\Entities\Transfer::STATUS_APPROVED,
            'revision' => 1, 'workflow_version' => 2,
        ]);
        $movement = \Modules\Adjustment\Entities\TransferMovement::create([
            'transfer_id' => $transfer->id, 'type' => \Modules\Adjustment\Entities\TransferMovement::TYPE_FORWARD_DISPATCH,
            'status' => \Modules\Adjustment\Entities\TransferMovement::STATUS_APPROVED,
            'stock_condition' => \Modules\Adjustment\Entities\TransferMovement::CONDITION_GOOD,
            'origin_location_id' => 1, 'destination_location_id' => 1, 'transfer_revision' => 1, 'created_by' => auth()->id(),
        ]);
        $line = \Modules\Adjustment\Entities\TransferMovementLine::create([
            'transfer_movement_id' => $movement->id, 'product_id' => $sn->product_id, 'quantity' => '1.0000', 'count_confirmed' => true,
        ]);
        $row = \Modules\Adjustment\Entities\TransferMovementSerial::create([
            'transfer_movement_id' => $movement->id, 'transfer_movement_line_id' => $line->id, 'product_id' => $sn->product_id,
            'product_serial_number_id' => $sn->id, 'serial_number' => $sn->serial_number,
            'stock_condition' => \Modules\Adjustment\Entities\TransferMovement::CONDITION_GOOD,
            'transit_custody_status' => \Modules\Adjustment\Entities\TransferMovementSerial::CUSTODY_IN_TRANSIT,
            'origin_location_id' => 1, 'destination_location_id' => 1,
        ]);
        \Modules\Adjustment\Entities\TransferActiveSerialClaim::create([
            'product_serial_number_id' => $sn->id, 'transfer_movement_id' => $movement->id, 'transfer_movement_serial_id' => $row->id,
        ]);
    }

    /** @test */
    public function cannot_process_goods_can_only_be_dispatched_as_the_original(): void
    {
        [$flow, $return, $custody] = $this->received(1, true);
        $b = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'REPL-U', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_ACTIVE]);
        $this->stock($flow['product']->id, 1, 3);
        $row = $this->awaiting($return, 'UNPROCESSED', $custody->product_serial_number_id);

        $this->request($row, ['dispatched_serial_number' => 'REPL-U']);
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status, 'serial replacement refused');
        $this->assertNull($row->fresh()->dispatch_kind);

        // forged persisted identity cannot get through approval either
        $row->update(['status' => Settlement::STATUS_DISPATCH_REQUESTED, 'dispatch_kind' => 'REPLACEMENT', 'dispatch_serial_number_id' => $b->id]);
        $before = $this->snapshot($flow['product']->id);
        $this->approve($row);
        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status);
        $this->assertSame($before, $this->snapshot($flow['product']->id));
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $b->fresh()->status);

        // and even a recorded DISPATCHED/REPLACEMENT never releases cannot-process custody
        $row->update(['status' => Settlement::STATUS_DISPATCHED]);
        $this->assertStringContainsString('terikat', (string) app(StandardReturnCustodyService::class)->releaseBlocker($custody->fresh()));

        // non-serial too
        [$flow2, $return2] = $this->received(2, false);
        $this->stock($flow2['product']->id, 1, 5);
        $row2 = $this->awaiting($return2, 'UNPROCESSED');
        $this->request($row2, ['dispatch_mode' => 'REPLACEMENT']);
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row2->fresh()->status);
    }

    /** @test */
    public function replacement_serials_with_competing_claims_are_refused_at_request_and_approval(): void
    {
        [$flow, $return, $custody] = $this->received(1, true);
        $b = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'REPL-T', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_ACTIVE]);
        $c = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'REPL-C', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_ACTIVE]);
        $this->stock($flow['product']->id, 1, 3);
        $this->claimByTransfer($b);
        $this->assertFalse($b->fresh()->isSellable());

        $row = $this->awaiting($return, 'REPAIR', $custody->product_serial_number_id);
        $this->request($row, ['dispatched_serial_number' => 'REPL-T']);
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status, 'transfer-claimed serial refused at request');

        // claimed AFTER a valid request: approval revalidates
        $this->request($row, ['dispatched_serial_number' => 'REPL-C']);
        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status);
        $this->claimByTransfer($c);
        $before = $this->snapshot($flow['product']->id);
        $this->approve($row);
        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status);
        $this->assertSame($before, $this->snapshot($flow['product']->id));
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $c->fresh()->status);
    }

    /** @test */
    public function an_existing_pos_replacement_claim_blocks_the_serial(): void
    {
        [$flow, $return, $custody] = $this->received(1, true);
        ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'REPL-P', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_ACTIVE]);
        $this->stock($flow['product']->id, 1, 3);
        // The POS guard owns the claim rules (draft/pending/approved/awaiting-dispatch lines); here we prove the wiring.
        $this->app->instance(\Modules\Pos\Services\PosReturnReplacementGuard::class, \Mockery::mock(\Modules\Pos\Services\PosReturnReplacementGuard::class, function ($m) {
            $m->shouldReceive('validateReplacementSerial')->andThrow(\Illuminate\Validation\ValidationException::withMessages(['replacement_serial' => 'Serial pengganti sedang digunakan oleh retur draft lain.']));
        }));

        $row = $this->awaiting($return, 'REPAIR', $custody->product_serial_number_id);
        $this->request($row, ['dispatched_serial_number' => 'REPL-P']);
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status);
    }

    /** @test */
    public function two_returns_cannot_request_the_same_replacement_serial(): void
    {
        [$flow, $return, $custody] = $this->received(1, true);
        $b = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'REPL-D', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_ACTIVE]);
        $this->stock($flow['product']->id, 1, 3);
        // another pending dispatch already claims serial B as its replacement
        $other = $this->settlementFor($return, Settlement::STATUS_DISPATCH_REQUESTED, 'REPAIR', null);
        $other->update(['dispatch_kind' => 'REPLACEMENT', 'dispatch_serial_number_id' => $b->id]);

        $mine = $this->awaiting($return, 'REPAIR', $custody->product_serial_number_id);
        $this->request($mine, ['dispatched_serial_number' => 'REPL-D']);
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $mine->fresh()->status);
        $this->assertNull($mine->fresh()->dispatch_kind);
    }

    /** @test */
    public function dispatch_and_completion_are_one_transaction(): void
    {
        [$flow, $return, $custody] = $this->received(1, true);
        $row = $this->awaiting($return, 'REPAIR', $custody->product_serial_number_id);
        $this->request($row, ['dispatched_serial_number' => $flow['serials'][0]->serial_number]);

        $this->app->instance(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class, \Mockery::mock(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class, function ($m) {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('syncSaleReturnCompletionRollup')->andThrow(new \RuntimeException('injected completion failure'));
        }));
        $this->approve($row);

        $this->assertSame(Settlement::STATUS_DISPATCH_REQUESTED, $row->fresh()->status, 'dispatch rolled back with the failed completion');
        $this->assertEquals(0, $custody->fresh()->quantity_dispatched);
        $this->assertSame(0, Movement::query()->where('type', 'DISPATCHED')->count());
        $this->assertSame(ProductSerialNumber::STATUS_RETURN_IN_PROCESS, $flow['serials'][0]->fresh()->status);
        $this->assertTrue(\App\Support\SalesReturn\HeldSerialGuard::isHeld($flow['serials'][0]->fresh()));
    }
}

<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Support\SalesReturn\HeldSerialException;
use App\Support\SalesReturn\HeldSerialGuard;
use App\Support\SalesReturn\SalesReturnStateException;
use App\Support\SalesReturn\StandardReturnCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Modules\SalesReturn\Entities\SaleReturnCustodyMovement;
use Modules\SalesReturn\Entities\SaleReturnInspection;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

/** Held custody: receiving, inspection/release, conservation and eligibility (group 4). */
class StandardCustodyTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        // Everything is allowed EXCEPT the dedicated inspection permission, which tests grant explicitly.
        Gate::before(fn ($user, $ability) => $ability === 'saleReturns.inspect' ? null : true);
        $this->actingAs(User::factory()->create());
        $this->app->instance(DocumentNotificationService::class, \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing());
    }

    private function http(): self
    {
        return $this->withSession(['setting_id' => 1]);
    }

    private function grantInspect(): void
    {
        Gate::define('saleReturns.inspect', fn () => true);
    }

    /** @return array{0: array, 1: SaleReturn} */
    private function received(int $quantity = 3, bool $serial = false, ?int $taxId = null): array
    {
        $flow = $this->makeDispatchedSale($quantity, $serial);
        if ($taxId) {
            $flow['dispatchDetail']->update(['tax_id' => $taxId]);
        }
        $serialIds = $serial ? collect($flow['serials'])->take($quantity)->pluck('id')->all() : [];
        $return = $this->makeReturnFor($flow, $quantity, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], $serialIds);

        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();

        return [$flow, $return->fresh()];
    }

    private function settle(SaleReturn $return, string $method, string $status, ?int $serialId = null, array $extra = []): Settlement
    {
        $row = $this->settlementFor($return, $status, $method, $serialId);
        $row->update($extra);

        return $row;
    }

    private function stockSum(int $productId): int
    {
        return (int) ProductStock::query()->where('product_id', $productId)->sum('quantity');
    }

    // ------------------------------------------------------------------ 4.2 receiving

    /** @test */
    public function first_receiving_holds_goods_without_increasing_sellable_stock(): void
    {
        [$flow, $return] = $this->received(3);

        $this->assertSame(0, $this->stockSum($flow['product']->id));
        $this->assertSame(0, (int) $flow['product']->fresh()->product_quantity);
        $this->assertSame(0, Transaction::query()->where('product_id', $flow['product']->id)->count());

        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();
        $this->assertEquals(3, $custody->held_quantity);
        $this->assertEquals(3, (int) $custody->quantity_received);
        $this->assertSame(1, (int) $custody->location_id);
        $this->assertSame(1, (int) $custody->owner_setting_id);
        $this->assertSame((int) $flow['dispatchDetail']->id, (int) $custody->dispatch_detail_id);
        $this->assertSame(1, (int) $return->custody_policy_version);
        $this->assertNotNull($return->custody_policy_applied_at);

        $movement = SaleReturnCustodyMovement::query()->where('sale_return_custody_id', $custody->id)->sole();
        $this->assertSame('RECEIVED', $movement->type);
        $this->assertSame(3, (int) $movement->quantity);
        $this->assertNotNull($movement->actor_id);
        $this->assertNotNull($movement->occurred_at);
    }

    /** @test */
    public function received_serials_are_held_one_custody_per_occurrence_and_hidden_from_every_scope(): void
    {
        [$flow, $return] = $this->received(2, true);

        $this->assertSame(2, SaleReturnCustody::query()->where('sale_return_id', $return->id)->count());
        foreach ($flow['serials'] as $serial) {
            $fresh = $serial->fresh();
            $this->assertSame(ProductSerialNumber::STATUS_RETURN_IN_PROCESS, $fresh->status);
            $this->assertTrue((bool) $fresh->is_in_return_process);
            $this->assertNotNull($fresh->dispatch_detail_id, 'lineage anchor kept');
            $this->assertFalse($fresh->isSellable());
            $this->assertTrue(HeldSerialGuard::isHeld($fresh));
            $this->assertSame(0, ProductSerialNumber::query()->sellable()->whereKey($fresh->id)->count());
            $this->assertSame(0, ProductSerialNumber::query()->available()->whereKey($fresh->id)->count());
            $this->assertSame(0, ProductSerialNumber::query()->availableBroken()->whereKey($fresh->id)->count());
        }
        $this->assertDatabaseHas('serial_number_histories', ['product_serial_number_id' => $flow['serials'][0]->id, 'event_type' => 'SALE_RETURNED']);
    }

    /** @test */
    public function receiving_replay_records_custody_and_history_once(): void
    {
        $flow = $this->makeDispatchedSale(2, true);
        $return = $this->makeReturnFor($flow, 2, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], collect($flow['serials'])->pluck('id')->all());
        $stale = SaleReturn::find($return->id);

        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();
        $this->http()->post(route('sale-returns.receive', $stale))->assertRedirect();   // replay with a stale model

        $this->assertSame(2, SaleReturnCustody::query()->count());
        $this->assertSame(2, SaleReturnCustodyMovement::query()->where('type', 'RECEIVED')->count());
        $this->assertSame(2, DB::table('serial_number_histories')->where('event_type', 'SALE_RETURNED')->count());
    }

    /** @test */
    public function the_database_refuses_a_second_custody_for_the_same_physical_occurrence(): void
    {
        [, $return] = $this->received(1);
        $existing = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();

        $this->expectException(\Illuminate\Database\QueryException::class);
        SaleReturnCustody::create($existing->only([
            'sale_return_id', 'sale_return_detail_id', 'sale_id', 'dispatch_detail_id', 'product_id', 'owner_setting_id',
            'location_id', 'tax_id', 'policy_version', 'quantity_received', 'occurrence_key',
        ]));
    }

    /** @test */
    public function older_unreceived_returns_adopt_the_policy_on_first_receiving_and_received_ones_stay_untouched(): void
    {
        // A return received BEFORE the policy: no custody, NULL markers, stock already counted.
        $flow = $this->makeDispatchedSale(2);
        $legacy = $this->makeReturnFor($flow, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()]);
        $before = $legacy->fresh()->toArray();

        // An older return still awaiting receiving: adopts the policy only when first received.
        $older = $this->makeReturnFor($flow, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);
        $this->assertNull($older->custody_policy_version);

        $this->http()->post(route('sale-returns.receive', $older))->assertRedirect();

        $this->assertSame(1, (int) $older->fresh()->custody_policy_version);
        $this->assertSame(1, SaleReturnCustody::query()->where('sale_return_id', $older->id)->count());
        $this->assertSame($before, $legacy->fresh()->toArray(), 'already-received record is unchanged');
        $this->assertSame(0, SaleReturnCustody::query()->where('sale_return_id', $legacy->id)->count(), 'no backfilled custody');
        $this->assertNull($legacy->fresh()->custody_policy_version);
    }

    /** @test */
    public function with_custody_disabled_new_receiving_is_blocked_and_never_makes_goods_sellable(): void
    {
        config(['sales_return.custody_enabled' => false]);
        $flow = $this->makeDispatchedSale(2);
        $return = $this->makeReturnFor($flow, 2, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);

        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();

        $this->assertSame(0, $this->stockSum($flow['product']->id), 'no sellable stock');
        $this->assertSame(0, (int) $flow['product']->fresh()->product_quantity);
        $this->assertSame(0, SaleReturnCustody::query()->count());
        $this->assertSame('AWAITING RECEIVING', strtoupper($return->fresh()->status), 'the return stays receivable once enabled');
        $this->assertNull($return->fresh()->custody_policy_version);
    }

    /** @test */
    public function with_custody_disabled_existing_custody_stays_visible_and_releasable(): void
    {
        $this->grantInspect();
        [$flow, $return] = $this->received(2);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();

        config(['sales_return.custody_enabled' => false]);

        $this->http()->get(route('sale-returns.show', $return))->assertSuccessful();
        $this->http()->get(route('sale-returns.inspect', $return))->assertSuccessful();
        $this->http()->post(route('sale-returns.inspect.store', $return), [
            'notes' => 'Lepas saat flag mati', 'request_key' => 'flag-off',
            'release' => [$custody->id => ['selected' => 1, 'quantity' => 2]],
        ])->assertRedirect();

        $this->assertSame(2, $this->stockSum($flow['product']->id));
        $this->assertEquals(0, $custody->fresh()->held_quantity);
    }

    /** @test */
    public function release_preserves_fractional_stock_in_buckets_global_quantity_and_evidence(): void
    {
        $this->grantInspect();
        $flow = $this->makeDispatchedSale(5);
        $flow['product']->forceFill(['product_quantity' => 10.5])->save();
        ProductStock::create([
            'product_id' => $flow['product']->id, 'location_id' => 1, 'quantity' => 10.5,
            'quantity_tax' => 0, 'quantity_non_tax' => 10.5, 'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity' => 0,
        ]);
        $return = $this->makeReturnFor($flow, 2, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);
        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();

        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();
        $this->settle($return->fresh(), 'CASH_REFUND', Settlement::STATUS_APPROVED);

        foreach ([['k1', 1], ['k2', 1]] as [$key, $qty]) {
            $this->http()->post(route('sale-returns.inspect.store', $return), [
                'notes' => 'Pecahan ' . $key, 'request_key' => $key,
                'release' => [$custody->id => ['selected' => 1, 'quantity' => $qty]],
            ])->assertRedirect();
        }

        $stock = ProductStock::query()->where('product_id', $flow['product']->id)->sole();
        $this->assertEquals(12.5, (float) $stock->quantity_non_tax);   // 10.5 + 1 + 1, no integer truncation
        $this->assertEquals(12.5, (float) $stock->quantity);
        $this->assertEquals(12.5, (float) $flow['product']->fresh()->product_quantity);
        $this->assertEquals(0, $custody->fresh()->held_quantity);
        $this->assertEquals([1.0, 1.0], SaleReturnCustodyMovement::query()->where('type', 'RELEASED')->orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertEquals([10.5, 11.5], Transaction::query()->where('product_id', $flow['product']->id)->orderBy('id')->pluck('previous_quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertEquals(2.0, (float) SaleReturnInspection::query()->sum('released_quantity'));
    }

    /** @test */
    public function a_stale_serial_model_cannot_bypass_custody(): void
    {
        $flow = $this->makeDispatchedSale(1, true);
        $stale = ProductSerialNumber::find($flow['serials'][0]->id);   // loaded BEFORE receiving: SOLD, not a return
        $return = $this->makeReturnFor($flow, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [$stale->id]);
        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();

        foreach ([
            ['status' => ProductSerialNumber::STATUS_ACTIVE],
            ['dispatch_detail_id' => null],
            ['product_id' => $this->makeProduct(true)->id],
        ] as $change) {
            $model = ProductSerialNumber::find($stale->id);
            $model->setRawAttributes(array_merge($model->getAttributes(), $stale->getAttributes()), true);   // pre-receiving state in memory
            try {
                $model->forceFill($change)->save();
                $this->fail('A stale instance must not change a held serial: ' . json_encode($change));
            } catch (HeldSerialException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(ProductSerialNumber::STATUS_RETURN_IN_PROCESS, $stale->fresh()->status);
        $this->assertSame((int) $flow['product']->id, (int) $stale->fresh()->product_id);
    }

    /** @test */
    public function the_permission_migration_registers_but_never_grants_it(): void
    {
        $role = \Spatie\Permission\Models\Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        (require base_path('Modules/SalesReturn/Database/Migrations/2026_11_08_000200_register_sale_return_inspect_permission.php'))->up();

        $this->assertTrue(\Spatie\Permission\Models\Permission::where('name', 'saleReturns.inspect')->exists());
        $this->assertFalse($role->fresh()->hasPermissionTo('saleReturns.inspect'));
        $this->assertSame(0, \DB::table('role_has_permissions')->count());
    }

    /** @test */
    public function rolling_back_the_custody_tables_is_refused_once_they_hold_evidence(): void
    {
        $this->received(1);

        try {
            (require base_path('Modules/SalesReturn/Database/Migrations/2026_11_08_000100_create_sale_return_custody_tables.php'))->down();
            $this->fail('A populated ledger must not be dropped.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback refused', $e->getMessage());
        }

        $this->assertSame(1, SaleReturnCustody::query()->count());
        $this->assertSame(1, SaleReturnCustodyMovement::query()->count());
    }

    // ------------------------------------------------------------------ 4.3 inspection permission + records

    /** @test */
    public function inspection_requires_its_dedicated_permission_everywhere(): void
    {
        [$flow, $return] = $this->received(2);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();

        $this->http()->get(route('sale-returns.inspect', $return))->assertForbidden();
        $this->http()->post(route('sale-returns.inspect.store', $return), ['notes' => 'ok', 'release' => [$custody->id => ['selected' => 1, 'quantity' => 1]]])->assertForbidden();

        try {
            app(StandardReturnCustodyService::class)->release($return, [['custody_id' => $custody->id, 'quantity' => 1]], 'ok');
            $this->fail('The service itself must enforce the permission.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, $this->stockSum($flow['product']->id));
        $this->assertEquals(2, $custody->fresh()->held_quantity);
        $this->assertSame(0, SaleReturnInspection::query()->count());

        $this->grantInspect();
        $this->http()->get(route('sale-returns.inspect', $return))->assertSuccessful();
    }

    /** @test */
    public function the_permission_is_registered_in_the_central_permission_config(): void
    {
        $groups = require base_path('app/Config/Permissions.php');
        $this->assertArrayHasKey('saleReturns.inspect', $groups['Retur Penjualan']);
    }

    /** @test */
    public function release_records_actor_time_notes_quantity_and_the_destination_bucket(): void
    {
        $this->grantInspect();
        [$flow, $return] = $this->received(3);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();

        $this->http()->post(route('sale-returns.inspect.store', $return), [
            'notes' => 'Kondisi baik, segel utuh', 'request_key' => 'req-1',
            'release' => [$custody->id => ['selected' => 1, 'quantity' => 2]],
        ])->assertRedirect(route('sale-returns.show', $return));

        $inspection = SaleReturnInspection::query()->sole();
        $this->assertSame(auth()->id(), (int) $inspection->inspected_by);
        $this->assertNotNull($inspection->inspected_at);
        $this->assertStringContainsString('SEGEL UTUH', strtoupper($inspection->notes));
        $this->assertSame(2, (int) $inspection->released_quantity);

        $movement = SaleReturnCustodyMovement::query()->where('type', 'RELEASED')->sole();
        $this->assertSame($inspection->id, (int) $movement->inspection_id);
        $this->assertSame(2, (int) $movement->quantity);
        $this->assertSame(1, (int) $movement->destination_setting_id);
        $this->assertSame(1, (int) $movement->destination_location_id);
        $this->assertNull($movement->destination_tax_id);
        $this->assertSame(auth()->id(), (int) $movement->actor_id);
    }

    /** @test */
    public function notes_and_a_selection_are_mandatory(): void
    {
        $this->grantInspect();
        [, $return] = $this->received(2);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();

        $this->http()->post(route('sale-returns.inspect.store', $return), ['notes' => '', 'release' => [$custody->id => ['selected' => 1, 'quantity' => 1]]])->assertSessionHasErrors('notes');
        $this->http()->post(route('sale-returns.inspect.store', $return), ['notes' => 'oke baik', 'release' => [$custody->id => ['quantity' => 1]]])->assertSessionHas('error');

        $this->assertSame(0, SaleReturnInspection::query()->count());
        $this->assertEquals(2, $custody->fresh()->held_quantity);
    }

    // ------------------------------------------------------------------ 4.4 release, conservation, buckets

    /** @test */
    public function partial_and_repeated_releases_conserve_custody_and_update_stock_exactly_once(): void
    {
        $this->grantInspect();
        [$flow, $return] = $this->received(3);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();
        $service = app(StandardReturnCustodyService::class);

        $service->release($return, [['custody_id' => $custody->id, 'quantity' => 1]], 'sebagian', 'k1');
        $this->assertSame(1, $this->stockSum($flow['product']->id));
        $this->assertSame(1, (int) $flow['product']->fresh()->product_quantity);
        $this->assertEquals(2, $custody->fresh()->held_quantity);

        $service->release($return, [['custody_id' => $custody->id, 'quantity' => 2]], 'sisanya', 'k2');
        $this->assertSame(3, $this->stockSum($flow['product']->id));
        $this->assertSame(3, (int) $flow['product']->fresh()->product_quantity);

        $fresh = $custody->fresh();
        $this->assertEquals(0, $fresh->held_quantity);
        $this->assertEquals((int) $fresh->quantity_received, (int) $fresh->quantity_released + (int) $fresh->quantity_dispatched + $fresh->held_quantity, 'held + released + dispatched = received');
        $this->assertSame(3, (int) SaleReturnCustodyMovement::query()->where('type', 'RELEASED')->sum('quantity'));

        // exhausted: nothing changes
        try {
            $service->release($return, [['custody_id' => $custody->id, 'quantity' => 1]], 'lagi', 'k3');
            $this->fail('Exhausted custody must be rejected.');
        } catch (SalesReturnStateException $e) {
            $this->assertStringContainsString('custody', strtolower($e->getMessage()));
        }
        $this->assertSame(3, $this->stockSum($flow['product']->id));
        $this->assertSame(3, (int) $flow['product']->fresh()->product_quantity);
        $this->assertSame(2, SaleReturnInspection::query()->count());
    }

    /** @test */
    public function an_over_release_is_rejected_atomically_across_all_selections(): void
    {
        $this->grantInspect();
        [$flow, $return] = $this->received(2, true);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED, $flow['serials'][0]->id);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED, $flow['serials'][1]->id);
        $custodies = SaleReturnCustody::query()->where('sale_return_id', $return->id)->orderBy('id')->get();

        try {
            app(StandardReturnCustodyService::class)->release($return, [
                ['custody_id' => $custodies[0]->id, 'quantity' => 1],
                ['custody_id' => $custodies[1]->id, 'quantity' => 2],   // a serial can only be released as 1
            ], 'campuran');
            $this->fail('The whole request must fail.');
        } catch (SalesReturnStateException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->stockSum($flow['product']->id), 'the valid first selection was rolled back too');
        $this->assertEquals(1, $custodies[0]->fresh()->held_quantity);
        $this->assertSame(0, SaleReturnInspection::query()->count());
    }

    /** @test */
    public function serial_release_activates_the_serial_once_and_cannot_repeat(): void
    {
        $this->grantInspect();
        [$flow, $return] = $this->received(2, true);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED, $flow['serials'][0]->id);
        $custody = SaleReturnCustody::query()->where('product_serial_number_id', $flow['serials'][0]->id)->sole();
        $service = app(StandardReturnCustodyService::class);

        $service->release($return, [['custody_id' => $custody->id]], 'serial baik');

        $serial = $flow['serials'][0]->fresh();
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $serial->status);
        $this->assertFalse((bool) $serial->is_in_return_process);
        $this->assertNull($serial->dispatch_detail_id);
        $this->assertTrue($serial->isSellable());
        $this->assertSame(1, $this->stockSum($flow['product']->id));
        $this->assertFalse(HeldSerialGuard::isHeld($serial));
        // the other serial is still held
        $this->assertFalse($flow['serials'][1]->fresh()->isSellable());

        $this->expectException(SalesReturnStateException::class);
        $service->release($return, [['custody_id' => $custody->id]], 'ulang');
    }

    /** @test */
    public function release_credits_the_tax_bucket_captured_at_receiving(): void
    {
        $this->grantInspect();
        $tax = Tax::query()->create(['name' => 'PPN CUSTODY ' . uniqid(), 'value' => 11, 'is_default' => false]);
        [$flow, $return] = $this->received(2, false, $tax->id);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();
        $this->assertSame($tax->id, (int) $custody->tax_id);

        app(StandardReturnCustodyService::class)->release($return, [['custody_id' => $custody->id, 'quantity' => 2]], 'pkp');

        $stock = ProductStock::query()->where('product_id', $flow['product']->id)->sole();
        $this->assertSame(2, (int) $stock->quantity_tax);
        $this->assertSame(0, (int) $stock->quantity_non_tax);
        $this->assertSame(2, (int) $stock->quantity);
        $this->assertDatabaseHas('transactions', ['product_id' => $flow['product']->id, 'type' => 'SALE_RETURN_RELEASE_TAX', 'quantity' => 2]);
    }

    /** @test */
    public function a_duplicate_inspection_request_key_is_applied_only_once(): void
    {
        $this->grantInspect();
        [$flow, $return] = $this->received(3);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();
        $service = app(StandardReturnCustodyService::class);

        $service->release($return, [['custody_id' => $custody->id, 'quantity' => 1]], 'sekali', 'same-key');
        try {
            $service->release($return, [['custody_id' => $custody->id, 'quantity' => 1]], 'sekali', 'same-key');
            $this->fail('A replayed submission must be refused.');
        } catch (SalesReturnStateException $e) {
            $this->assertStringContainsString('sudah diproses', $e->getMessage());
        }

        $this->assertSame(1, $this->stockSum($flow['product']->id));
        $this->assertEquals(2, $custody->fresh()->held_quantity);
    }

    /** @test */
    public function inspection_remains_available_after_the_source_sale_is_archived(): void
    {
        $this->grantInspect();
        [$flow, $return] = $this->received(1);
        $this->settle($return, 'CASH_REFUND', Settlement::STATUS_APPROVED);
        Sale::withArchived()->whereKey($flow['sale']->id)->update(['archived_at' => now(), 'archived_by' => 1]);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();

        app(StandardReturnCustodyService::class)->release($return, [['custody_id' => $custody->id]], 'arsip');

        $this->assertSame(1, $this->stockSum($flow['product']->id));
    }

    // ------------------------------------------------------------------ eligibility: settlement never releases

    /** @test */
    public function settlement_alone_never_releases_goods_and_customer_bound_goods_are_ineligible(): void
    {
        $this->grantInspect();
        [$flow, $return] = $this->received(1);
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();
        $service = app(StandardReturnCustodyService::class);

        // no settlement decision yet
        $this->assertNotNull($service->releaseBlocker($custody));

        // completed cash refund: eligible, but NOT released
        $cash = $this->settle($return, 'CASH_REFUND', Settlement::STATUS_SUBMITTED);
        $this->http()->post(route('sale-return-settlements.item.approve', $cash))->assertSessionHas('success');
        $this->assertSame('COMPLETED', strtoupper((string) $return->fresh()->status));
        $this->assertNull($service->releaseBlocker($custody->fresh()));
        $this->assertSame(0, $this->stockSum($flow['product']->id), 'financial completion did not release anything');
        $this->assertEquals(1, $custody->fresh()->held_quantity);
        $this->assertSame(0, SaleReturnInspection::query()->count());
    }

    /** @test */
    public function repair_and_cannot_process_goods_are_customer_bound_but_a_replaced_original_is_retained(): void
    {
        [$flow, $return] = $this->received(1, true);
        $serial = $flow['serials'][0];
        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();
        $service = app(StandardReturnCustodyService::class);

        foreach ([Settlement::STATUS_APPROVED_AWAITING_DISPATCH, Settlement::STATUS_DISPATCH_REQUESTED] as $status) {
            $row = $this->settle($return, 'REPAIR', $status, $serial->id);
            $this->assertStringContainsString('terikat pengiriman', (string) $service->releaseBlocker($custody->fresh()));
            $row->delete();
        }

        $unprocessed = $this->settle($return, 'UNPROCESSED', Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $serial->id);
        $this->assertStringContainsString('terikat pengiriman', (string) $service->releaseBlocker($custody->fresh()));
        $unprocessed->delete();

        // same serial sent back after repair: stays customer-bound
        $same = $this->settle($return, 'REPAIR', Settlement::STATUS_DISPATCHED, $serial->id, ['dispatched_serial_number' => $serial->serial_number, 'dispatch_kind' => 'ORIGINAL']);
        $this->assertNotNull($service->releaseBlocker($custody->fresh()));
        $same->delete();

        // a DIFFERENT unit was dispatched as replacement: the original is retained, yet stays held until inspected
        // a different serial STRING without persisted REPLACEMENT identity proves nothing
        $string = $this->settle($return, 'REPAIR', Settlement::STATUS_DISPATCHED, $serial->id, ['dispatched_serial_number' => 'OTHER-UNIT-1']);
        $this->assertNotNull($service->releaseBlocker($custody->fresh()));
        $string->update(['dispatch_kind' => 'REPLACEMENT']);
        $this->assertNull($service->releaseBlocker($custody->fresh()));
        $this->assertEquals(1, $custody->fresh()->held_quantity, 'replacement dispatch alone does not release the original');
    }

    // ------------------------------------------------------------------ 4.5 display

    /** @test */
    public function a_completed_cash_return_still_shows_actionable_retained_custody_that_is_not_sellable(): void
    {
        $this->grantInspect();
        [$flow, $return] = $this->received(2);
        $cash = $this->settle($return, 'CASH_REFUND', Settlement::STATUS_SUBMITTED);
        $this->http()->post(route('sale-return-settlements.item.approve', $cash))->assertSessionHas('success');
        $this->assertSame('COMPLETED', strtoupper((string) $return->fresh()->status));

        $summary = app(StandardReturnCustodyService::class)->summary($return->fresh());
        $this->assertEquals(2, $summary['held']);
        $this->assertEquals(0, $summary['released']);
        $this->assertEquals(0, $summary['dispatched']);

        $page = $this->http()->get(route('sale-returns.show', $return));
        $page->assertSuccessful();
        $page->assertSee('custody-held', false);
        $page->assertSee(route('sale-returns.inspect', $return), false);

        // physical reconciliation: warehouse physical = sellable stock + held custody; held is NOT sellable
        $sellable = $this->stockSum($flow['product']->id);
        $this->assertSame(0, $sellable);
        $this->assertEquals(2, $sellable + $summary['held']);
    }

    /** @test */
    public function legacy_returns_have_no_custody_panel_and_inspection_is_refused_with_audit_guidance(): void
    {
        $this->grantInspect();
        $flow = $this->makeDispatchedSale(1);
        $legacy = $this->makeReturnFor($flow, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()]);

        $this->http()->get(route('sale-returns.show', $legacy))->assertDontSee('custody-held', false);

        try {
            app(StandardReturnCustodyService::class)->release($legacy, [['custody_id' => 1]], 'legacy');
            $this->fail('A legacy return has no custody to release.');
        } catch (SalesReturnStateException $e) {
            $this->assertStringContainsString('audit', strtolower($e->getMessage()));
        }
    }

    // ------------------------------------------------------------------ 4.1 ledger integrity

    /** @test */
    public function movements_are_append_only_and_custody_never_goes_negative(): void
    {
        [, $return] = $this->received(1);
        $movement = SaleReturnCustodyMovement::query()->firstOrFail();

        try { $movement->update(['quantity' => 99]); $this->fail('movements are append-only'); } catch (\LogicException $e) { $this->addToAssertionCount(1); }
        try { $movement->delete(); $this->fail('movements are append-only'); } catch (\LogicException $e) { $this->addToAssertionCount(1); }

        $custody = SaleReturnCustody::query()->where('sale_return_id', $return->id)->sole();
        $this->assertEquals(0, $custody->fresh()->quantity_released);
        $this->assertEquals(1, $custody->held_quantity);
    }

    /** @test */
    public function migration_leaves_existing_records_without_policy_and_has_no_backfilled_balances(): void
    {
        $flow = $this->makeDispatchedSale(1);
        $legacy = $this->makeReturnFor($flow, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()]);

        $this->assertNull($legacy->fresh()->custody_policy_version);
        $this->assertNull($legacy->fresh()->custody_policy_applied_at);
        $this->assertSame(0, SaleReturnCustody::query()->count());
        $this->assertSame(0, SaleReturnCustodyMovement::query()->count());
    }

    // ------------------------------------------------------------------ 4.7 walkthrough

    /** @test */
    public function ordinary_walkthrough_receive_settle_inspect_release_for_serial_and_non_serial_returns(): void
    {
        $this->grantInspect();
        $serialFlow = $this->makeDispatchedSale(2, true);
        $plainFlow = $this->makeDispatchedSale(4, false);

        $serialReturn = $this->makeReturnFor($serialFlow, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [$serialFlow['serials'][0]->id]);
        $plainReturn = $this->makeReturnFor($plainFlow, 3, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);

        // 1. receive: held, not sellable
        foreach ([$serialReturn, $plainReturn] as $return) {
            $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();
        }
        $service = app(StandardReturnCustodyService::class);
        $this->assertEquals(1, $service->summary($serialReturn->fresh())['held']);
        $this->assertEquals(3, $service->summary($plainReturn->fresh())['held']);
        $this->assertSame(0, $this->stockSum($serialFlow['product']->id) + $this->stockSum($plainFlow['product']->id));

        // 2. cash refund settlement completes the return: still held
        foreach ([[$serialReturn, $serialFlow['serials'][0]->id], [$plainReturn, null]] as [$return, $serialId]) {
            $row = $this->settle($return->fresh(), 'CASH_REFUND', Settlement::STATUS_SUBMITTED, $serialId);
            $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertSessionHas('success');
            $this->assertSame('COMPLETED', strtoupper((string) $return->fresh()->status));
        }
        $this->assertSame(0, $this->stockSum($plainFlow['product']->id));
        $this->assertEquals(3, $service->summary($plainReturn->fresh())['held']);

        // 3. inspect + release: serial fully, non-serial in two steps
        $serialCustody = SaleReturnCustody::query()->where('sale_return_id', $serialReturn->id)->sole();
        $plainCustody = SaleReturnCustody::query()->where('sale_return_id', $plainReturn->id)->sole();

        $this->http()->post(route('sale-returns.inspect.store', $serialReturn), [
            'notes' => 'Serial diperiksa, baik', 'request_key' => 'wt-1', 'release' => [$serialCustody->id => ['selected' => 1, 'quantity' => 1]],
        ])->assertRedirect();
        $this->http()->post(route('sale-returns.inspect.store', $plainReturn), [
            'notes' => 'Tahap satu', 'request_key' => 'wt-2', 'release' => [$plainCustody->id => ['selected' => 1, 'quantity' => 2]],
        ])->assertRedirect();
        $this->http()->post(route('sale-returns.inspect.store', $plainReturn), [
            'notes' => 'Tahap dua', 'request_key' => 'wt-3', 'release' => [$plainCustody->id => ['selected' => 1, 'quantity' => 1]],
        ])->assertRedirect();

        // 4. outcome: sellable again exactly once, nothing held, ledger balanced
        $this->assertTrue($serialFlow['serials'][0]->fresh()->isSellable());
        $this->assertSame(1, $this->stockSum($serialFlow['product']->id));
        $this->assertSame(3, $this->stockSum($plainFlow['product']->id));
        $this->assertSame(3, (int) $plainFlow['product']->fresh()->product_quantity);
        foreach ([$serialReturn, $plainReturn] as $return) {
            $summary = $service->summary($return->fresh());
            $this->assertEquals(0, $summary['held']);
            $this->assertEquals($summary['received'], $summary['released'] + $summary['dispatched'] + $summary['held']);
        }
        $this->assertSame(3, SaleReturnInspection::query()->count());
    }
}

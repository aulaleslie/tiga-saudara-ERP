<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\SalesReturn\SaleReturnCreateForm;
use App\Livewire\SalesReturn\SaleReturnEditForm;
use App\Livewire\SalesReturn\SaleSerialNumberLoader;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Tests\TestCase;

class StandardSerialRegistrationFlowTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->app->instance(DocumentNotificationService::class, \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing());
    }

    /** Untracked 3-unit sale whose product now requires serials. */
    private function untrackedFlow(int $qty = 3): array
    {
        $flow = $this->makeDispatchedSale($qty, false);
        $flow['product']->update(['serial_number_required' => true]);

        return $flow;
    }

    private function row(array $flow, array $serials, ?int $quantity = null): array
    {
        return [
            'dispatch_detail_id' => $flow['dispatchDetail']->id, 'product_id' => $flow['product']->id,
            'sale_detail_id' => $flow['saleDetail']->id, 'serial_number_required' => true,
            'quantity' => $quantity ?? count($serials), 'unit_price' => 1000, 'total' => 1000 * ($quantity ?? count($serials)),
            'available_quantity' => 3,
            'serial_numbers' => array_map(fn ($t) => ['id' => null, 'serial_number' => $t, 'pending' => true], $serials),
        ];
    }

    private function submit(array $flow, array $row)
    {
        return Livewire::test(SaleReturnCreateForm::class)
            ->set('sale_id', $flow['sale']->id)->set('date', now()->toDateString())->set('rows', [$row])->call('submit');
    }

    /** @test */
    public function draft_with_unknown_serials_persists_text_only_and_reopens(): void
    {
        $flow = $this->untrackedFlow();

        $this->submit($flow, $this->row($flow, ['sn-a', 'sn-b']))->assertHasNoErrors();

        $return = SaleReturn::sole();
        $this->assertSame(0, ProductSerialNumber::count(), 'no serial inventory before receiving');
        $this->assertEquals(['SN-A', 'SN-B'], ReturnSerialRegistration::orderBy('id')->pluck('proposed_serial')->all());
        $this->assertSame([], $return->saleReturnDetails->first()->serial_number_ids);
        $this->assertNull($flow['dispatchDetail']->fresh()->serial_numbers);

        $edit = Livewire::test(SaleReturnEditForm::class, ['saleReturn' => $return]);
        $serials = $edit->get('rows.0.serial_numbers');
        $this->assertSame(['SN-A', 'SN-B'], array_column($serials, 'serial_number'));
        $this->assertTrue($serials[0]['pending']);
    }

    /** @test */
    public function edit_corrects_proposed_text_and_keeps_claim_identity_of_unchanged_values(): void
    {
        $flow = $this->untrackedFlow();
        $this->submit($flow, $this->row($flow, ['sn-a', 'sn-b']))->assertHasNoErrors();
        $uuidA = ReturnSerialRegistration::where('proposed_serial', 'SN-A')->value('claim_uuid');

        Livewire::test(SaleReturnEditForm::class, ['saleReturn' => SaleReturn::sole()])
            ->set('rows.0.serial_numbers', [
                ['id' => null, 'serial_number' => 'SN-A', 'pending' => true],
                ['id' => null, 'serial_number' => 'SN-C', 'pending' => true],
            ])->call('submit')->assertHasNoErrors();

        $this->assertEquals(['SN-A', 'SN-C'], ReturnSerialRegistration::orderBy('id')->pluck('proposed_serial')->all());
        $this->assertSame($uuidA, ReturnSerialRegistration::where('proposed_serial', 'SN-A')->value('claim_uuid'));
    }

    /** @test */
    public function direct_submit_rejects_wrong_count_duplicates_and_excess_quantity(): void
    {
        $flow = $this->untrackedFlow();

        $this->submit($flow, $this->row($flow, ['x'], 2))->assertHasErrors();
        $this->submit($flow, $this->row($flow, ['x', ' X ']))->assertHasErrors();
        $this->submit($flow, $this->row($flow, ['a', 'b', 'c', 'd']))->assertHasErrors();
        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(0, ReturnSerialRegistration::count());
    }

    /** @test */
    public function partial_return_is_allowed(): void
    {
        $flow = $this->untrackedFlow(3);

        $this->submit($flow, $this->row($flow, ['p-1', 'p-2']))->assertHasNoErrors();

        $this->assertSame(1, SaleReturn::count());
    }

    /** @test */
    public function historically_serialized_source_rejects_unknown_serial_even_with_forged_flags(): void
    {
        $flow = $this->makeDispatchedSale(2, true);

        $this->submit($flow, $this->row($flow, ['forged-1']))->assertHasErrors();

        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(0, ReturnSerialRegistration::count());
    }

    /** @test */
    public function product_without_tracking_keeps_quantity_only_flow(): void
    {
        $flow = $this->makeDispatchedSale(2, false);   // flag stays false
        $row = ['dispatch_detail_id' => $flow['dispatchDetail']->id, 'product_id' => $flow['product']->id, 'sale_detail_id' => $flow['saleDetail']->id,
            'serial_number_required' => false, 'quantity' => 1, 'unit_price' => 1000, 'total' => 1000, 'serial_numbers' => []];

        $this->submit($flow, $row)->assertHasNoErrors();

        $this->assertSame(0, ReturnSerialRegistration::count());
    }

    /** @test */
    public function loader_proposes_unknown_serial_only_for_eligible_sources(): void
    {
        $flow = $this->untrackedFlow();
        Livewire::test(SaleSerialNumberLoader::class, ['index' => 0, 'dispatchDetailId' => $flow['dispatchDetail']->id, 'productId' => $flow['product']->id])
            ->set('query', ' new-9 ')->call('addSerial')
            ->assertDispatched('serialNumberSelected', 0, ['id' => null, 'serial_number' => 'NEW-9', 'pending' => true]);

        $serialized = $this->makeDispatchedSale(1, true);
        Livewire::test(SaleSerialNumberLoader::class, ['index' => 0, 'dispatchDetailId' => $serialized['dispatchDetail']->id, 'productId' => $serialized['product']->id])
            ->set('query', 'unknown')->call('addSerial')
            ->assertNotDispatched('serialNumberSelected')->assertSet('error_message', 'Nomor seri tidak ditemukan.');
        $this->assertSame(0, ProductSerialNumber::query()->where('serial_number', 'NEW-9')->count());
    }

    private function approvedAwaitingReceiving(array $flow, array $serials): SaleReturn
    {
        $this->submit($flow, $this->row($flow, $serials))->assertHasNoErrors();
        $return = SaleReturn::sole();
        $return->update(['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);

        return $return->fresh();
    }

    /** @test */
    public function receiving_registers_serials_into_held_custody_without_sellable_stock(): void
    {
        $flow = $this->untrackedFlow();
        $return = $this->approvedAwaitingReceiving($flow, ['rcv-1', 'rcv-2']);

        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $return))->assertRedirect();

        $serials = ProductSerialNumber::orderBy('id')->get();
        $this->assertSame(['RCV-1', 'RCV-2'], $serials->pluck('serial_number')->all());
        foreach ($serials as $s) {
            $this->assertSame(ProductSerialNumber::STATUS_RETURN_IN_PROCESS, $s->status);
            $this->assertFalse($s->isSellable());
            $this->assertSame(1, (int) $s->location_id);
        }
        $this->assertSame(2, SaleReturnCustody::where('sale_return_id', $return->id)->count());
        $this->assertSame(0, (int) ProductStock::where('product_id', $flow['product']->id)->sum('quantity'));
        $this->assertEqualsCanonicalizing($serials->pluck('id')->all(), $return->saleReturnDetails()->first()->fresh()->serial_number_ids);
        $this->assertSame(2, ReturnSerialRegistration::whereNotNull('registered_serial_id')->whereNotNull('registered_by')->whereNotNull('registered_at')->count());
        $this->assertNull($flow['dispatchDetail']->fresh()->serial_numbers);

        // replay is a no-op
        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $return));
        $this->assertSame(2, ProductSerialNumber::count());
        $this->assertSame(2, SaleReturnCustody::where('sale_return_id', $return->id)->count());
    }

    /** @test */
    public function collision_at_receiving_rolls_back_and_keeps_proposed_text(): void
    {
        $flow = $this->untrackedFlow();
        $return = $this->approvedAwaitingReceiving($flow, ['ok-1', 'taken']);
        ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'taken', 'location_id' => 1, 'status' => 'ACTIVE']);

        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $return))->assertRedirect();

        $this->assertSame(1, ProductSerialNumber::count());
        $this->assertSame(0, SaleReturnCustody::count());
        $this->assertNull($return->fresh()->received_at);
        $this->assertSame(2, ReturnSerialRegistration::whereNull('registered_serial_id')->count());
    }

    /** @test */
    public function registered_original_returned_beside_untouched_unit_keeps_quantity_conserved(): void
    {
        $flow = $this->untrackedFlow(2);
        $return = $this->approvedAwaitingReceiving($flow, ['one']);
        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $return));

        $rows = app(\App\Support\SalesReturn\SaleReturnEligibilityService::class)->buildReturnableRows($flow['sale']->fresh());

        $this->assertSame(1, (int) $rows->first()['available_quantity']);
        $this->assertTrue($rows->first()['registration_eligible'], 'remaining untouched unit still eligible');
        $this->assertSame(0, $rows->first()['serial_total'], 'registered serial is not original evidence');
    }

    /** @test */
    public function stale_source_blocks_receiving_without_effects(): void
    {
        $flow = $this->untrackedFlow();
        $return = $this->approvedAwaitingReceiving($flow, ['stl-1']);
        $flow['product']->update(['serial_number_required' => false]);

        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $return));

        $this->assertSame(0, ProductSerialNumber::count());
        $this->assertNull($return->fresh()->received_at);
    }

    private function receivedRegistered(int $qty = 1): array
    {
        $flow = $this->untrackedFlow($qty);
        $serials = array_map(fn ($i) => "reg-$i", range(1, $qty));
        $return = $this->approvedAwaitingReceiving($flow, $serials);
        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $return));

        return [$flow, $return->fresh()];
    }

    /** @test */
    public function settlement_coverage_uses_received_registration_evidence(): void
    {
        [$flow, $return] = $this->receivedRegistered(2);
        $detail = $return->saleReturnDetails()->first();

        $occ = \App\Support\SalesReturn\StandardReturnCoverage::occurrences($detail->fresh());

        $this->assertCount(2, $occ);
        $this->assertSame(1.0, $occ[0]['units']);
        $this->assertNotNull($occ[0]['serial']);
    }

    /** @test */
    public function inspection_release_after_cash_refund_makes_registered_serial_sellable_in_correct_bucket(): void
    {
        [$flow, $return] = $this->receivedRegistered(1);
        $serial = ProductSerialNumber::sole();
        $this->settlementFor($return, 'APPROVED', 'CASH_REFUND', $serial->id);
        $custody = SaleReturnCustody::where('sale_return_id', $return->id)->sole();

        $this->withSession(['setting_id' => 1])->post(route('sale-returns.inspect.store', $return), [
            'notes' => 'cek', 'request_key' => 'k1', 'release' => [$custody->id => ['selected' => 1, 'quantity' => 1]],
        ])->assertRedirect();

        $this->assertSame(1, (int) ProductStock::where('product_id', $flow['product']->id)->sum('quantity'));
        $fresh = $serial->fresh();
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $fresh->status);
        $this->assertNull($fresh->dispatch_detail_id);
        $this->assertTrue($fresh->isSellable());
    }

    /** @test */
    public function registered_original_can_be_dispatched_back_for_repair_once(): void
    {
        [$flow, $return] = $this->receivedRegistered(1);
        $serial = ProductSerialNumber::sole();
        $row = $this->settlementFor($return, \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH, 'REPAIR', $serial->id);

        $this->withSession(['setting_id' => 1])->post(route('sale-return-settlements.item.dispatch', $row), ['dispatched_serial_number' => $serial->serial_number])->assertRedirect();
        $this->withSession(['setting_id' => 1])->post(route('sale-return-settlements.item.dispatch.approve', $row))->assertRedirect();
        $this->withSession(['setting_id' => 1])->post(route('sale-return-settlements.item.dispatch.approve', $row));

        $this->assertSame('ORIGINAL', $row->fresh()->dispatch_kind);
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $serial->fresh()->status);
        $this->assertSame(1, \Modules\SalesReturn\Entities\SaleReturnCustodyMovement::where('type', 'DISPATCHED')->count());
    }

    /** @test */
    public function bundle_component_registers_under_its_own_product_and_rejects_parent_substitution(): void
    {
        $flow = $this->makeDispatchedSale(1, false);          // parent line (untracked)
        $component = $this->makeProduct(true, 'COMP');          // component now requires serials
        $componentDetail = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $flow['dispatchDetail']->dispatch_id, 'sale_id' => $flow['sale']->id, 'product_id' => $component->id,
            'dispatched_quantity' => 2, 'location_id' => 1,
        ]);
        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_detail_id' => $flow['saleDetail']->id, 'sale_id' => $flow['sale']->id, 'bundle_id' => 1, 'bundle_item_id' => 1,
            'product_id' => $component->id, 'name' => 'Comp', 'price' => 1000, 'quantity' => 2, 'sub_total' => 2000,
        ]);
        $flow['product']->update(['serial_number_required' => true]);

        $row = fn (int $productId) => [
            'dispatch_detail_id' => $componentDetail->id, 'product_id' => $productId, 'sale_detail_id' => $flow['saleDetail']->id,
            'serial_number_required' => true, 'quantity' => 1, 'unit_price' => 1000, 'total' => 1000,
            'serial_numbers' => [['id' => null, 'serial_number' => 'cmp-1', 'pending' => true]],
        ];

        $this->submit($flow, $row($flow['product']->id))->assertHasErrors();
        $this->assertSame(0, ReturnSerialRegistration::count());

        $this->submit($flow, $row($component->id))->assertHasNoErrors();
        $claim = ReturnSerialRegistration::sole();
        $this->assertSame($component->id, (int) $claim->product_id);
        $this->assertSame('dispatch_detail:'.$componentDetail->id, $claim->source_key);
    }

    private function repairedAndBack(): array
    {
        $flow = $this->untrackedFlow(3);
        $return = $this->approvedAwaitingReceiving($flow, ['rep-1']);
        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $return));
        $serial = ProductSerialNumber::sole();

        return [$flow, $return->fresh(), $serial];
    }

    private function dispatchBack(SaleReturn $return, ProductSerialNumber $serial): void
    {
        $row = $this->settlementFor($return, \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH, 'REPAIR', $serial->id);
        $this->withSession(['setting_id' => 1])->post(route('sale-return-settlements.item.dispatch', $row), ['dispatched_serial_number' => $serial->serial_number])->assertRedirect();
        $this->withSession(['setting_id' => 1])->post(route('sale-return-settlements.item.dispatch.approve', $row))->assertRedirect();
    }

    private function serialRow(array $flow, array $entries, int $quantity): array
    {
        return array_merge($this->row($flow, [], $quantity), ['serial_numbers' => $entries]);
    }

    /** @test */
    public function repaired_registered_unit_returns_again_without_consuming_another_untouched_unit(): void
    {
        [$flow, $return, $serial] = $this->repairedAndBack();
        $eligibility = app(\App\Support\SalesReturn\SaleReturnEligibilityService::class);

        // while still held it cannot be claimed again
        $this->submit($flow, $this->serialRow($flow, [['id' => $serial->id, 'serial_number' => $serial->serial_number]], 1))->assertHasErrors();

        $this->dispatchBack($return, $serial);
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $serial->fresh()->status);
        $this->assertSame(2, (int) $eligibility->buildReturnableRows($flow['sale']->fresh())->first()['available_quantity']);

        $this->submit($flow, $this->serialRow($flow, [['id' => $serial->id, 'serial_number' => $serial->serial_number]], 1))->assertHasNoErrors();

        $this->assertSame(2, SaleReturn::count());
        $this->assertSame(2, (int) $eligibility->buildReturnableRows($flow['sale']->fresh())->first()['available_quantity'], 'the unit is counted once');
    }

    /** @test */
    public function single_unit_sale_repaired_and_back_can_be_returned_again_with_zero_untouched_quantity(): void
    {
        $flow = $this->untrackedFlow(1);
        $return = $this->approvedAwaitingReceiving($flow, ['solo-1']);
        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $return));
        $serial = ProductSerialNumber::sole();
        $this->dispatchBack($return->fresh(), $serial);

        $rows = app(\App\Support\SalesReturn\SaleReturnEligibilityService::class)->buildReturnableRows($flow['sale']->fresh());
        $this->assertCount(1, $rows, 'the repaired unit keeps the line offered');
        $this->assertSame(0, (int) $rows->first()['available_quantity']);

        $this->submit($flow, $this->serialRow($flow, [['id' => $serial->id, 'serial_number' => $serial->serial_number]], 1))->assertHasNoErrors();
        $this->assertSame(2, SaleReturn::count());

        // an extra untouched unit is still not available
        $this->submit($flow, $this->serialRow($flow, [['id' => null, 'serial_number' => 'solo-2', 'pending' => true]], 1))->assertHasErrors();
    }

    /** @test */
    public function repeat_unit_plus_two_untouched_units_can_be_claimed_together_but_not_more(): void
    {
        [$flow, $return, $serial] = $this->repairedAndBack();
        $this->dispatchBack($return, $serial);

        $entries = [['id' => $serial->id, 'serial_number' => $serial->serial_number],
            ['id' => null, 'serial_number' => 'U-2', 'pending' => true], ['id' => null, 'serial_number' => 'U-3', 'pending' => true]];
        $this->submit($flow, $this->serialRow($flow, array_merge($entries, [['id' => null, 'serial_number' => 'U-4', 'pending' => true]]), 4))->assertHasErrors();
        $this->submit($flow, $this->serialRow($flow, $entries, 3))->assertHasNoErrors();
    }

    /** @test */
    public function cash_refund_of_a_repaired_registered_unit_counts_one_unit_once(): void
    {
        [$flow, $return, $serial] = $this->repairedAndBack();
        $this->dispatchBack($return, $serial);
        $this->submit($flow, $this->serialRow($flow, [['id' => $serial->id, 'serial_number' => $serial->serial_number]], 1))->assertHasNoErrors();
        $second = SaleReturn::orderByDesc('id')->first();
        $second->update(['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);
        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $second))->assertRedirect();
        $this->settlementFor($second->fresh(), 'APPROVED', 'CASH_REFUND', $serial->id);

        $this->assertSame(ProductSerialNumber::STATUS_RETURN_IN_PROCESS, $serial->fresh()->status);
        $this->assertSame(1, SaleReturnCustody::where('sale_return_id', $second->id)->count());
        $this->assertSame(2, (int) app(\App\Support\SalesReturn\SaleReturnEligibilityService::class)->buildReturnableRows($flow['sale']->fresh())->first()['available_quantity']);
    }

    /** @test */
    public function bundle_component_registered_unit_returns_again_without_consuming_another_untouched_component_unit(): void
    {
        $flow = $this->makeDispatchedSale(1, false);
        $component = $this->makeProduct(true, 'COMP2');
        $componentDetail = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $flow['dispatchDetail']->dispatch_id, 'sale_id' => $flow['sale']->id, 'product_id' => $component->id,
            'dispatched_quantity' => 3, 'location_id' => 1,
        ]);
        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_detail_id' => $flow['saleDetail']->id, 'sale_id' => $flow['sale']->id, 'bundle_id' => 1, 'bundle_item_id' => 1,
            'product_id' => $component->id, 'name' => 'Comp', 'price' => 1000, 'quantity' => 3, 'sub_total' => 3000,
        ]);
        $componentFlow = array_merge($flow, ['product' => $component, 'dispatchDetail' => $componentDetail]);
        $row = fn (array $entries, int $qty) => [
            'dispatch_detail_id' => $componentDetail->id, 'product_id' => $component->id, 'sale_detail_id' => $flow['saleDetail']->id,
            'serial_number_required' => true, 'quantity' => $qty, 'unit_price' => 1000, 'total' => 1000 * $qty, 'serial_numbers' => $entries,
        ];

        $this->submit($flow, $row([['id' => null, 'serial_number' => 'cmp-r1', 'pending' => true]], 1))->assertHasNoErrors();
        $first = SaleReturn::sole();
        $first->update(['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);
        $this->withSession(['setting_id' => 1])->post(route('sale-returns.receive', $first))->assertRedirect();
        $serial = ProductSerialNumber::sole();
        $this->assertSame($component->id, (int) $serial->product_id);

        $settlement = $this->settlementFor($first->fresh(), \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH, 'REPAIR', $serial->id);
        $this->withSession(['setting_id' => 1])->post(route('sale-return-settlements.item.dispatch', $settlement), ['dispatched_serial_number' => $serial->serial_number])->assertRedirect();
        $this->withSession(['setting_id' => 1])->post(route('sale-return-settlements.item.dispatch.approve', $settlement))->assertRedirect();

        $this->submit($flow, $row([['id' => $serial->id, 'serial_number' => $serial->serial_number]], 1))->assertHasNoErrors();

        $rows = app(\App\Support\SalesReturn\SaleReturnEligibilityService::class)->buildReturnableRows($flow['sale']->fresh());
        $this->assertSame(2, (int) $rows->firstWhere('dispatch_detail_id', $componentDetail->id)['available_quantity'], 'component unit counted once');
    }

    /** @test */
    public function replacement_for_a_registered_original_can_be_returned_and_consumes_the_unit_once(): void
    {
        [$flow, $return, $original] = $this->repairedAndBack();   // R1 registered + held
        $replacement = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'REPL-S', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_ACTIVE]);
        ProductStock::create(['product_id' => $flow['product']->id, 'location_id' => 1, 'quantity' => 4, 'quantity_tax' => 0, 'quantity_non_tax' => 4,
            'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0]);
        $flow['product']->forceFill(['product_quantity' => 4])->save();

        $row = $this->settlementFor($return, \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH, 'REPAIR', $original->id);
        $this->withSession(['setting_id' => 1])->post(route('sale-return-settlements.item.dispatch', $row), ['dispatched_serial_number' => 'REPL-S'])->assertRedirect();
        $this->withSession(['setting_id' => 1])->post(route('sale-return-settlements.item.dispatch.approve', $row))->assertRedirect();
        $this->assertSame('REPLACEMENT', $row->fresh()->dispatch_kind);
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $replacement->fresh()->status);

        $eligibility = app(\App\Support\SalesReturn\SaleReturnEligibilityService::class);
        $this->assertSame(2, (int) $eligibility->buildReturnableRows($flow['sale']->fresh())->first()['available_quantity']);

        // the replacement is the customer-held occurrence of the same unit
        $this->submit($flow, $this->serialRow($flow, [['id' => $replacement->id, 'serial_number' => 'REPL-S']], 1))->assertHasNoErrors();

        $this->assertSame(2, SaleReturn::count());
        $this->assertSame(2, (int) $eligibility->buildReturnableRows($flow['sale']->fresh())->first()['available_quantity'], 'original unit consumed once');

        // an unrelated stocked serial is still not returnable through this source
        $other = ProductSerialNumber::create(['product_id' => $flow['product']->id, 'serial_number' => 'OTHER-S', 'location_id' => 1, 'status' => ProductSerialNumber::STATUS_SOLD]);
        $this->submit($flow, $this->serialRow($flow, [['id' => $other->id, 'serial_number' => 'OTHER-S']], 1))->assertHasErrors();
    }
}

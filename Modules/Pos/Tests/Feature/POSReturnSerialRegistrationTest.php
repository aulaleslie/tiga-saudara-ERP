<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;
use Spatie\Permission\Models\Permission;

class POSReturnSerialRegistrationTest extends PosTransactionFeatureTestCase
{
    use Support\BuildsPosSerialRegistrationFixture;

    /** @test */
    public function snapshot_marks_untracked_history_eligible_and_serialized_history_not(): void
    {
        $f = $this->fixture();
        $line = collect($this->snapshot($f)['lines'])->firstWhere('sale_detail_id', $f['saleDetail']->id);
        $this->assertTrue($line['registration_eligible']);
        $this->assertSame('quantity_only', $line['registration_classification']);
        $this->assertEquals(3, $line['returnable_quantity']);

        $g = $this->fixture(true);
        $line = collect($this->snapshot($g)['lines'])->firstWhere('sale_detail_id', $g['saleDetail']->id);
        $this->assertFalse($line['registration_eligible'] ?? false);

        $h = $this->fixture(false, false);
        $line = collect($this->snapshot($h)['lines'])->firstWhere('sale_detail_id', $h['saleDetail']->id);
        $this->assertFalse($line['registration_eligible'], 'currently non-serial product keeps quantity-only flow');
    }

    /** @test */
    public function snapshot_hash_ignores_registration_metadata(): void
    {
        $f = $this->fixture();
        $svc = app(PosReturnSnapshotService::class);
        $snap = $this->snapshot($f);
        $stripped = $snap;
        foreach ($stripped['lines'] as &$l) {
            foreach (array_keys($l) as $k) {
                if (str_starts_with($k, 'registration_')) {
                    unset($l[$k]);
                }
            }
        }
        $this->assertSame($svc->hash($snap), $svc->hash($stripped));
    }

    /** @test */
    public function draft_with_proposed_serials_creates_no_inventory_and_keeps_text(): void
    {
        $f = $this->fixture();
        $before = ProductSerialNumber::count();

        $return = $this->store($f, [$this->pendingLine($f, 'new-a'), $this->pendingLine($f, 'new-b')]);

        $this->assertSame($before, ProductSerialNumber::count());
        $this->assertSame(2, $return->lines()->count());
        $this->assertEqualsCanonicalizing(['NEW-A', 'NEW-B'], ReturnSerialRegistration::where('workflow', 'pos')->pluck('proposed_serial')->all());
        $this->assertSame(1200.0, round((float) $return->fresh()->total_amount, 2));
        $this->assertNull($f['dispatchDetail']->fresh()->serial_numbers);
    }

    /** @test */
    public function direct_requests_cannot_forge_eligibility_or_skip_serials(): void
    {
        $g = $this->fixture(true);   // historically serialized
        $this->expectExceptionMessage('hanya dapat didaftarkan');
        $this->store($g, [$this->pendingLine($g, 'forged')]);
    }

    /** @test */
    public function tracked_untracked_history_without_serial_is_rejected(): void
    {
        $f = $this->fixture();
        $this->expectExceptionMessage('Nomor seri wajib diisi');
        $this->store($f, [['sale_detail_id' => $f['saleDetail']->id, 'sale_id' => $f['sale']->id, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN, 'quantity' => 1]]);
    }

    /** @test */
    public function duplicates_excess_and_registered_serials_are_rejected_without_effects(): void
    {
        $f = $this->fixture();

        foreach ([
            [['a', 'A '], 'duplikat'],
            [['a', 'b', 'c', 'd'], 'melebihi'],
        ] as [$serials, $message]) {
            try {
                $this->store($f, array_map(fn ($s) => $this->pendingLine($f, $s), $serials));
                $this->fail('expected rejection');
            } catch (\Exception $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        ProductSerialNumber::create(['product_id' => $f['product']->id, 'serial_number' => 'exists', 'location_id' => $this->location->id, 'status' => 'ACTIVE']);
        try {
            $this->store($f, [$this->pendingLine($f, 'exists')]);
            $this->fail('expected rejection');
        } catch (\Exception $e) {
            $this->assertStringContainsString('sudah terdaftar', $e->getMessage());
        }

        $this->assertSame(0, PosReturn::count());
        $this->assertSame(0, ReturnSerialRegistration::count());
    }

    /** @test */
    public function partial_return_leaves_remaining_untouched_quantity_returnable(): void
    {
        $f = $this->fixture();
        $return = $this->store($f, [$this->pendingLine($f, 'one'), $this->pendingLine($f, 'two')]);
        app(PosReturnSubmissionService::class)->submitDraftForApproval($return);

        $line = collect($this->snapshot($f)['lines'])->firstWhere('sale_detail_id', $f['saleDetail']->id);

        $this->assertEquals(1, $line['returnable_quantity']);
        $this->assertTrue($line['registration_eligible']);
    }

    /** @test */
    public function edit_replaces_proposals_and_keeps_claim_identity(): void
    {
        $f = $this->fixture();
        $return = $this->store($f, [$this->pendingLine($f, 'keep'), $this->pendingLine($f, 'drop')]);
        $uuid = ReturnSerialRegistration::where('proposed_serial', 'KEEP')->value('claim_uuid');
        $snapshot = $this->snapshot($f);

        app(PosReturnSubmissionService::class)->update($return, [
            'source_snapshot_hash' => $snapshot['hash'], 'lines' => [$this->pendingLine($f, 'keep'), $this->pendingLine($f, 'fresh')],
        ]);

        $this->assertEqualsCanonicalizing(['KEEP', 'FRESH'], ReturnSerialRegistration::pluck('proposed_serial')->all());
        $this->assertSame($uuid, ReturnSerialRegistration::where('proposed_serial', 'KEEP')->value('claim_uuid'));
        $this->assertSame(0, ProductSerialNumber::where('serial_number', 'KEEP')->count());
    }

    /** @test */
    public function replacement_lines_are_tagged_serialized_and_require_a_replacement_serial(): void
    {
        $f = $this->fixture();
        try {
            $this->store($f, [$this->pendingLine($f, 'r1', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)]);
            $this->fail('expected rejection');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Serial pengganti', $e->getMessage());
        }

        $replacement = $this->createSerialNumber($f['product'], $this->location, 'REPL-1');
        $return = $this->store($f, [$this->pendingLine($f, 'r1', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, ['replacement_serial_id' => $replacement->id])]);

        $this->assertSame('serial_inventory_replacement', $return->lines()->first()->line_meta['execution_mode']);
    }

    /** @test */
    public function preview_shows_registration_intentions_without_mutating_anything(): void
    {
        $f = $this->fixture();
        $return = $this->submitted($f, [$this->pendingLine($f, 'pv-1'), $this->pendingLine($f, 'pv-2')]);

        $tables = ['product_serial_numbers', 'serial_number_histories', 'product_stocks', 'return_serial_registrations', 'sale_returns', 'sale_return_details', 'dispatch_details', 'transactions'];
        $count = fn () => collect($tables)->map(fn ($t) => DB::table($t)->count())->all();
        $stocks = fn () => DB::table('product_stocks')->orderBy('id')->get()->toJson();
        $before = [$count(), $stocks(), $return->fresh()->status];

        $response = $this->get(route('pos.returns.approval-preview', $return));

        $response->assertOk()->assertSee('serial_baru_didaftarkan_saat_receiving', false);
        $this->assertSame($before, [$count(), $stocks(), $return->fresh()->status]);
    }

    /** @test */
    public function approval_registers_serials_atomically_with_owner_location_stock_and_history(): void
    {
        $f = $this->fixture();
        $return = $this->submitted($f, [$this->pendingLine($f, 'rc-1'), $this->pendingLine($f, 'rc-2')]);
        $stockBefore = (float) ProductStock::where('product_id', $f['product']->id)->sum('quantity');

        $return = $this->approve($return);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $return->status);
        $serials = ProductSerialNumber::whereIn('serial_number', ['RC-1', 'RC-2'])->get();
        $this->assertCount(2, $serials);
        foreach ($serials as $serial) {
            $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $serial->status);
            $this->assertSame((int) $this->location->id, (int) $serial->location_id);
            $this->assertNull($serial->dispatch_detail_id);
            $this->assertSame(1, \Modules\Product\Entities\SerialNumberHistory::where('product_serial_number_id', $serial->id)->where('event_type', 'RECEIVED')->count());
        }
        $this->assertSame($stockBefore + 2, (float) ProductStock::where('product_id', $f['product']->id)->sum('quantity'));
        $claims = ReturnSerialRegistration::where('workflow', 'pos')->get();
        $this->assertCount(2, $claims);
        foreach ($claims as $claim) {
            $this->assertNotNull($claim->registered_serial_id);
            $this->assertNotNull($claim->registered_by);
            $this->assertNotNull($claim->registered_at);
        }
        $this->assertEqualsCanonicalizing($serials->pluck('id')->all(), $return->lines()->pluck('returned_serial_id')->all());
        $this->assertNull($f['dispatchDetail']->fresh()->serial_numbers, 'original dispatch serial list unchanged');
        $detail = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->where('pos_return_line_id', $return->lines()->value('id'))->first();
        $this->assertNotNull(data_get($detail->execution_context, 'registration_claim_uuid'));

        // replay of the approval creates nothing more
        $this->post(route('pos.returns.approve', $return));
        $this->assertSame(2, ProductSerialNumber::whereIn('serial_number', ['RC-1', 'RC-2'])->count());
        $this->assertSame($stockBefore + 2, (float) ProductStock::where('product_id', $f['product']->id)->sum('quantity'));
    }

    /** @test */
    public function collision_during_approval_rolls_back_everything_and_keeps_proposals(): void
    {
        $f = $this->fixture();
        $return = $this->submitted($f, [$this->pendingLine($f, 'ok-1'), $this->pendingLine($f, 'taken')]);
        ProductSerialNumber::create(['product_id' => $f['product']->id, 'serial_number' => 'taken', 'location_id' => $this->location->id, 'status' => 'ACTIVE']);
        $stockBefore = (float) ProductStock::where('product_id', $f['product']->id)->sum('quantity');
        $serialsBefore = ProductSerialNumber::count();

        $this->get(route('pos.returns.approval-preview', $return))->assertOk();
        $this->post(route('pos.returns.approve', $return));
        $this->assertStringContainsString('TAKEN sudah terdaftar', json_encode(session('alert')), 'collision reached the registration step');
        $return = $return->fresh();

        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $return->status);
        $this->assertSame($serialsBefore, ProductSerialNumber::count());
        $this->assertSame(0, ReturnSerialRegistration::whereNotNull('registered_serial_id')->count());
        $this->assertSame(2, ReturnSerialRegistration::whereNull('registered_serial_id')->count());
        $this->assertSame($stockBefore, (float) ProductStock::where('product_id', $f['product']->id)->sum('quantity'));
        $this->assertSame(0, \Modules\SalesReturn\Entities\SaleReturn::where('pos_return_id', $return->id)->where('status', 'COMPLETED')->count());
    }

    /** @test */
    public function stale_source_blocks_approval_without_effects(): void
    {
        $f = $this->fixture();
        $return = $this->submitted($f, [$this->pendingLine($f, 'st-1')]);
        $f['product']->update(['serial_number_required' => false]);

        $return = $this->approve($return);

        $this->assertNotSame(PosReturn::STATUS_COMPLETED, $return->status);
        $this->assertSame(0, ProductSerialNumber::where('serial_number', 'ST-1')->count());
    }

    /** @test */
    public function registered_original_replacement_uses_serialized_replacement_stock(): void
    {
        $f = $this->fixture();
        $replacement = $this->createSerialNumber($f['product'], $this->location, 'REPL-9');
        $return = $this->submitted($f, [$this->pendingLine($f, 'orig-9', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, ['replacement_serial_id' => $replacement->id])]);

        $return = $this->approve($return);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $return->status);
        $registered = ProductSerialNumber::where('serial_number', 'ORIG-9')->firstOrFail();
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $registered->status, 'returned original enters stock');
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $replacement->fresh()->status, 'replacement serial left stock');
        $this->assertNotNull($replacement->fresh()->dispatch_detail_id);
        $dispatched = DispatchDetail::where('replacement_returned_serial_id', $registered->id)->first();
        $this->assertNotNull($dispatched, 'replacement dispatch references the registered original');
        $this->assertSame($registered->id, (int) $return->lines()->value('returned_serial_id'));
        $this->assertSame($replacement->id, (int) $return->lines()->value('replacement_serial_id'));
    }

    /** Registers ORIG-9 by a completed replacement; the customer then holds REPL-9. */
    private function replacedRegisteredUnit(): array
    {
        $f = $this->fixture();
        $replacement = $this->createSerialNumber($f['product'], $this->location, 'REPL-9');
        $this->approve($this->submitted($f, [$this->pendingLine($f, 'orig-9', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, ['replacement_serial_id' => $replacement->id])]));

        return [$f, ProductSerialNumber::where('serial_number', 'ORIG-9')->firstOrFail(), $replacement->fresh()];
    }

    private function lineFor(array $snapshot, int $saleDetailId, ?int $serialId = null): ?array
    {
        return collect($snapshot['lines'])->first(fn ($l) => (int) $l['sale_detail_id'] === $saleDetailId
            && ($serialId === null ? empty($l['serial_number_ids']) : in_array($serialId, $l['serial_number_ids'] ?? [], true)));
    }

    /** @test */
    public function lookup_after_replacement_shows_customer_held_registered_unit_and_untouched_quantity_separately(): void
    {
        [$f, $original, $replacement] = $this->replacedRegisteredUnit();
        $snapshot = $this->snapshot($f);

        $held = $this->lineFor($snapshot, $f['saleDetail']->id, $replacement->id);
        $this->assertNotNull($held, 'the replacement unit held by the customer is offered by serial');
        $this->assertEquals(1, $held['returnable_quantity']);
        $this->assertSame($original->id, $held['registration_origin_serial_id']);

        $quantityRow = $this->lineFor($snapshot, $f['saleDetail']->id);
        $this->assertNotNull($quantityRow);
        $this->assertEquals(3, $quantityRow['returnable_quantity'], 'completed replacement did not consume entitlement');
        $this->assertTrue($quantityRow['registration_eligible']);
        $this->assertNull($this->lineFor($snapshot, $f['saleDetail']->id, $original->id), 'the registered original is back in the store, not customer-held');
    }

    /** @test */
    public function replacement_then_refund_consumes_the_unit_once(): void
    {
        [$f, $original, $replacement] = $this->replacedRegisteredUnit();

        $return = $this->submitted($f, [[
            'sale_detail_id' => $f['saleDetail']->id, 'sale_id' => $f['sale']->id, 'returned_serial_id' => $replacement->id,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN, 'quantity' => 1,
        ]]);
        $return = $this->approve($return);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $return->status);
        $this->assertSame(2, (int) $f['dispatchDetail']->fresh()->dispatched_quantity, 'exactly one of the three units left the entitlement');
        $snapshot = $this->snapshot($f);
        $this->assertEquals(2, $this->lineFor($snapshot, $f['saleDetail']->id)['returnable_quantity']);
        $this->assertEquals(0, $this->lineFor($snapshot, $f['saleDetail']->id, $replacement->id)['returnable_quantity'], 'refunded unit is exhausted');
    }

    /** @test */
    public function customer_held_unit_and_untouched_quantity_cannot_be_claimed_beyond_the_source_total(): void
    {
        [$f, $original, $replacement] = $this->replacedRegisteredUnit();
        $held = [
            'sale_detail_id' => $f['saleDetail']->id, 'sale_id' => $f['sale']->id, 'returned_serial_id' => $replacement->id,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN, 'quantity' => 1,
        ];

        try {
            $this->store($f, [$held, $this->pendingLine($f, 'a'), $this->pendingLine($f, 'b'), $this->pendingLine($f, 'c')]);
            $this->fail('four claims on a three unit source must be rejected');
        } catch (\Exception $e) {
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        $this->store($f, [$held, $this->pendingLine($f, 'a'), $this->pendingLine($f, 'b')]);
        $this->assertSame(3, PosReturnLine::count() - 1, 'held unit + two untouched units; the earlier replacement line is the +1');
    }

    /** @test */
    public function create_form_enters_pending_serials_per_unit_validates_them_and_saves_the_draft(): void
    {
        $f = $this->fixture();
        $form = \Livewire\Livewire::test(\Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::class)
            ->set('identifier', $f['checkout']->receipt_number)->call('lookup')->assertSet('error', null);
        $key = (string) $f['saleDetail']->id;

        $form->call('updateResolution', $key, 'cash_return')
            ->set("lineSelections.$key.pending_input", ' form-1 ')->call('addPendingSerial', $key)
            ->set("lineSelections.$key.pending_input", 'FORM-1')->call('addPendingSerial', $key)
            ->assertHasErrors(["lineSelections.$key.pending_input"])
            ->set("lineSelections.$key.pending_input", 'form-2')->call('addPendingSerial', $key)
            ->assertSet("lineSelections.$key.quantity", 2);

        $this->assertSame(['FORM-1', 'FORM-2'], array_column($form->get("lineSelections.$key.pending_serials"), 'serial_number'));

        $form->call('submit');

        $return = PosReturn::latest('id')->firstOrFail();
        $this->assertSame(2, $return->lines()->count());
        $this->assertEqualsCanonicalizing(['FORM-1', 'FORM-2'], ReturnSerialRegistration::pluck('proposed_serial')->all());
        $this->assertSame(0, ProductSerialNumber::whereIn('serial_number', ['FORM-1', 'FORM-2'])->count());
        $this->assertSame(PosReturn::STATUS_DRAFT, $return->status);
    }

    /** @test */
    public function create_form_refuses_ineligible_sources_and_unknown_keys(): void
    {
        $g = $this->fixture(true);   // serialized history
        $form = \Livewire\Livewire::test(\Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::class)
            ->set('identifier', $g['checkout']->receipt_number)->call('lookup');
        $key = (string) $g['saleDetail']->id;

        $form->set("lineSelections.$key.pending_input", 'forged')->call('addPendingSerial', $key);
        $this->assertSame([], $form->get("lineSelections.$key.pending_serials") ?? []);
    }

    /** @test */
    public function edit_form_reloads_saved_pending_serials_and_saves_corrections(): void
    {
        $f = $this->fixture();
        $return = $this->store($f, [$this->pendingLine($f, 'edit-1'), $this->pendingLine($f, 'edit-2')]);
        $key = (string) $f['saleDetail']->id;

        $form = \Livewire\Livewire::test(\Modules\Pos\Livewire\PosReturn\PosReturnEditForm::class, ['return' => $return])
            ->assertSet("lineSelections.$key.resolution", 'cash_return')
            ->assertSet("lineSelections.$key.quantity", 2);
        $this->assertSame(['EDIT-1', 'EDIT-2'], array_column($form->get("lineSelections.$key.pending_serials"), 'serial_number'));

        $form->call('removePendingSerial', $key, 1)
            ->set("lineSelections.$key.pending_input", 'edit-3')->call('addPendingSerial', $key)
            ->call('submit');

        $this->assertEqualsCanonicalizing(['EDIT-1', 'EDIT-3'], ReturnSerialRegistration::pluck('proposed_serial')->all());
        $this->assertSame(2, $return->fresh()->lines()->count());
    }

    /** @test */
    public function repeated_replacement_follows_the_chain_to_the_latest_customer_held_unit(): void
    {
        [$f, $original, $first] = $this->replacedRegisteredUnit();
        $second = $this->createSerialNumber($f['product'], $this->location, 'REPL-10');

        $this->approve($this->submitted($f, [[
            'sale_detail_id' => $f['saleDetail']->id, 'sale_id' => $f['sale']->id, 'returned_serial_id' => $first->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, 'quantity' => 1, 'replacement_serial_id' => $second->id,
        ]]));

        $snapshot = $this->snapshot($f);
        $this->assertNotNull($this->lineFor($snapshot, $f['saleDetail']->id, $second->id), 'latest unit offered');
        $this->assertNull($this->lineFor($snapshot, $f['saleDetail']->id, $first->id), 'replaced unit no longer customer-held');
        $this->assertEquals(3, $this->lineFor($snapshot, $f['saleDetail']->id)['returnable_quantity'], 'replacements never consume entitlement');

        $return = $this->approve($this->submitted($f, [[
            'sale_detail_id' => $f['saleDetail']->id, 'sale_id' => $f['sale']->id, 'returned_serial_id' => $second->id,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN, 'quantity' => 1,
        ]]));
        $this->assertSame(PosReturn::STATUS_COMPLETED, $return->status);
        $this->assertSame(2, (int) $f['dispatchDetail']->fresh()->dispatched_quantity);
    }

    private function replaceLine(array $f, ProductSerialNumber $returned, ProductSerialNumber $replacement): array
    {
        return [
            'sale_detail_id' => $f['saleDetail']->id, 'sale_id' => $f['sale']->id, 'returned_serial_id' => $returned->id,
            'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, 'quantity' => 1, 'replacement_serial_id' => $replacement->id,
        ];
    }

    /** @test */
    public function a_to_b_to_a_replacement_chain_keeps_the_registered_unit_offered_as_customer_held(): void
    {
        [$f, $a, $b] = $this->replacedRegisteredUnit();
        $a->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);   // repaired and back on the shelf

        $this->approve($this->submitted($f, [$this->replaceLine($f, $b, $a->fresh())]));

        $snapshot = $this->snapshot($f);
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $a->fresh()->status);
        $this->assertNotNull($this->lineFor($snapshot, $f['saleDetail']->id, $a->id), 'A is customer-held again and must stay returnable');
        $this->assertNull($this->lineFor($snapshot, $f['saleDetail']->id, $b->id));
    }

    /** @test */
    public function an_old_refund_of_a_serial_does_not_block_a_later_occurrence_of_it(): void
    {
        [$f, $a, $b] = $this->replacedRegisteredUnit();

        // B is refunded (previous occurrence), then comes back to stock and is used as a replacement again.
        $this->approve($this->submitted($f, [[
            'sale_detail_id' => $f['saleDetail']->id, 'sale_id' => $f['sale']->id, 'returned_serial_id' => $b->id,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN, 'quantity' => 1,
        ]]));
        $b->update(['status' => ProductSerialNumber::STATUS_ACTIVE]);
        $c = $this->createSerialNumber($f['product'], $this->location, 'ORIG-11');
        $this->approve($this->submitted($f, [$this->pendingLine($f, 'orig-12', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, ['replacement_serial_id' => $b->id])]));

        $snapshot = $this->snapshot($f);
        $line = $this->lineFor($snapshot, $f['saleDetail']->id, $b->id);
        $this->assertNotNull($line, 'B is customer-held again');
        $this->assertEquals(1, $line['returnable_quantity'], 'the earlier refund belongs to the previous occurrence');
    }

    /** @test */
    public function return_created_exclusion_applies_only_to_the_registrations_own_source(): void
    {
        [$f, $a, $b] = $this->replacedRegisteredUnit();
        $classifier = app(\App\Support\SalesReturn\SerialRegistration\HistoricalFulfillmentClassifier::class);
        $submission = app(\Modules\Pos\Services\PosReturnSubmissionService::class);

        $this->assertSame([$a->id], $classifier->returnCreatedSerialIdsForSource([$a->id], $f['dispatchDetail']->id));
        $this->assertSame([], $classifier->returnCreatedSerialIdsForSource([$a->id], $f['dispatchDetail']->id + 999), 'a later sale of the serial is its own fulfillment');

        // The serial was resold under another dispatch line: it is that line's recorded identity again.
        $other = $f['dispatchDetail']->replicate();
        $other->dispatched_quantity = 1;
        $other->save();
        $a->forceFill(['dispatch_detail_id' => $other->id])->save();
        $this->assertContains($a->id, $submission->resolveOriginalSerialIdsForDispatch($other->id));
        $this->assertNotContains($a->id, $submission->resolveOriginalSerialIdsForDispatch($f['dispatchDetail']->id));
    }

    /** @test */
    public function sequential_submissions_cannot_claim_more_units_than_the_source_has(): void
    {
        $f = $this->fixture();
        $a = $this->store($f, [$this->pendingLine($f, 'x-1'), $this->pendingLine($f, 'x-2')]);
        $b = $this->store($f, [$this->pendingLine($f, 'y-1'), $this->pendingLine($f, 'y-2')]);

        app(PosReturnSubmissionService::class)->submitDraftForApproval($a);
        $this->expectException(\Throwable::class);
        app(PosReturnSubmissionService::class)->submitDraftForApproval($b);
    }

    /** @test */
    public function a_customer_held_unit_cannot_have_two_active_claims(): void
    {
        [$f, $original, $replacement] = $this->replacedRegisteredUnit();
        $line = [
            'sale_detail_id' => $f['saleDetail']->id, 'sale_id' => $f['sale']->id, 'returned_serial_id' => $replacement->id,
            'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN, 'quantity' => 1,
        ];
        $this->submitted($f, [$line]);

        $this->expectException(\Exception::class);
        $this->submitted($f, [$line]);
    }
}

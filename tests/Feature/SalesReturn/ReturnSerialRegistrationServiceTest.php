<?php

namespace Tests\Feature\SalesReturn;

use App\Support\SalesReturn\SerialRegistration\HistoricalFulfillmentClassifier;
use App\Support\SalesReturn\SerialRegistration\ReturnSerialRegistrationService;
use App\Support\SalesReturn\SerialRegistration\SerialRegistrationCollisionException;
use App\Support\SalesReturn\SerialRegistration\SourceClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;
use App\Models\User;
use Tests\TestCase;

class ReturnSerialRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsStandardFlow;

    private function flowWithProposal(array $texts = ['new-1']): array
    {
        $this->seedBase();
        $this->actingAs(User::factory()->create());
        $flow = $this->makeDispatchedSale(3, false);
        $flow['product']->update(['serial_number_required' => true]);   // tracking enabled AFTER the sale
        $return = $this->makeReturnFor($flow, count($texts));
        $detail = $return->saleReturnDetails()->first();
        $class = app(HistoricalFulfillmentClassifier::class)->forDispatchDetail($flow['dispatchDetail']);

        $claims = app(ReturnSerialRegistrationService::class)->saveProposals([
            'workflow' => 'standard', 'sale_return_id' => $return->id, 'sale_return_detail_id' => $detail->id,
            'product_id' => $flow['product']->id,
            'source_key' => HistoricalFulfillmentClassifier::standardSourceKey($flow['dispatchDetail']->id),
            'classification' => $class,
        ], $texts);

        return compact('flow', 'return', 'detail', 'claims', 'class');
    }

    private function ctx(): array
    {
        return ['location_id' => 1, 'tax_id' => null, 'actor_id' => auth()->id(), 'at' => now()];
    }

    public function test_untracked_dispatch_classifies_quantity_only_and_proposals_create_no_serials(): void
    {
        $x = $this->flowWithProposal(['a', 'b']);

        $this->assertSame(SourceClassification::QUANTITY_ONLY, $x['class']->type);
        $this->assertCount(2, $x['claims']);
        $this->assertSame(0, ProductSerialNumber::count());
        $this->assertNull($x['flow']['dispatchDetail']->fresh()->serial_numbers);
    }

    public function test_recorded_serial_dispatch_is_not_eligible(): void
    {
        $this->seedBase();
        $flow = $this->makeDispatchedSale(2, true);
        $class = app(HistoricalFulfillmentClassifier::class)->forDispatchDetail($flow['dispatchDetail']);

        $this->assertTrue($class->isRecordedSerial());
        $this->expectException(\DomainException::class);
        app(ReturnSerialRegistrationService::class)->saveProposals([
            'workflow' => 'standard', 'sale_return_id' => 1, 'sale_return_detail_id' => 1, 'product_id' => $flow['product']->id,
            'source_key' => 'x', 'classification' => $class,
        ], ['zzz']);
    }

    public function test_resaving_keeps_claim_uuid_and_drops_removed_text(): void
    {
        $x = $this->flowWithProposal(['a', 'b']);
        $uuidA = $x['claims']->firstWhere('proposed_serial', 'A')->claim_uuid;

        $again = app(ReturnSerialRegistrationService::class)->saveProposals([
            'workflow' => 'standard', 'sale_return_id' => $x['return']->id, 'sale_return_detail_id' => $x['detail']->id,
            'product_id' => $x['flow']['product']->id, 'source_key' => $x['claims'][0]->source_key, 'classification' => $x['class'],
        ], ['a', 'c']);

        $this->assertSame($uuidA, $again->firstWhere('proposed_serial', 'A')->claim_uuid);
        $this->assertSame(['A', 'C'], ReturnSerialRegistration::orderBy('id')->pluck('proposed_serial')->all());
    }

    public function test_register_creates_serial_with_evidence_and_replay_is_idempotent(): void
    {
        $x = $this->flowWithProposal(['new-1']);
        $svc = app(ReturnSerialRegistrationService::class);

        DB::transaction(fn () => $svc->register($x['claims'], $this->ctx() + ['dispatch_detail_id' => $x['flow']['dispatchDetail']->id]));
        $claim = $x['claims'][0]->fresh();

        $this->assertNotNull($claim->registered_serial_id);
        $this->assertNotNull($claim->registered_at);
        $serial = ProductSerialNumber::find($claim->registered_serial_id);
        $this->assertSame('NEW-1', $serial->serial_number);

        DB::transaction(fn () => $svc->register($x['claims'], $this->ctx()));
        $this->assertSame(1, ProductSerialNumber::count());
        $this->assertNull($x['flow']['dispatchDetail']->fresh()->serial_numbers);   // original list untouched
    }

    public function test_independently_registered_serial_is_a_collision_and_rolls_back_everything(): void
    {
        $x = $this->flowWithProposal(['dup', 'other']);
        ProductSerialNumber::create([
            'product_id' => $x['flow']['product']->id, 'serial_number' => 'dup', 'location_id' => 1, 'status' => 'ACTIVE',
        ]);

        try {
            DB::transaction(fn () => app(ReturnSerialRegistrationService::class)->register($x['claims'], $this->ctx()));
            $this->fail('collision expected');
        } catch (SerialRegistrationCollisionException $e) {
            $this->assertSame('DUP', $e->serial);
        }

        $this->assertSame(1, ProductSerialNumber::count());
        $this->assertSame(0, ReturnSerialRegistration::whereNotNull('registered_serial_id')->count());
        $this->assertSame(2, ReturnSerialRegistration::count());   // proposed text retained
    }

    public function test_failure_after_registration_removes_the_serial(): void
    {
        $x = $this->flowWithProposal(['new-1']);

        try {
            DB::transaction(function () use ($x) {
                app(ReturnSerialRegistrationService::class)->register($x['claims'], $this->ctx());
                throw new \RuntimeException('later effect failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, ProductSerialNumber::count());
        $this->assertNull($x['claims'][0]->fresh()->registered_serial_id);
    }

    public function test_registered_serial_is_excluded_from_original_evidence(): void
    {
        $x = $this->flowWithProposal(['new-1']);
        DB::transaction(fn () => app(ReturnSerialRegistrationService::class)
            ->register($x['claims'], $this->ctx() + ['dispatch_detail_id' => $x['flow']['dispatchDetail']->id]));

        $class = app(HistoricalFulfillmentClassifier::class)->forDispatchDetail($x['flow']['dispatchDetail']->fresh());

        $this->assertSame(SourceClassification::QUANTITY_ONLY, $class->type);
        $this->assertSame($x['class']->fingerprint, $class->fingerprint);
    }

    public function test_same_text_on_another_product_does_not_collide(): void
    {
        $x = $this->flowWithProposal(['same']);
        $other = $this->makeProduct(true, 'OTHER');
        ProductSerialNumber::create(['product_id' => $other->id, 'serial_number' => 'same', 'location_id' => 1, 'status' => 'ACTIVE']);

        DB::transaction(fn () => app(ReturnSerialRegistrationService::class)->register($x['claims'], $this->ctx()));

        $this->assertSame(2, ProductSerialNumber::count());
    }

    public function test_claim_uuid_and_unit_attribution_are_unique_and_old_returns_are_unaffected(): void
    {
        $x = $this->flowWithProposal(['u1']);
        $base = $x['claims'][0]->only(['workflow', 'product_id', 'source_key', 'source_classification', 'source_basis', 'source_fingerprint', 'proposed_serial', 'sale_return_id', 'sale_return_detail_id']);

        try {
            ReturnSerialRegistration::create($base + ['claim_uuid' => $x['claims'][0]->claim_uuid, 'unit_attribution_key' => 'other']);
            $this->fail('duplicate claim uuid accepted');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        }

        try {
            ReturnSerialRegistration::create($base + ['claim_uuid' => (string) \Illuminate\Support\Str::uuid(), 'unit_attribution_key' => $x['claims'][0]->unit_attribution_key]);
            $this->fail('duplicate unit attribution accepted');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        }

        // a return without registration rows is untouched
        $plain = $this->makeReturnFor($x['flow'], 1);
        $this->assertSame(0, ReturnSerialRegistration::where('sale_return_id', $plain->id)->count());
    }

    public function test_foreign_keys_reject_unknown_detail(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        ReturnSerialRegistration::create([
            'claim_uuid' => (string) \Illuminate\Support\Str::uuid(), 'workflow' => 'standard', 'sale_return_id' => 999, 'sale_return_detail_id' => 999,
            'product_id' => 999, 'source_key' => 'x', 'source_classification' => 'quantity_only', 'source_basis' => 'x',
            'source_fingerprint' => 'x', 'unit_attribution_key' => 'k', 'proposed_serial' => 'Z',
        ]);
    }
}

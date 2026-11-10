<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentBuyoutReservation;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Product\Entities\ProductSerialNumber;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ConsignmentBuyoutLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;

    protected Location $standard;
    protected User $admin;
    protected ConsignmentBuyoutLifecycleService $svc2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuyoutFixtures();
        $this->svc2 = new ConsignmentBuyoutLifecycleService();
        $this->standard = Location::create(['setting_id' => $this->setting->id, 'name' => 'Standard', 'is_consignment' => false]);
        $this->admin = $this->userWith([
            'consignments.buyouts.create', 'consignments.buyouts.edit', 'consignments.buyouts.submit', 'consignments.buyouts.approve',
            'consignments.buyouts.reject', 'consignments.buyouts.cancel', 'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve',
        ]);
    }

    private function userWith(array $perms): User
    {
        $u = User::factory()->create();
        $u->settings()->attach($this->setting->id, ['role_id' => \Spatie\Permission\Models\Role::findOrCreate('Buyout Clerk', 'web')->id]);
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
            $u->givePermissionTo($p);
        }

        return $u;
    }

    private function unsold(ConsignmentReceivingDetail $r, string $qty, array $extra = []): array
    {
        return $extra + ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->standard->id,
            'base_quantity' => $qty, 'buckets' => ['NON_TAXED' => $qty]];
    }

    private function draft(array $lines, ?User $u = null): ConsignmentBuyout
    {
        return $this->svc2->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', $lines, $u ?? $this->admin);
    }

    private function active(ConsignmentBuyout $b): int
    {
        return $b->reservations()->where('status', 'ACTIVE')->count();
    }

    /** @test */
    public function approval_alone_reserves_resources_and_moves_no_stock_cost_or_purchase()
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $stockBefore = \DB::table('product_stocks')->first();

        $b = $this->draft([$this->unsold($r, '4')]);
        $this->assertSame(0, $this->active($b));
        $b = $this->svc2->submit($b, $this->admin);
        $this->assertSame('WAITING_APPROVAL', $b->status);
        $this->assertSame(0, $this->active($b), 'waiting documents reserve nothing');

        $b = $this->svc2->approve($b, $this->admin);
        $this->assertSame('APPROVED', $b->status);
        $this->assertSame(64, strlen($b->approved_revision_hash));
        $this->assertTrue($this->svc2->approvedHashIsCurrent($b));
        try {
            $b->lines()->first()->update(['base_quantity' => '3.000']);
            $this->fail('approved line mutated');
        } catch (\DomainException $e) {
            $this->assertTrue(true);
        }
        \DB::table('consignment_buyout_lines')->where('consignment_buyout_id', $b->id)->update(['base_quantity' => '3.000']);
        $this->assertFalse($this->svc2->approvedHashIsCurrent($b), 'changed approved terms invalidate the hash');
        \DB::table('consignment_buyout_lines')->where('consignment_buyout_id', $b->id)->update(['base_quantity' => '4.000']);
        $this->assertSame(2, $this->active($b)); // receipt + physical bucket
        $this->assertSame(6.0, $this->capacity()->receiptCapacity($r->id)['unsold_available']);

        $this->assertEquals($stockBefore, \DB::table('product_stocks')->first());
        $this->assertSame(0, \DB::table('purchases')->count());
        $this->assertSame(['DRAFT_CREATED', 'SUBMITTED', 'APPROVED'], $b->auditEvents()->orderBy('id')->pluck('action')->all());
    }

    private function capacity(): ConsignmentBuyoutCapacityService
    {
        return new ConsignmentBuyoutCapacityService();
    }

    /** @test */
    public function invalid_transitions_and_stale_revisions_are_rejected_without_duplicate_audit()
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $b = $this->draft([$this->unsold($r, '2')]);

        try {
            $this->svc2->approve($b, $this->admin); // DRAFT cannot be approved
            $this->fail('draft approved');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid transition', $e->getMessage());
        }
        try {
            $this->svc2->submit($b, $this->admin, 99);
            $this->fail('stale revision accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('changed by someone else', $e->getMessage());
        }

        $b = $this->svc2->approve($this->svc2->submit($b, $this->admin), $this->admin);
        $events = $b->auditEvents()->count();
        try {
            $this->svc2->approve($b, $this->admin); // duplicate approval
            $this->fail('duplicate approval');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
        $this->assertSame($events, $b->auditEvents()->count());
        $this->assertSame(2, $this->active($b));
    }

    /** @test */
    public function sold_only_billers_gain_no_buyout_access_and_violations_leave_no_state()
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $soldOnly = $this->userWith(['consignments.buyouts.create', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.allocations.approve', 'consignments.billing.convert']);

        try {
            $this->draft([$this->unsold($r, '2')], $soldOnly);
            $this->fail('unsold acquisition without authority');
        } catch (AuthorizationException $e) {
            $this->assertSame(0, ConsignmentBuyout::count());
        }

        $outsider = User::factory()->create();
        $outsider->givePermissionTo(Permission::findOrCreate('consignments.buyouts.create', 'web'));
        $this->expectException(AuthorizationException::class);
        $this->draft([$this->unsold($r, '2')], $outsider);
    }

    /** @test */
    public function approval_requires_both_buyout_and_existing_allocation_approval_authority()
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $b = $this->svc2->submit($this->draft([$this->unsold($r, '2')]), $this->admin);
        $noAlloc = $this->userWith(['consignments.buyouts.approve', 'consignments.buyouts.acquire_unsold']);

        try {
            $this->svc2->approve($b, $noAlloc);
            $this->fail('approved without allocation authority');
        } catch (AuthorizationException $e) {
            $this->assertSame('WAITING_APPROVAL', $b->fresh()->status);
            $this->assertSame(0, ConsignmentBuyoutReservation::count());
        }
    }

    /** @test */
    public function a_later_line_failure_rolls_back_every_reservation_and_status()
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $b = $this->svc2->submit($this->draft([$this->unsold($r, '4')]), $this->admin);
        // A competing approved buyout consumes capacity after submission.
        $c = $this->svc2->approve($this->svc2->submit($this->draft([$this->unsold($r, '8')]), $this->admin), $this->admin);

        try {
            $this->svc2->approve($b, $this->admin);
            $this->fail('over-capacity approval');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds', $e->getMessage());
        }
        $this->assertSame('WAITING_APPROVAL', $b->fresh()->status);
        $this->assertSame(0, $this->active($b));
        $this->assertSame(2, $this->active($c));
        $this->assertSame(1, $b->auditEvents()->where('action', 'APPROVED')->count() + 1);
    }

    /** @test */
    public function reject_and_cancel_release_reservations_and_preserve_stock_cost_and_payables()
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $b = $this->svc2->approve($this->svc2->submit($this->draft([$this->unsold($r, '4')]), $this->admin), $this->admin);
        $this->assertSame(6.0, $this->capacity()->receiptCapacity($r->id)['unsold_available']);

        $b = $this->svc2->cancel($b, 'changed mind', $this->admin);
        $this->assertSame('CANCELLED', $b->status);
        $this->assertSame(0, $this->active($b));
        $this->assertSame(10.0, $this->capacity()->receiptCapacity($r->id)['unsold_available']);
        $this->assertSame(0, \DB::table('purchases')->count());

        try {
            $this->svc2->cancel($b, 'again', $this->admin);
            $this->fail('cancelled twice');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        $w = $this->svc2->submit($this->draft([$this->unsold($r, '1')]), $this->admin);
        $w = $this->svc2->reject($w, 'wrong qty', $this->admin);
        $this->assertSame('REJECTED', $w->status);
        $re = $this->svc2->reviseDraft($w, [$this->unsold($r, '2')], $this->admin);
        $this->assertSame(['DRAFT', 2], [$re->status, $re->revision]);
    }

    /** @test */
    public function approved_reservations_do_not_expire_and_block_other_documents()
    {
        $r = $this->receipt($this->supplier, '5');
        $this->stock('5');
        $other = $this->svc2->submit($this->draft([$this->unsold($r, '1')]), $this->admin);
        $this->svc2->approve($this->svc2->submit($this->draft([$this->unsold($r, '4')]), $this->admin), $this->admin);
        \Carbon\Carbon::setTestNow(now()->addYears(2));
        $this->assertSame(1.0, $this->capacity()->receiptCapacity($r->id)['unsold_available']);
        \Carbon\Carbon::setTestNow();
        // A third document consumes the remainder, so the earlier waiting one can no longer be approved.
        $this->svc2->approve($this->svc2->submit($this->draft([$this->unsold($r, '1')]), $this->admin), $this->admin);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc2->approve($other, $this->admin);
    }

    /** @test */
    public function serialized_selection_is_exact_whole_unique_and_claim_checked_with_atomic_rollback()
    {
        \DB::table('products')->where('id', $this->product->id)->update(['serial_number_required' => true]);
        $r = $this->receipt($this->supplier, '5');
        $this->stock('5');
        $mk = fn (string $sn, array $x = []) => ProductSerialNumber::create(array_merge([
            'product_id' => $this->product->id, 'location_id' => $this->location->id, 'serial_number' => $sn,
            'consignment_receiving_detail_id' => $r->id, 'status' => 'ACTIVE'], $x));
        $s1 = $mk('SN-1');
        $s2 = $mk('SN-2');
        $bad = $mk('SN-BAD', ['is_broken' => true]);
        $line = fn (array $ids, string $q) => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id,
            'destination_location_id' => $this->standard->id, 'base_quantity' => $q, 'serial_ids' => $ids];

        foreach ([
            'fractional' => $line([$s1->id], '1.500'),
            'count mismatch' => $line([$s1->id], '2'),
            'unavailable serial' => $line([$s1->id, $bad->id], '2'),
        ] as $label => $l) {
            try {
                $this->svc2->submit($this->draft([$l]), $this->admin);
                $this->fail("{$label} accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }

        $b = $this->svc2->submit($this->draft([$line([$s1->id, $s2->id], '2')]), $this->admin);
        // A competing document claims s2 first (approved).
        $c = $this->svc2->approve($this->svc2->submit($this->draft([$line([$s2->id], '1')]), $this->admin), $this->admin);
        $this->assertSame(1, ConsignmentActiveSerialClaim::count());

        try {
            $this->svc2->approve($b, $this->admin);
            $this->fail('claimed serial accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('SN-2', $e->getMessage());
        }
        $this->assertSame(1, ConsignmentActiveSerialClaim::count(), 'one bad serial rolls back the whole approval');
        $this->assertSame(0, $this->active($b));

        $this->svc2->cancel($c, 'free it', $this->admin);
        $this->assertSame(0, ConsignmentActiveSerialClaim::count());
        $b = $this->svc2->approve($b, $this->admin);
        $this->assertSame(2, ConsignmentActiveSerialClaim::where('consignment_buyout_id', $b->id)->count());
    }

    /** @test */
    public function consuming_reservations_requires_an_approved_document_and_marks_them_consumed()
    {
        $r = $this->receipt($this->supplier, '5');
        $this->stock('5');
        $b = $this->svc2->approve($this->svc2->submit($this->draft([$this->unsold($r, '2')]), $this->admin), $this->admin);
        \DB::transaction(fn () => $this->svc2->consumeReservations($b));
        $this->assertSame(0, $this->active($b));
        $this->assertSame(2, $b->reservations()->where('status', 'CONSUMED')->count());

        $this->expectException(\InvalidArgumentException::class);
        $b->update(['status' => 'DRAFT']);
        $this->svc2->consumeReservations($b->fresh());
    }

    /** @test */
    public function equivalent_hash_casing_is_accepted_but_changed_evidence_is_rejected()
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $b = $this->svc2->approve($this->svc2->submit($this->draft([$this->unsold($r, '4')]), $this->admin), $this->admin);

        $lower = strtolower($b->approved_revision_hash);
        foreach ([strtoupper($lower), $lower, ucfirst($lower)] as $variant) {
            \DB::table('consignment_buyouts')->where('id', $b->id)->update(['approved_revision_hash' => $variant]);
            $this->assertTrue($this->svc2->approvedHashIsCurrent($b->fresh()), "casing variant {$variant}");
        }

        // Genuinely changed evidence: destination, quantity, snapshot, pricing intent each invalidate.
        $other = Location::create(['setting_id' => $this->setting->id, 'name' => 'Other', 'is_consignment' => false]);
        $mutations = [
            fn () => \DB::table('consignment_buyout_lines')->where('consignment_buyout_id', $b->id)->update(['destination_location_id' => $other->id]),
            fn () => \DB::table('consignment_buyout_lines')->where('consignment_buyout_id', $b->id)->update(['base_quantity' => '3.000']),
            fn () => \DB::table('consignment_buyout_lines')->where('consignment_buyout_id', $b->id)->update(['original_snapshot' => json_encode(['tampered' => 1])]),
            fn () => \DB::table('consignment_buyouts')->where('id', $b->id)->update(['pricing_intent' => json_encode(['discount' => 5])]),
        ];
        foreach ($mutations as $i => $mutate) {
            \DB::beginTransaction();
            $mutate();
            $this->assertFalse($this->svc2->approvedHashIsCurrent($b->fresh()), "mutation {$i} must invalidate");
            \DB::rollBack();
        }
        $this->assertTrue($this->svc2->approvedHashIsCurrent($b->fresh()));
    }

    /** @test */
    public function invoice_tax_terms_never_relabel_physical_buckets_or_serials(): void
    {
        $this->setting->update(['is_pkp' => true]);
        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true, 'is_active' => true]);
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $b = $this->svc2->approve($this->svc2->submit($this->draft([$this->unsold($r, '4')]), $this->admin), $this->admin);
        $b->update(['pricing_intent' => ['is_tax_included' => false, 'rows' => [$b->lines()->first()->id => ['tax_id' => $tax->id, 'unit_price' => 100]]]]);

        $before = $b->reservations()->orderBy('id')->get(['resource_type', 'tax_bucket', 'base_quantity', 'status'])->toArray();
        $result = (new \Modules\Consignment\Services\ConsignmentBuyoutPricingService())->accept($b->fresh());
        $this->assertGreaterThan(0, $result['totals']['tax_amount']);

        $this->assertSame($before, $b->reservations()->orderBy('id')->get(['resource_type', 'tax_bucket', 'base_quantity', 'status'])->toArray());
        $this->assertSame('NON_TAXED', $b->reservations()->where('resource_type', 'PHYSICAL_BUCKET')->value('tax_bucket'));
        $this->assertEquals(10, \DB::table('product_stocks')->first()->quantity_non_tax, 'stock buckets untouched');
        $this->assertNotNull($b->lines()->first()->fresh()->accepted_snapshot);
    }
}

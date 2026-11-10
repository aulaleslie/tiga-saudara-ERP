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
use Tests\TestCase;

class ConsignmentBuyoutCapacityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuyoutFixtures();
    }

    /** @test */
    public function attribution_example_a10_b8_sold6_reconciles_without_billing_b()
    {
        $A = $this->supplier('A');
        $B = $this->supplier('B');
        $ra = $this->receipt($A, '10');
        $rb = $this->receipt($B, '8');
        $src = $this->soldSource('6');

        $before = $this->svc->receiptCapacity($ra->id);
        $this->assertSame(10.0, $before['physical_residual']);
        $this->assertNotEmpty($this->svc->soldAttribution($src)['blockers'], 'unattributed sale must block');

        $result = $this->svc->setAttribution($src->id, [$ra->id => '4', $rb->id => '2'], null, 'staff review');
        $this->assertTrue($result['complete']);

        $capA = $this->svc->receiptCapacity($ra->id);
        $capB = $this->svc->receiptCapacity($rb->id);
        $this->assertSame(6.0, $capA['physical_residual']);
        $this->assertSame(6.0, $capB['physical_residual']);
        // Attribution is non-financial: no invoice or financial consumption for B.
        $this->assertSame(8.0, $capB['financial_available']);
        $this->assertSame(0, ConsignmentBillingConfirmationLine::count());
        $this->assertSame(0, ConsignmentBuyout::count());
    }

    /** @test */
    public function attribution_must_equal_sold_quantity_and_match_the_pool()
    {
        $A = $this->supplier('A');
        $ra = $this->receipt($A, '10');
        $src = $this->soldSource('6');

        $this->expectException(\DomainException::class);
        $this->svc->setAttribution($src->id, [$ra->id => '5'], null, 'short');
    }

    /** @test */
    public function attribution_cannot_exceed_received_or_change_once_reserved()
    {
        $A = $this->supplier('A');
        $ra = $this->receipt($A, '4');
        $src = $this->soldSource('5');
        try {
            $this->svc->setAttribution($src->id, [$ra->id => '5'], null, 'too many');
            $this->fail('over-attribution accepted');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('exceed', $e->getMessage());
        }

        $rb = $this->receipt($A, '10');
        $this->svc->setAttribution($src->id, [$ra->id => '2', $rb->id => '3'], null, 'ok');
        $bo = $this->buyout($A, 'APPROVED');
        $l = $this->line($bo, $rb, 'UNSOLD_BUYOUT', '1');
        $this->reserve($bo, $l, 'RECEIPT', '1', ['consignment_receiving_detail_id' => $rb->id]);

        $this->expectException(\DomainException::class);
        $this->svc->setAttribution($src->id, [$ra->id => '3', $rb->id => '2'], null, 'shift');
    }

    /** @test */
    public function unknown_snapshot_versions_unsupported_dispatches_and_missing_discovery_block_the_pool()
    {
        $A = $this->supplier('A');
        $ra = $this->receipt($A, '10');
        $src = $this->soldSource('2');
        $this->svc->setAttribution($src->id, [$ra->id => '2'], null, 'ok');
        $this->assertSame([], $this->svc->poolBlockers($this->setting->id, $this->product->id, $this->location->id));

        $this->raw($src, ['source_snapshot' => json_encode(['snapshot_version' => 99])]);
        $types = array_column($this->svc->poolBlockers($this->setting->id, $this->product->id, $this->location->id), 'type');
        $this->assertContains('UNKNOWN_SNAPSHOT_VERSION', $types);
        $this->raw($src, ['source_snapshot' => json_encode([]), 'has_reconstruction_blocker' => true, 'blocker_reason' => 'missing lineage']);
        $types = array_column($this->svc->poolBlockers($this->setting->id, $this->product->id, $this->location->id), 'type');
        $this->assertContains('RECONSTRUCTION', $types);

        $this->raw($src, ['has_reconstruction_blocker' => false]);
        $other = $this->soldSource('1'); // captured but unattributed
        $types = array_column($this->svc->poolBlockers($this->setting->id, $this->product->id, $this->location->id), 'type');
        $this->assertContains('ATTRIBUTION', $types);

        // An approved dispatch with no sold source is incomplete discovery.
        \DB::table('consignment_sold_sources')->where('id', $other->id)->delete();
        $types = array_column($this->svc->poolBlockers($this->setting->id, $this->product->id, $this->location->id), 'type');
        $this->assertContains('DISCOVERY_INCOMPLETE', $types);
    }

    /** @test */
    public function receipt_capacity_counts_legacy_and_buyout_states_exactly_once()
    {
        $A = $this->supplier('A');
        $r = $this->receipt($A, '10');
        $src = $this->soldSource('10');
        $this->svc->setAttribution($src->id, [$r->id => '10'], null, 'all');

        // draft / rejected / cancelled / reversed buyouts consume nothing.
        foreach (['DRAFT', 'REJECTED', 'CANCELLED', 'REVERSED'] as $st) {
            $b = $this->buyout($A, $st);
            $this->line($b, $r, 'SOLD_UNBILLED', '3', $src);
        }
        $this->assertSame(10.0, $this->svc->receiptCapacity($r->id)['financial_available']);

        // approved with active reservation
        $b = $this->buyout($A, 'APPROVED');
        $l = $this->line($b, $r, 'SOLD_UNBILLED', '2', $src);
        $this->reserve($b, $l, 'RECEIPT', '2', ['consignment_receiving_detail_id' => $r->id]);
        $this->assertSame(8.0, $this->svc->receiptCapacity($r->id)['financial_available']);

        // finalized: consumed once (reservation moves to CONSUMED, excluded from active)
        $b->update(['status' => 'FINALIZED']);
        ConsignmentBuyoutReservation::query()->update(['status' => 'CONSUMED']);
        $this->assertSame(8.0, $this->svc->receiptCapacity($r->id)['financial_available']);
        $this->assertSame(2.0, $this->svc->receiptCapacity($r->id)['buyout_finalized']);
        $this->assertSame(0.0, $this->svc->receiptCapacity($r->id)['buyout_reserved']);
    }

    /** @test */
    public function validate_slices_aggregates_duplicates_and_reports_conservation_mismatch()
    {
        $A = $this->supplier('A');
        $r = $this->receipt($A, '5');
        $errors = $this->svc->validateSlices([
            ['type' => 'UNSOLD_BUYOUT', 'receiving_detail_id' => $r->id, 'sold_source_id' => null, 'quantity' => '3'],
            ['type' => 'UNSOLD_BUYOUT', 'receiving_detail_id' => $r->id, 'sold_source_id' => null, 'quantity' => '3'],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertSame([], $this->svc->validateSlices([
            ['type' => 'UNSOLD_BUYOUT', 'receiving_detail_id' => $r->id, 'sold_source_id' => null, 'quantity' => '2.500'],
            ['type' => 'UNSOLD_BUYOUT', 'receiving_detail_id' => $r->id, 'sold_source_id' => null, 'quantity' => '2.500'],
        ]));

        // Ledger inconsistency (attribution exceeds receipt) is reported, not clamped.
        $src = $this->soldSource('9');
        \DB::table('consignment_sold_receipt_attributions')->insert(['setting_id' => $this->setting->id,
            'consignment_sold_source_id' => $src->id, 'consignment_receiving_detail_id' => $r->id, 'attributed_base_quantity' => '9',
            'returned_base_quantity' => 0, 'returned_acquired_quantity' => 0, 'revision' => 1, 'origin' => 'EXPLICIT', 'created_at' => now(), 'updated_at' => now()]);
        $cap = $this->svc->receiptCapacity($r->id);
        $this->assertSame(-4.0, $cap['physical_residual']);
        $this->assertNotEmpty($cap['conservation_errors']);
        $this->assertNotEmpty($this->svc->validateSlices([
            ['type' => 'UNSOLD_BUYOUT', 'receiving_detail_id' => $r->id, 'sold_source_id' => null, 'quantity' => '1'],
        ]));
    }

    /** @test */
    public function sold_source_capacity_subtracts_legacy_and_buyout_consumers()
    {
        $A = $this->supplier('A');
        $r = $this->receipt($A, '10');
        $src = $this->soldSource('6');
        $conf = ConsignmentBillingConfirmation::create(['setting_id' => $this->setting->id, 'supplier_id' => $A->id,
            'confirmation_number' => 'C-1', 'status' => 'WAITING_APPROVAL', 'date' => now()]);
        ConsignmentBillingConfirmationLine::create(['consignment_billing_confirmation_id' => $conf->id,
            'consignment_sold_source_id' => $src->id, 'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'allocated_base_quantity' => '2']);
        $b = $this->buyout($A, 'APPROVED');
        $l = $this->line($b, $r, 'SOLD_UNBILLED', '1.500', $src);
        $this->reserve($b, $l, 'SOLD_SOURCE', '1.500', ['consignment_sold_source_id' => $src->id]);

        $cap = $this->svc->soldSourceCapacity($src);
        $this->assertSame(2.5, $cap['available']);
        $this->assertSame(4.0, $this->svc->soldSourceCapacity($src, $b->id)['available']);
    }

    /** @test */
    public function physical_availability_requires_sellable_stock_and_respects_claims()
    {
        $A = $this->supplier('A');
        $r = $this->receipt($A, '10');
        $pool = $this->svc->physicalPool($this->setting->id, $this->product->id, $this->location->id);
        // No stock row: financial residual alone cannot permit purchase.
        $this->assertSame(0.0, $pool['physically_available']);
        $this->assertNotEmpty($pool['blockers']);

        $this->stock('10');
        $pool = $this->svc->physicalPool($this->setting->id, $this->product->id, $this->location->id);
        $this->assertSame(10.0, $pool['physically_available']);

        $b = $this->buyout($A, 'APPROVED');
        $l = $this->line($b, $r, 'UNSOLD_BUYOUT', '4');
        $this->reserve($b, $l, 'PHYSICAL_BUCKET', '4', ['product_id' => $this->product->id, 'location_id' => $this->location->id]);
        $this->assertSame(6.0, $this->svc->physicalPool($this->setting->id, $this->product->id, $this->location->id)['physically_available']);
    }

    /** @test */
    public function damaged_or_moved_stock_blocks_the_pool_instead_of_borrowing_other_stock()
    {
        $A = $this->supplier('A');
        $this->receipt($A, '10');
        $this->stock('7', '3'); // 3 units are damaged: sellable 7 < residual 10
        $pool = $this->svc->physicalPool($this->setting->id, $this->product->id, $this->location->id);
        $this->assertSame(0.0, $pool['physically_available']);
        $this->assertStringContainsString('ambiguous', implode(' ', $pool['blockers']));
    }

    private function lifecycle(): \Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService
    {
        return new \Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService(
            new \Modules\Consignment\Services\ConsignmentReturnEligibilityService(),
            new \Modules\Consignment\Services\ConsignmentReceiptAllocationService()
        );
    }

    /** Give a fixture source the live canonical hash so lifecycle revalidation accepts it. */
    private function hashed(ConsignmentSoldSource $src): ConsignmentSoldSource
    {
        $hash = $this->lifecycle()->computeCanonicalLiveHash($src->fresh());
        \DB::table('consignment_sold_sources')->where('id', $src->id)->update(['source_hash' => $hash]);

        return $src->fresh();
    }

    private function draft(Supplier $s, ConsignmentSoldSource $src, ConsignmentReceivingDetail $r, string $qty): ConsignmentBillingConfirmation
    {
        return $this->lifecycle()->createDraft($this->setting->id, $s->id, date('Y-m-d'), [[
            'consignment_sold_source_id' => $src->id, 'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'allocated_base_quantity' => $qty,
            'receipt_allocations' => [['consignment_receiving_detail_id' => $r->id, 'allocated_base_quantity' => $qty]],
        ]], 'n', null);
    }

    /** @test */
    public function legacy_submit_adopts_matching_attribution_and_respects_established_attribution()
    {
        $A = $this->supplier('A');
        $B = $this->supplier('B');
        $ra = $this->receipt($A, '10');
        $rb = $this->receipt($B, '8');
        $src = $this->hashed($this->soldSource('6'));

        // No attribution yet: A's confirmation of 4 adopts 4 for A's receipt (partial attribution, pool still blocked).
        $conf = $this->lifecycle()->submitConfirmation($this->draft($A, $src, $ra, '4'));
        $row = \DB::table('consignment_sold_receipt_attributions')->where('consignment_sold_source_id', $src->id)->first();
        $this->assertSame($ra->id, (int) $row->consignment_receiving_detail_id);
        $this->assertSame('LEGACY_ALLOCATION', $row->origin);
        $this->assertEquals(4, $row->attributed_base_quantity);
        $this->assertNotEmpty($this->svc->soldAttribution($src)['blockers']);

        // Staff complete the attribution for B (A's billed 4 cannot be lowered).
        $this->svc->setAttribution($src->id, [$ra->id => '4', $rb->id => '2'], null, 'review');
        $this->lifecycle()->approveConfirmation($conf);
        $this->assertSame(6.0, $this->svc->receiptCapacity($ra->id)['physical_residual']);
        $this->assertSame(6.0, $this->svc->receiptCapacity($rb->id)['physical_residual']);

        try {
            $this->svc->setAttribution($src->id, [$ra->id => '3', $rb->id => '3'], null, 'steal');
            $this->fail('attribution moved away from billed quantity');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('billed', $e->getMessage());
        }
    }

    /** @test */
    public function conflicting_supplier_assignment_fails_atomically_on_submit()
    {
        $A = $this->supplier('A');
        $B = $this->supplier('B');
        $ra = $this->receipt($A, '10');
        $rb = $this->receipt($B, '8');
        $src = $this->hashed($this->soldSource('6'));
        $this->svc->setAttribution($src->id, [$ra->id => '4', $rb->id => '2'], null, 'review');

        // B tries to bill 5 from a sale only 2 of which are attributed to B (A already holds 4 of the 6).
        $draft = $this->draft($B, $src, $rb, '5');
        try {
            $this->lifecycle()->submitConfirmation($draft);
            $this->fail('conflicting supplier assignment accepted');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('conflicts', $e->getMessage());
        }
        $this->assertSame('DRAFT', $draft->fresh()->status);
        $this->assertSame(2, \DB::table('consignment_sold_receipt_attributions')->count());
        $this->assertEquals(4, \DB::table('consignment_sold_receipt_attributions')->where('consignment_receiving_detail_id', $ra->id)->value('attributed_base_quantity'));
    }

    /** @test */
    public function unsold_buckets_do_not_relabel_taxed_and_non_taxed_stock()
    {
        $A = $this->supplier('A');
        $this->receipt($A, '10');
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10,
            'quantity_tax' => 4, 'quantity_non_tax' => 6, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0]);

        $args = [$this->setting->id, $this->product->id, $this->location->id];
        $this->assertSame([], $this->svc->validateBuckets(...$args, ...[['taxed' => '4', 'non_taxed' => '6']]));
        $this->assertNotEmpty($this->svc->validateBuckets(...$args, ...[['taxed' => '5']]));

        $b = $this->buyout($A, 'APPROVED');
        $l = $this->line($b, ConsignmentReceivingDetail::first(), 'UNSOLD_BUYOUT', '3');
        $this->reserve($b, $l, 'PHYSICAL_BUCKET', '3', ['product_id' => $this->product->id, 'location_id' => $this->location->id, 'tax_bucket' => 'TAXED']);
        $pool = $this->svc->physicalPool(...$args);
        $this->assertSame(1.0, $pool['bucket_available']['taxed']);
        $this->assertSame(6.0, $pool['bucket_available']['non_taxed']);
        $this->assertNotEmpty($this->svc->validateBuckets(...$args, ...[['taxed' => '2']]));
    }

    /** @test */
    public function held_broken_returning_and_claimed_serials_are_not_selectable()
    {
        $A = $this->supplier('A');
        $r = $this->receipt($A, '5');
        $mk = fn (string $sn, array $x = []) => \Modules\Product\Entities\ProductSerialNumber::create(array_merge([
            'product_id' => $this->product->id, 'location_id' => $this->location->id, 'serial_number' => $sn,
            'consignment_receiving_detail_id' => $r->id, 'status' => 'ACTIVE'], $x));
        $ok = $mk('S-OK');
        $mk('S-BROKEN', ['is_broken' => true]);
        $mk('S-RET', ['is_in_return_process' => true]);
        $mk('S-SOLD', ['status' => 'SOLD']);
        $claimed = $mk('S-CLAIM');
        $nolineage = $mk('S-NOLIN', ['consignment_receiving_detail_id' => null]);

        $b = $this->buyout($A, 'APPROVED');
        $l = $this->line($b, $r, 'UNSOLD_BUYOUT', '1');
        $bs = \Modules\Consignment\Entities\ConsignmentBuyoutSerial::create(['consignment_buyout_id' => $b->id,
            'consignment_buyout_line_id' => $l->id, 'product_serial_number_id' => $claimed->id, 'consignment_receiving_detail_id' => $r->id]);
        \Modules\Consignment\Entities\ConsignmentActiveSerialClaim::create(['owner_type' => 'BUYOUT', 'product_serial_number_id' => $claimed->id,
            'consignment_buyout_id' => $b->id, 'consignment_buyout_serial_id' => $bs->id]);

        $got = $this->svc->selectableSerialIds($this->product->id, $this->location->id);
        $this->assertSame([$ok->id], $got, json_encode(\DB::table('product_serial_numbers')->get(['id','serial_number','status','is_broken','is_in_return_process','consignment_receiving_detail_id'])));
    }
}

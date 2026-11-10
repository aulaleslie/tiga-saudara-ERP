<?php

namespace Modules\Consignment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;
use Modules\Consignment\Services\ConsignmentBuyoutProposalService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Tests\TestCase;

/**
 * Regression tests for the spec review: exact sold source/receipt attribution, live evidence revalidation,
 * inactive destinations, and receipt-specific "all eligible" sold proposals. SQLite integration; not concurrency proof.
 */
class ConsignmentBuyoutReviewFindingsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
    }

    private function soldLine($b, $receipt, $src, string $qty): void
    {
        $this->line($b, $receipt, ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED, $qty, $src);
        \DB::table('consignment_buyout_lines')->where('consignment_buyout_id', $b->id)->update(['destination_location_id' => $this->dest1->id]);
    }

    private function errors($b): string
    {
        return implode(' | ', $this->life->validateDocument($b->fresh()));
    }

    /** @test */
    public function a_sold_slice_must_pair_with_a_receipt_the_source_is_really_attributed_to(): void
    {
        $a = $this->receipt($this->supplier, '5');
        $b = $this->receipt($this->supplier, '5');
        $src = $this->soldSource('5');
        $this->capacity()->setAttribution($src->id, [$b->id => '5'], null, 'attribute');   // attributed to B only
        $this->stock('10');

        $wrong = $this->buyout($this->supplier);
        $this->soldLine($wrong, $a, $src, '2');
        $this->assertStringContainsString("attributed to receipt #{$a->id}", $this->errors($wrong));

        $right = $this->buyout($this->supplier);
        $this->soldLine($right, $b, $src, '2');
        $this->assertStringNotContainsString('attributed to receipt', $this->errors($right), 'negative control: the attributed receipt passes');
    }

    /** @test */
    public function a_source_of_another_product_cannot_be_paired_with_a_receipt(): void
    {
        $r = $this->receipt($this->supplier, '5');
        $src = $this->soldSource('2');
        \DB::table('products')->insert(['product_name' => 'Other', 'product_code' => 'OTH', 'product_quantity' => 0, 'product_cost' => 1,
            'product_price' => 1, 'product_unit' => 'pcs', 'product_stock_alert' => 0, 'setting_id' => $this->setting->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->raw($src, ['product_id' => Product::where('product_code', 'OTH')->value('id')]);
        $this->stock('5');

        $b = $this->buyout($this->supplier);
        $this->soldLine($b, $r, $src->fresh(), '1');
        $this->assertStringContainsString('does not belong to the product/location', $this->errors($b));
    }

    /** @test */
    public function the_per_receipt_attribution_not_just_the_source_total_bounds_the_slice(): void
    {
        $a = $this->receipt($this->supplier, '5');
        $b = $this->receipt($this->supplier, '5');
        $src = $this->soldSource('5');
        $this->capacity()->setAttribution($src->id, [$a->id => '3', $b->id => '2'], null, 'attribute');
        $this->stock('10');

        $over = $this->buyout($this->supplier);
        $this->soldLine($over, $a, $src, '4');                   // source has 5, but only 3 belong to A
        $this->assertStringContainsString("only 3", $this->errors($over));

        $fits = $this->buyout($this->supplier);
        $this->soldLine($fits, $a, $src, '3');
        $this->assertStringNotContainsString('attributed to receipt', $this->errors($fits));
    }

    /** @test */
    public function changed_dispatch_evidence_is_detected_and_blocks_approval_and_finalization(): void
    {
        [$b, $r, $src] = $this->mixed();
        $this->assertSame([], $this->life->validateDocument($b->fresh()), 'negative control: untouched evidence validates');

        // The authoritative dispatch is altered after approval; the stored source row still carries the old hash.
        \DB::table('dispatch_details')->where('id', $src->dispatch_detail_id)->update(['dispatched_quantity' => 6]);

        $this->assertStringContainsString('live dispatch evidence', $this->errors($b));
        $before = $this->postingCounts();
        try {
            $this->fin->finalize($b->fresh(), $this->user);
            $this->fail('finalization must refuse changed dispatch evidence');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('evidence', strtolower($e->getMessage()));
        }
        $this->assertSame($before, $this->postingCounts());
    }

    /** @test */
    public function a_destination_deactivated_after_approval_blocks_finalization_without_posting(): void
    {
        [$b] = $this->mixed();
        $this->assertSame([], $this->life->validateDocument($b->fresh()), 'negative control: active destination validates');

        \DB::table('locations')->where('id', $this->dest1->id)->update(['is_active' => false]);
        $this->assertStringContainsString('active standard location', $this->errors($b));

        $before = $this->postingCounts();
        try {
            $this->fin->finalize($b->fresh(), $this->user);
            $this->fail('finalization must refuse an inactive destination');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('active standard location', $e->getMessage());
        }
        $this->assertSame($before, $this->postingCounts());
    }

    /** @test */
    public function all_eligible_proposes_receipt_specific_sold_quantities_that_never_exceed_the_source(): void
    {
        $a = $this->receipt($this->supplier, '5');
        $b = $this->receipt($this->supplier, '5');
        $src = $this->soldSource('5');
        $this->capacity()->setAttribution($src->id, [$a->id => '3', $b->id => '2'], null, 'attribute');
        $this->stock('4');

        $rows = (new ConsignmentBuyoutProposalService())->allEligible($this->setting->id, $this->supplier->id, $this->dest1->id)['sold'];
        $byReceipt = collect($rows)->keyBy('consignment_receiving_detail_id');

        $this->assertEquals(3.0, $byReceipt[$a->id]['proposed']);
        $this->assertEquals(2.0, $byReceipt[$b->id]['proposed']);
        $this->assertEquals(5.0, array_sum(array_column($rows, 'proposed')), 'the source is proposed once in total, not once per receipt');
    }

    /** @test */
    public function all_eligible_serial_selection_follows_receipt_membership_and_skips_claimed_serials(): void
    {
        \DB::table('products')->where('id', $this->product->id)->update(['serial_number_required' => true]);
        $a = $this->receipt($this->supplier, '2');
        $b = $this->receipt($this->supplier, '2');
        $src = $this->soldSource('3');
        $mk = fn ($sn, $receipt) => ProductSerialNumber::create(['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'serial_number' => $sn, 'consignment_receiving_detail_id' => $receipt->id, 'is_broken' => false, 'status' => 'sold']);
        $sa1 = $mk('A1', $a);
        $sa2 = $mk('A2', $a);
        $sb1 = $mk('B1', $b);
        foreach ([$sa1, $sa2, $sb1] as $s) {
            ConsignmentSoldSourceSerial::create(['consignment_sold_source_id' => $src->id, 'product_serial_number_id' => $s->id]);
        }
        // A2 is already claimed by another document.
        $other = $this->buyout($this->supplier);
        $ol = $this->line($other, $a, ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED, '1', $src);
        $bs = \Modules\Consignment\Entities\ConsignmentBuyoutSerial::create(['consignment_buyout_id' => $other->id,
            'consignment_buyout_line_id' => $ol->id, 'product_serial_number_id' => $sa2->id, 'consignment_receiving_detail_id' => $a->id]);
        \Modules\Consignment\Entities\ConsignmentActiveSerialClaim::create(['owner_type' => 'BUYOUT', 'product_serial_number_id' => $sa2->id,
            'consignment_buyout_id' => $other->id, 'consignment_buyout_serial_id' => $bs->id]);

        $rows = collect((new ConsignmentBuyoutProposalService())->allEligible($this->setting->id, $this->supplier->id, $this->dest1->id)['sold'])
            ->keyBy('consignment_receiving_detail_id');

        $this->assertSame([$sa1->id], $rows[$a->id]['serial_ids'], 'only receipt A\'s unclaimed serial');
        $this->assertSame([$sb1->id], $rows[$b->id]['serial_ids'], 'receipt B gets its own serial, never A\'s');
    }

    /** @test */
    public function legacy_pending_and_approved_allocations_consume_the_exact_source_receipt_pair(): void
    {
        $a = $this->receipt($this->supplier, '5');
        $b = $this->receipt($this->supplier, '5');
        $src = $this->soldSource('10');
        $this->capacity()->setAttribution($src->id, [$a->id => '5', $b->id => '5'], null, 'attribute');
        $this->stock('10');
        $legacy = app(\Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService::class);
        $draft = fn ($r, $q) => $legacy->createDraft($this->setting->id, $this->supplier->id, date('Y-m-d'), [[
            'consignment_sold_source_id' => $src->id, 'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'allocated_base_quantity' => $q, 'receipt_allocations' => [['consignment_receiving_detail_id' => $r->id, 'allocated_base_quantity' => $q]]]], 'n', null);

        $this->assertEquals(5.0, $this->capacity()->soldReceiptResidual($src->fresh(), $a->id), 'control: nothing consumed yet');

        $conf = $legacy->submitConfirmation($draft($a, '5'));                // pending (WAITING_APPROVAL)
        $this->assertEquals(0.0, $this->capacity()->soldReceiptResidual($src->fresh(), $a->id), 'pending legacy allocation consumes A');
        $this->assertEquals(5.0, $this->capacity()->soldReceiptResidual($src->fresh(), $b->id), 'B is untouched');
        $over = $this->buyout($this->supplier);
        $this->soldLine($over, $a, $src->fresh(), '3');
        $this->assertStringContainsString('only 0', $this->errors($over));
        $ok = $this->buyout($this->supplier);
        $this->soldLine($ok, $b, $src->fresh(), '3');
        $this->assertStringNotContainsString('attributed to receipt', $this->errors($ok), 'negative control: B\'s share is still free');

        $legacy->approveConfirmation($conf);                                  // approved
        $this->assertEquals(0.0, $this->capacity()->soldReceiptResidual($src->fresh(), $a->id), 'approved legacy allocation consumes A');
        $this->assertStringContainsString('only 0', $this->errors($over));
    }
}

<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Consignment\Services\ConsignmentBuyoutReversalService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Services\HistoricalReplayEngine;
use Tests\TestCase;

/**
 * Historical cost replay with consignment buyout cost events: no second receipt, exactly-once adjustment, reversal
 * restores, legacy results unchanged.
 */
class ConsignmentBuyoutCostReplayTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
    }

    /** Ordinary approved receipt of 10 @ 100 yesterday: the opening state of the replay. */
    private function ordinaryReceipt(string $source = Purchase::SOURCE_ORDINARY, bool $withReceivedNote = true): Purchase
    {
        $purchase = Purchase::create(['setting_id' => $this->setting->id, 'date' => now()->subDay(), 'due_date' => now(), 'reference' => 'PO-RP-' . uniqid(),
            'supplier_id' => $this->supplier->id, 'status' => Purchase::STATUS_RECEIVED, 'payment_status' => 'Unpaid', 'payment_method' => 'Cash',
            'total_amount' => 1000, 'paid_amount' => 0, 'due_amount' => 1000, 'source_type' => $source]);
        $detail = PurchaseDetail::create(['purchase_id' => $purchase->id, 'product_id' => $this->product->id, 'product_name' => 'W', 'product_code' => 'W',
            'quantity' => 10, 'price' => 100, 'unit_price' => 100, 'sub_total' => 1000, 'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0]);
        if ($withReceivedNote) {
            $note = ReceivedNote::create(['po_id' => $purchase->id, 'date' => now()->subDay(), 'location_id' => $this->location->id,
                'status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()->subDay()]);
            ReceivedNoteDetail::create(['received_note_id' => $note->id, 'quantity_received' => 10, 'po_detail_id' => $detail->id]);
        }

        return $purchase;
    }

    private function replay(): array
    {
        $engine = new HistoricalReplayEngine();
        $events = $engine->collectTimelineEvents($this->product->id, now()->addMinute(), $this->setting->id);

        return [$events, $engine->replayAverageCost($events), $engine->replayWithBucketIsolation($events)];
    }

    /** @test */
    public function an_acquisition_adjusts_the_replayed_average_exactly_once_and_adds_no_receipt(): void
    {
        $this->ordinaryReceipt();
        [$b] = $this->mixed(null, 120.0);
        $purchase = $this->fin->finalize($b, $this->user);

        [$events, $average, $isolated] = $this->replay();

        // One real receipt (the ordinary purchase) and one zero-quantity cost event; the buyout Purchase is NOT a receipt.
        $this->assertSame(1, $events->where('type', 'purchase')->count());
        $this->assertEquals(10.0, (float) $events->firstWhere('type', 'purchase')['quantity']);
        $this->assertSame(1, $events->where('type', 'cost_replace')->count());
        $this->assertEquals(0, $events->firstWhere('type', 'cost_replace')['quantity']);
        $this->assertSame(0, $events->where('model.purchase_id', $purchase->id)->count(), 'no event comes from the buyout Purchase details');
        // (1000 - 3*100 + 360) / 10 = 106 : the same figure the live posting produced.
        $this->assertSame(106.0, $average);
        $this->assertSame(106.0, $isolated['final_average']);
        $this->assertEquals((float) \Modules\Product\Entities\ProductPrice::first()->average_purchase_price, $average);

        // Replaying again (idempotent): same events, same figure, nothing accumulates.
        [$events2, $average2] = $this->replay();
        $this->assertEquals($events->pluck('id')->all(), $events2->pluck('id')->all());
        $this->assertSame($average, $average2);
    }

    /** @test */
    public function a_reversal_restores_the_replayed_average_without_double_counting(): void
    {
        $this->ordinaryReceipt();
        [$b] = $this->mixed(null, 120.0);
        $this->fin->finalize($b, $this->user);
        Carbon::setTestNow(now()->addSeconds(30));
        (new ConsignmentBuyoutReversalService())->reverse($b->fresh(), $this->user, 'undo');
        Carbon::setTestNow();

        $engine = new HistoricalReplayEngine();
        $events = $engine->collectTimelineEvents($this->product->id, now()->addMinute(), $this->setting->id);
        $this->assertSame(2, $events->where('type', 'cost_replace')->count());
        $this->assertSame(['ACQUISITION', 'REVERSAL'], $events->where('type', 'cost_replace')->pluck('kind')->values()->all());
        $this->assertSame(100.0, $engine->replayAverageCost($events));
        $this->assertSame(100.0, $engine->replayWithBucketIsolation($events)['final_average']);

        // As of a moment between the two events the acquisition is in force.
        $mid = $events->where('type', 'cost_replace')->first()['date'];
        $midEvents = $engine->collectTimelineEvents($this->product->id, Carbon::parse($mid), $this->setting->id);
        $this->assertSame(106.0, $engine->replayAverageCost($midEvents));
    }

    /** @test */
    public function legacy_results_are_unchanged_when_no_buyout_exists(): void
    {
        $this->ordinaryReceipt();
        // A legacy sold-only billing Purchase (RECEIVED, no Received Note) keeps its existing fallback behaviour.
        $this->ordinaryReceipt(Purchase::SOURCE_CONSIGNMENT_BILLING, false);

        [$events, $average] = $this->replay();

        $this->assertSame(0, $events->where('type', 'cost_replace')->count(), 'installing buyout support adds nothing by itself');
        $this->assertSame(2, $events->where('type', 'purchase')->count(), 'legacy billing fallback is untouched');
        $this->assertSame(100.0, $average);
    }

    /** @test */
    public function a_buyout_purchase_never_enters_the_fallback_even_without_received_notes(): void
    {
        $this->ordinaryReceipt();
        $buyoutLike = $this->ordinaryReceipt(Purchase::SOURCE_CONSIGNMENT_BUYOUT, false);

        [$events] = $this->replay();

        $this->assertSame(1, $events->where('type', 'purchase')->count());
        $this->assertSame(0, $events->filter(fn ($e) => ($e['model']->purchase_id ?? null) === $buyoutLike->id)->count());
    }
}

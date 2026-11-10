<?php

namespace Modules\Consignment\Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Modules\Consignment\Services\ConsignmentBuyoutReversalService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Consignment\Tests\Support\RunsBuyoutRaceWorkers;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Ordinary purchase-receiving approval (changes the shared average cost) versus buyout finalization / reversal, as real
 * OS processes on the isolated MySQL database. The append-only cost ledger is the oracle: whatever order wins, the ledger
 * must be a gap-free chain (each row's old = the previous row's new, i.e. no lost update), the live average must equal the
 * last ledger value, the buyout's cost evidence must point at its own ledger row and carry the average it really replaced,
 * and a reversal must be refused whenever another cost event follows the acquisition.
 *
 * @group mysql
 */
class ConsignmentBuyoutCostRaceMysqlTest extends TestCase
{
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;
    use RunsBuyoutRaceWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }
        $this->truncateAll();
        $this->setUpFinalizationFixtures();
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql_test') {
            $this->truncateAll();
        }
        parent::tearDown();
    }

    private function ledger(): array
    {
        return DB::table('product_price_cost_events')->orderBy('id')->get(['id', 'old_average', 'new_average'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'old' => $r->old_average === null ? null : (float) $r->old_average, 'new' => (float) $r->new_average])->all();
    }

    /** Gap-free chain + live average equals the last ledger value. */
    private function assertLedgerIsAChain(array $ledger, string $why): void
    {
        for ($i = 1; $i < count($ledger); $i++) {
            $this->assertEqualsWithDelta($ledger[$i - 1]['new'], $ledger[$i]['old'], 0.0051, "lost update between ledger rows {$ledger[$i - 1]['id']} and {$ledger[$i]['id']}: {$why}");
        }
        $this->assertEqualsWithDelta(end($ledger)['new'], (float) DB::table('product_prices')->where('product_id', $this->product->id)->value('average_purchase_price'), 0.0051, "live average != last ledger value: {$why}");
    }

    /** Pending ordinary receiving of 10 units into a standard location (changes the average). */
    private function pendingReceiving(): ReceivedNote
    {
        foreach (['purchases.receive', 'purchases.receive.approval'] as $p) {
            Permission::findOrCreate($p, 'web');
            $this->user->givePermissionTo($p);
        }
        DB::table('products')->where('id', $this->product->id)->update(['stock_managed' => 1]);
        $purchase = Purchase::create(['date' => now(), 'due_date' => now()->addDays(30), 'reference' => 'PO-COST-' . uniqid(), 'supplier_id' => $this->supplier->id,
            'supplier_name' => 'S', 'status' => Purchase::STATUS_APPROVED, 'payment_status' => 'Unpaid', 'payment_method' => 'Cash',
            'total_amount' => 5000, 'paid_amount' => 0, 'due_amount' => 5000, 'setting_id' => $this->setting->id]);
        $detail = PurchaseDetail::create(['purchase_id' => $purchase->id, 'product_id' => $this->product->id, 'product_name' => 'W', 'product_code' => 'W-BO',
            'quantity' => 10, 'price' => 500, 'unit_price' => 500, 'sub_total' => 5000, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $note = ReceivedNote::create(['po_id' => $purchase->id, 'date' => now(), 'location_id' => $this->otherStd->id, 'status' => ReceivedNote::STATUS_PENDING]);
        ReceivedNoteDetail::create(['received_note_id' => $note->id, 'quantity_received' => 10, 'po_detail_id' => $detail->id]);

        return $note;
    }

    private function approveRoute(ReceivedNote $n): string
    {
        return route('receivings.approve', $n->id, false);
    }

    /** @test negative control: the receiving worker really moves the average and writes a ledger row */
    public function receiving_approval_alone_changes_the_average_in_a_worker(): void
    {
        [$b] = $this->mixed(null, 150.0);
        $note = $this->pendingReceiving();
        $before = $this->ledger();
        $results = $this->race([['http', $this->approveRoute($note)]]);
        $after = $this->ledger();

        $this->assertSame(1, $this->ok($results), implode(' | ', $results));
        $this->assertCount(count($before) + 1, $after, 'the receiving worker must append exactly one ledger row: ' . implode(' | ', $results));
        $this->assertNotEqualsWithDelta(end($before)['new'], end($after)['new'], 0.01);
        $this->assertLedgerIsAChain($after, 'control');
    }

    /** @test finalization versus receiving approval: serialized cost effects, correct average, complete ledger evidence */
    public function finalization_versus_receiving_approval_serializes_the_cost_effects(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->truncateAll();
                $this->setUpFinalizationFixtures();
            }
            [$b] = $this->mixed(null, 150.0);
            $note = $this->pendingReceiving();
            $baseline = count($this->ledger());
            $all = $this->ledger();
            $baselineAvg = end($all)['new'];

            $results = $this->race([['finalize', $b->id], ['http', $this->approveRoute($note)]], $round % 2 ? [250, 0] : [0, 250]);

            $ledger = $this->ledger();
            $new = array_slice($ledger, $baseline);
            $this->assertSame('FINALIZED', $b->fresh()->status, 'finalization must complete (a receiving into a standard location is compatible): ' . implode(' | ', $results));
            $this->assertSame('APPROVED', strtoupper((string) DB::table('received_notes')->where('id', $note->id)->value('status')), 'receiving must complete: ' . implode(' | ', $results));
            $this->assertCount(2, $new, 'exactly one ledger row per cost effect: ' . json_encode($new));
            $this->assertLedgerIsAChain($ledger, implode(' | ', $results));
            $this->assertEqualsWithDelta($baselineAvg, $new[0]['old'], 0.0051, 'the first writer started from the baseline average');

            $event = DB::table('consignment_buyout_cost_events')->where('consignment_buyout_id', $b->id)->first();
            $this->assertNotNull($event, 'complete cost evidence for the buyout');
            $mine = collect($new)->firstWhere('id', (int) $event->price_event_id);
            $this->assertNotNull($mine, 'the cost evidence points at the buyout\'s own ledger row');
            $this->assertEqualsWithDelta((float) $event->average_before, $mine['old'], 0.0051, 'the buyout replaced the average it really saw (not a stale read)');
            $this->assertEqualsWithDelta((float) $event->average_after, $mine['new'], 0.0051);

            // Reversal is rejected exactly when another cost event followed the acquisition.
            $receivingAfter = $new[1]['id'] === (int) $event->price_event_id ? false : true;
            $preview = app(ConsignmentBuyoutReversalService::class)->preview($b->fresh());
            $costBlocked = collect($preview['blockers'])->contains(fn ($x) => str_contains(json_encode($x), 'intervening'));
            $this->assertSame($receivingAfter, $costBlocked, 'reversal blocker must match the ledger order: ' . json_encode($preview['blockers']));
            $outcomes[] = $receivingAfter ? 'receiving-after-finalize' : 'finalize-after-receiving';
        }
        fwrite(STDERR, "\n[finalize-vs-receiving outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }

    /** @test reversal versus receiving approval: a reversal can never commit over an intervening cost event */
    public function reversal_versus_receiving_approval_rejects_reversal_after_an_intervening_cost_event(): void
    {
        $outcomes = [];
        for ($round = 0; $round < 6; $round++) {
            if ($round > 0) {
                $this->truncateAll();
                $this->setUpFinalizationFixtures();
            }
            [$b] = $this->mixed(null, 150.0);
            app(\Modules\Consignment\Services\ConsignmentBuyoutFinalizationService::class)->finalize($b, $this->user);
            $event = DB::table('consignment_buyout_cost_events')->where('consignment_buyout_id', $b->id)->first();
            $note = $this->pendingReceiving();
            $baseline = count($this->ledger());

            $results = $this->race([['reverse', $b->id], ['http', $this->approveRoute($note)]], $round % 2 ? [250, 0] : [0, 250]);

            $ledger = $this->ledger();
            $new = array_slice($ledger, $baseline);
            $reversed = strtoupper((string) $b->fresh()->status) === 'REVERSED';
            $received = strtoupper((string) DB::table('received_notes')->where('id', $note->id)->value('status')) === 'APPROVED';
            $this->assertTrue($received, 'the receiving always completes: ' . implode(' | ', $results));
            $this->assertLedgerIsAChain($ledger, implode(' | ', $results));
            if ($reversed) {
                // Reversal won: it must have run BEFORE the receiving, so the receiving row restarts from the restored average.
                $this->assertCount(2, $new, json_encode($new));
                $this->assertEqualsWithDelta((float) $event->average_before, $new[0]['new'], 0.0051, 'reversal restored the pre-acquisition average');
            } else {
                // Receiving won: the reversal was refused by the intervening ledger row and changed nothing.
                $this->assertCount(1, $new, 'a refused reversal writes no cost event: ' . json_encode($new));
                $this->assertStringContainsString('intervening', implode(' ', $results) . json_encode(app(ConsignmentBuyoutReversalService::class)->preview($b->fresh())['blockers']));
                $this->assertSame('FINALIZED', strtoupper((string) $b->fresh()->status));
            }
            $outcomes[] = $reversed ? 'reversal-won' : 'receiving-won';
        }
        fwrite(STDERR, "\n[reversal-vs-receiving outcomes] " . json_encode(array_count_values($outcomes)) . "\n");
    }
}

<?php

namespace Tests\Feature\SalesReturn;

use App\Support\SalesReturn\StandardReturnCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Tests\TestCase;

/** Final-review gaps: competing rows and unrecognized methods must never prove coverage. */
class CoverageEvidenceTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
    }

    private function receivedReturn(): array
    {
        $flow = $this->makeDispatchedSale(1, false);
        $return = $this->awaitingSettlement($flow, 1);

        return [$flow, $return];
    }

    /** @test */
    public function an_approved_cash_row_with_a_competing_open_repair_row_does_not_prove_coverage(): void
    {
        [$flow, $return] = $this->receivedReturn();
        $this->settlementFor($return, Settlement::STATUS_APPROVED, 'CASH_REFUND');

        $units = StandardReturnCoverage::saleCashUnits($flow['sale']->id);
        $this->assertSame(1.0, (float) $units['lines'][$flow['dispatchDetail']->id]);
        $this->assertSame(0, $units['pending']);

        $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'REPAIR');

        $units = StandardReturnCoverage::saleCashUnits($flow['sale']->id);
        $this->assertGreaterThan(0, $units['pending'], 'the competing repair row is a pending contributor that blocks archival');
        $this->assertFalse(StandardReturnCoverage::returnCompletion($return->fresh())['complete']);
    }

    /** @test */
    public function a_final_non_cash_row_is_not_cash_coverage(): void
    {
        [$flow, $return] = $this->receivedReturn();
        $this->settlementFor($return, Settlement::STATUS_DISPATCHED, 'REPAIR');

        $this->assertSame([], StandardReturnCoverage::saleCashUnits($flow['sale']->id)['lines']);
    }

    /** @test */
    public function unknown_or_missing_methods_are_never_final_and_stay_unresolved(): void
    {
        [$flow, $return] = $this->receivedReturn();
        $bogus = $this->settlementFor($return, Settlement::STATUS_APPROVED, 'MYSTERY');

        $this->assertFalse(StandardReturnCoverage::isFinal($bogus));
        $this->assertTrue(StandardReturnCoverage::isOpen($bogus));
        $this->assertFalse(StandardReturnCoverage::returnCompletion($return->fresh())['complete']);
        $this->assertSame([], StandardReturnCoverage::saleCashUnits($flow['sale']->id)['lines']);

        $none = $this->settlementFor($return, Settlement::STATUS_APPROVED, '');
        $this->assertFalse(StandardReturnCoverage::isFinal($none));

        foreach (['CASH_REFUND' => 'APPROVED', 'REPAIR' => 'DISPATCHED', 'UNPROCESSED' => 'DISPATCHED'] as $method => $status) {
            $this->assertTrue(StandardReturnCoverage::isFinal(new Settlement(['method' => $method, 'status' => $status])), $method);
        }
    }

    private function serial(array $flow, string $suffix): int
    {
        return \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $flow['product']->id, 'dispatch_detail_id' => $flow['dispatchDetail']->id,
            'serial_number' => 'SN-X-' . $suffix, 'location_id' => 1, 'status' => \Modules\Product\Entities\ProductSerialNumber::STATUS_SOLD,
        ])->id;
    }

    private function archivalOutcome(array $flow, $return): bool
    {
        $return->update(['status' => 'Completed']);
        app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)->archiveSourceSaleIfFullyReturnedAndCompleted($return->fresh(), 1);

        return ! empty(\Modules\Sale\Entities\Sale::withArchived()->find($flow['sale']->id)->archived_at);
    }

    /** @test */
    public function an_open_settlement_for_an_unclaimed_occurrence_blocks_coverage_and_archival(): void
    {
        [$flow, $return] = $this->receivedReturn();
        $this->settlementFor($return, Settlement::STATUS_APPROVED, 'CASH_REFUND');

        $sync = app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class);
        $this->assertTrue($sync->calculateEffectiveStandardReturnCoverage($flow['sale']->id)['fully_covered'], 'control: clean evidence covers the line');

        $this->settlementFor($return, Settlement::STATUS_SUBMITTED, 'REPAIR', $this->serial($flow, 'UNCLAIMED'));   // serial nobody claimed

        $coverage = $sync->calculateEffectiveStandardReturnCoverage($flow['sale']->id);
        $this->assertFalse($coverage['fully_covered']);
        $this->assertGreaterThan(0, $coverage['pending_contributors']);
        $this->assertFalse($this->archivalOutcome($flow, $return), 'the source sale stays unarchived');
    }

    /** @test */
    public function ambiguous_lineage_on_another_return_vetoes_full_coverage_and_archival(): void
    {
        [$flow, $return] = $this->receivedReturn();
        $this->settlementFor($return, Settlement::STATUS_APPROVED, 'CASH_REFUND');

        $sync = app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class);
        $this->assertTrue($sync->calculateEffectiveStandardReturnCoverage($flow['sale']->id)['fully_covered'], 'control');

        // a second received return whose serial evidence contradicts its quantity, carrying a final cash row
        $first = $this->serial($flow, 'A');
        $other = $this->awaitingSettlement($flow, 1, [$first, $this->serial($flow, 'B')]);
        $this->settlementFor($other, Settlement::STATUS_APPROVED, 'CASH_REFUND', $first);

        $coverage = $sync->calculateEffectiveStandardReturnCoverage($flow['sale']->id);
        $this->assertGreaterThan(0, $coverage['ambiguous_quantity']);
        $this->assertFalse($coverage['fully_covered']);
        $this->assertFalse($this->archivalOutcome($flow, $return), 'the source sale stays unarchived');
    }
}

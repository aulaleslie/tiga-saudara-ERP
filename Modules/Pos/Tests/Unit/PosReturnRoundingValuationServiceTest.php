<?php

namespace Modules\Pos\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosReturnRoundingValuationService;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosReturnRoundingValuationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PosReturnRoundingValuationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PosReturnRoundingValuationService();
    }

    /** @test */
    public function it_calculates_marker_baseline_rp790000_for_120_markers_at_rp100_increment(): void
    {
        // 1 of 120 markers charged at Rp790000 with increment Rp100
        $step1 = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 1.0,
            previouslyCommittedAmount: 0.0,
            increment: 100.0,
            storedResidual: 790000.0
        );

        $this->assertEquals(6600.0, $step1['target_cumulative_amount']);
        $this->assertEquals(6600.0, $step1['current_return_amount']);
        $this->assertEquals(783400.0, $step1['remaining_commercial_value']);
        $this->assertFalse($step1['is_exhaustion']);

        // Step 2: 2nd marker (cumulative q=2, prev H=6600)
        $step2 = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 2.0,
            previouslyCommittedAmount: 6600.0,
            increment: 100.0,
            storedResidual: 783400.0
        );

        $this->assertEquals(13200.0, $step2['target_cumulative_amount']);
        $this->assertEquals(6600.0, $step2['current_return_amount']);
        $this->assertEquals(776800.0, $step2['remaining_commercial_value']);

        // Step 3: 3rd marker (cumulative q=3, prev H=13200)
        $step3 = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 3.0,
            previouslyCommittedAmount: 13200.0,
            increment: 100.0,
            storedResidual: 776800.0
        );

        $this->assertEquals(19800.0, $step3['target_cumulative_amount']);
        $this->assertEquals(6600.0, $step3['current_return_amount']);
        $this->assertEquals(770200.0, $step3['remaining_commercial_value']);
    }

    /** @test */
    public function it_calculates_fourth_unit_rp6500_delta_due_to_half_up_cumulative_boundary(): void
    {
        // 4th marker: cumulative q=4 gives cumulative target 26300; delta from H=19800 is 6500
        $step4 = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 4.0,
            previouslyCommittedAmount: 19800.0,
            increment: 100.0,
            storedResidual: 770200.0
        );

        $this->assertEquals(26300.0, $step4['target_cumulative_amount']);
        $this->assertEquals(6500.0, $step4['current_return_amount']);
        $this->assertEquals(763700.0, $step4['remaining_commercial_value']);
    }

    /** @test */
    public function it_demonstrates_partition_independence_for_multi_unit_requests(): void
    {
        // Direct single request for 2 units
        $twoUnitsSingle = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 2.0,
            previouslyCommittedAmount: 0.0,
            increment: 100.0,
            storedResidual: 790000.0
        );

        $this->assertEquals(13200.0, $twoUnitsSingle['target_cumulative_amount']);
        $this->assertEquals(13200.0, $twoUnitsSingle['current_return_amount']);

        // Direct single request for 4 units
        $fourUnitsSingle = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 4.0,
            previouslyCommittedAmount: 0.0,
            increment: 100.0,
            storedResidual: 790000.0
        );

        $this->assertEquals(26300.0, $fourUnitsSingle['target_cumulative_amount']);
        $this->assertEquals(26300.0, $fourUnitsSingle['current_return_amount']);

        // Sum of 3 units (19800) + 4th unit (6500) equals direct 4 units (26300)
        $this->assertEquals(26300.0, 19800.0 + 6500.0);
    }

    /** @test */
    public function zero_increment_retains_two_decimal_currency_precision(): void
    {
        // Zero increment: 790000 / 120 = 6583.3333... -> 6583.33
        $step1 = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 1.0,
            previouslyCommittedAmount: 0.0,
            increment: 0.0,
            storedResidual: 790000.0
        );

        $this->assertEquals(6583.33, $step1['target_cumulative_amount']);
        $this->assertEquals(6583.33, $step1['current_return_amount']);

        // Step 2: 2 * 790000 / 120 = 13166.6666... -> 13166.67
        $step2 = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 2.0,
            previouslyCommittedAmount: 6583.33,
            increment: 0.0,
            storedResidual: 790000.0 - 6583.33
        );

        $this->assertEquals(13166.67, $step2['target_cumulative_amount']);
        $this->assertEquals(6583.34, $step2['current_return_amount']);
    }

    /** @test */
    public function coarse_increment_permits_zero_monetary_delta_with_valid_physical_quantity(): void
    {
        // Increment Rp10000:
        // q=1: 6583.33 rounds to 10000
        $step1 = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 1.0,
            previouslyCommittedAmount: 0.0,
            increment: 10000.0,
            storedResidual: 790000.0
        );

        $this->assertEquals(10000.0, $step1['target_cumulative_amount']);
        $this->assertEquals(10000.0, $step1['current_return_amount']);

        // q=2: 13166.67 rounds to 10000. Target remains 10000, so delta is 0!
        $step2 = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 2.0,
            previouslyCommittedAmount: 10000.0,
            increment: 10000.0,
            storedResidual: 780000.0
        );

        $this->assertEquals(10000.0, $step2['target_cumulative_amount']);
        $this->assertEquals(0.0, $step2['current_return_amount']);
        $this->assertEquals(780000.0, $step2['remaining_commercial_value']);
    }

    /** @test */
    public function final_exhaustion_consumes_exact_stored_residual_including_legacy_discrepancies(): void
    {
        // 119 of 120 markers were previously returned for 783400.40 (legacy Rp0.40 discrepancy)
        // Stored residual is 6599.60 (790000 - 783400.40)
        $exhaustion = $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 120.0,
            previouslyCommittedAmount: 783400.40,
            increment: 100.0,
            storedResidual: 6599.60
        );

        $this->assertTrue($exhaustion['is_exhaustion']);
        $this->assertEquals(6599.60, $exhaustion['current_return_amount']);
        $this->assertEquals(790000.0, $exhaustion['target_cumulative_amount']);
        $this->assertEquals(0.0, $exhaustion['remaining_commercial_value']);
    }

    /** @test */
    public function it_blocks_ambiguous_evidence_and_invalid_arguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->calculateCumulativeValuation(
            baselineAmount: 0.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 1.0,
            previouslyCommittedAmount: 0.0,
            increment: 100.0,
            storedResidual: 0.0
        );
    }

    /** @test */
    public function it_rejects_negative_residual_evidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->calculateCumulativeValuation(
            baselineAmount: 790000.0,
            baselineQuantity: 120.0,
            cumulativeReturnedQuantity: 1.0,
            previouslyCommittedAmount: 0.0,
            increment: 100.0,
            storedResidual: -100.0
        );
    }

    /** @test */
    public function it_preserves_pos_business_isolation_and_freezes_increment_on_commit(): void
    {
        $posSetting = Setting::factory()->create([
            'company_name' => 'POS Business',
            'row_total_rounding_increment' => 500.0,
        ]);

        $ownerSetting = Setting::factory()->create([
            'company_name' => 'Owner Business',
            'row_total_rounding_increment' => 50.0,
        ]);

        $customer = Customer::factory()->create();

        $user = User::factory()->create();

        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $posSetting->id,
            'name' => 'Terminal 1',
            'code' => 'T1',
            'is_active' => true,
        ]);

        $session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $posSetting->id,
            'pos_terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'opened_at' => now(),
            'status' => \Modules\Pos\Entities\PosSession::STATUS_OPEN,
            'active_marker' => 1,
            'expected_cash_total' => 100000,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $posSetting->id,
            'code' => 'TRX-TEST-001',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $user->id,
            'owner_user_id' => $user->id,
            'last_saved_by' => $user->id,
            'customer_id' => $customer->id,
            'source_pos_session_id' => $session->id,
        ]);

        $sale = Sale::create([
            'setting_id' => $ownerSetting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'date' => now()->toDateString(),
            'total_amount' => 790000,
            'paid_amount' => 790000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        // Provisional increment from POS transaction business setting is 500, NOT the fragment owner's 50
        $effectiveIncrement = $this->service->resolveEffectiveIncrement($sale, $transaction);
        $this->assertEquals(500.0, $effectiveIncrement);

        // Preview is non-mutating: sale context remains null
        $this->assertNull($sale->fresh()->return_rounding_context);

        // First commit freezes policy
        $baselineInfo = [
            'commercial_line_id' => 10,
            'baseline_quantity' => 120.0,
            'baseline_returnable_amount' => 790000.0,
        ];

        $committedContext = $this->service->commitLineAllocation(
            sale: $sale,
            posTransaction: $transaction,
            commercialLineKey: 'ptl_10',
            baselineInfo: $baselineInfo,
            committedDeltaQty: 1.0,
            committedDeltaAmount: 6600.0,
            allocationReference: 'POSR-001',
            actorId: 1
        );

        $this->assertIsArray($committedContext);
        $this->assertEquals(500.0, $committedContext['frozen_increment']);
        $this->assertEquals($posSetting->id, $committedContext['pos_business_id']);

        // Subsequent setting change on the business does NOT affect the frozen sale context
        $posSetting->update(['row_total_rounding_increment' => 1000.0]);
        $saleReloaded = $sale->fresh();
        $this->assertEquals(500.0, $this->service->resolveEffectiveIncrement($saleReloaded, $transaction));
    }

    /** @test */
    public function it_allocates_commercial_amount_across_fragments_deterministically_without_increment_rounding(): void
    {
        $fragments = [
            'owner_1' => ['basis' => 100.0],
            'owner_2' => ['basis' => 200.0],
        ];

        // Total commercial return Rp13200 (which was rounded once at the commercial boundary)
        $allocated = $this->service->allocateCommercialAmountAcrossFragments(13200.0, $fragments);

        $this->assertEquals(4400.0, $allocated['owner_1']);
        $this->assertEquals(8800.0, $allocated['owner_2']);
        $this->assertEquals(13200.0, $allocated['owner_1'] + $allocated['owner_2']);

        // 3 fragments dividing Rp100: remainder is distributed by cent without independent increment rounding
        $threeFragments = [
            'f1' => ['basis' => 10.0],
            'f2' => ['basis' => 10.0],
            'f3' => ['basis' => 10.0],
        ];

        $alloc3 = $this->service->allocateCommercialAmountAcrossFragments(100.0, $threeFragments);
        $this->assertEquals(33.34, $alloc3['f1']);
        $this->assertEquals(33.33, $alloc3['f2']);
        $this->assertEquals(33.33, $alloc3['f3']);
        $this->assertEquals(100.0, array_sum($alloc3));
    }
}

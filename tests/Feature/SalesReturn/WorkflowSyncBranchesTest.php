<?php

namespace Tests\Feature\SalesReturn;

use App\Support\SalesReturn\SaleReturnLifecycleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Tests\TestCase;

class WorkflowSyncBranchesTest extends TestCase
{
    use RefreshDatabase, BuildsWorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableForeignKeys();
    }

    private function dispatchedSale(int $qty = 2): Sale
    {
        $sale = $this->makeSale();
        // The coverage denominator only counts APPROVED dispatches.
        DB::table('dispatches')->insert(['id' => 1, 'sale_id' => $sale->id, 'dispatch_date' => now()->toDateString(), 'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dispatch_details')->insert([
            'dispatch_id' => 1, 'sale_id' => $sale->id, 'product_id' => 1, 'dispatched_quantity' => $qty,
            'location_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $sale;
    }

    private function receivedDetail(int $returnId, int $qty): void
    {
        DB::table('sale_return_details')->insert([
            'sale_return_id' => $returnId, 'product_id' => 1, 'product_name' => 'P', 'product_code' => 'P',
            'dispatch_detail_id' => DB::table('dispatch_details')->value('id'),
            'quantity' => $qty, 'price' => 100, 'unit_price' => 100, 'sub_total' => 100 * $qty,
            'product_discount_amount' => 0, 'product_tax_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test */
    public function standard_branch_still_derives_status_and_archives_by_coverage(): void
    {
        $sale = $this->dispatchedSale(2);
        $return = $this->makeReturn($sale, ['status' => 'COMPLETED', 'approval_status' => 'approved', 'received_at' => now()]);
        $this->receivedDetail($return->id, 2);

        $service = app(SaleReturnLifecycleSyncService::class);

        // Received but unsettled quantities prove nothing: the sale stays as it was.
        $service->syncSourceSaleReturnStatusFromReceivedReturns($return);
        $this->assertSame(Sale::STATUS_DISPATCHED, $sale->fresh()->status);

        // FINAL cash-return coverage (an APPROVED cash settlement) -> partial state, then archival -> RETURNED.
        DB::table('sale_return_item_settlements')->insert([
            'sale_return_id' => $return->id, 'sale_return_detail_id' => DB::table('sale_return_details')->value('id'),
            'method' => 'CASH_REFUND', 'status' => 'APPROVED', 'nominal' => 200, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service->syncSourceSaleReturnStatusFromReceivedReturns($return);
        $this->assertSame(Sale::STATUS_RETURNED_PARTIALLY, $sale->fresh()->status);

        $service->archiveSourceSaleIfFullyReturnedAndCompleted($return->fresh(), 1);
        $this->assertSame(Sale::STATUS_RETURNED, $sale->fresh()->status);
        $this->assertNotNull(Sale::withArchived()->find($sale->id)->archived_at);
    }

    /** @test */
    public function pos_return_completes_and_archives_from_active_quantities_without_settlement_rows(): void
    {
        $sale = $this->makeSale();
        $this->linkSplitCheckoutSale($sale);
        // No active quantities remain (POS execution reduced them) and no dispatch rows.
        $return = $this->makeReturn($sale, ['pos_return_id' => 3, 'status' => 'COMPLETED']);

        $service = app(SaleReturnLifecycleSyncService::class);
        $service->syncSaleReturnCompletionRollup($return, 1);   // POS branch: no settlement rows needed
        $service->syncSourceSaleReturnStatusFromReceivedReturns($return);
        $service->archiveSourceSaleIfFullyReturnedAndCompleted($return->fresh(), 1);

        $this->assertSame(0, DB::table('sale_return_item_settlements')->count());
        $this->assertSame(Sale::STATUS_RETURNED, Sale::withArchived()->find($sale->id)->status);
        $this->assertNotNull(Sale::withArchived()->find($sale->id)->archived_at);
    }

    /** @test paired with pos_return_completes_and_archives_from_active_quantities_without_settlement_rows */
    public function the_same_received_quantities_without_final_settlements_never_complete_or_archive_a_standard_return(): void
    {
        $sale = $this->dispatchedSale(2);
        $return = $this->makeReturn($sale, ['status' => 'AWAITING SETTLEMENT', 'approval_status' => 'approved', 'received_at' => now()]);
        $this->receivedDetail($return->id, 2);   // fully received, but no settlement rows at all

        $service = app(SaleReturnLifecycleSyncService::class);
        $service->syncSaleReturnCompletionRollup($return->fresh(), 1);
        $service->syncSourceSaleReturnStatusFromReceivedReturns($return->fresh());
        $service->archiveSourceSaleIfFullyReturnedAndCompleted($return->fresh(), 1);

        $this->assertSame('AWAITING SETTLEMENT', strtoupper($return->fresh()->status), 'received quantities alone never complete a standard return');
        $this->assertSame(Sale::STATUS_DISPATCHED, Sale::withArchived()->find($sale->id)->status);
        $this->assertNull(Sale::withArchived()->find($sale->id)->archived_at);
    }

    /** @test */
    public function standard_sync_on_a_mixed_history_sale_changes_nothing(): void
    {
        $sale = $this->dispatchedSale(2);
        $this->linkSplitCheckoutSale($sale);                       // POS-origin
        $return = $this->makeReturn($sale, ['status' => 'COMPLETED']); // standard record => mixed
        $this->receivedDetail($return->id, 2);                     // would fully cover under standard rules

        $before = [Sale::withArchived()->find($sale->id)->toArray(), $return->fresh()->toArray()];

        $service = app(SaleReturnLifecycleSyncService::class);
        $service->syncSourceSaleReturnStatusFromReceivedReturns($return);
        $service->archiveSourceSaleIfFullyReturnedAndCompleted($return->fresh(), 1);

        $this->assertSame($before, [Sale::withArchived()->find($sale->id)->toArray(), $return->fresh()->toArray()]);
    }

    /** @test */
    public function mixed_history_with_a_pos_linked_return_changes_no_source_state_through_any_entry_point(): void
    {
        $sale = $this->makeSale();                                  // DISPATCHED, no active qty rows => would read as fully returned
        $this->linkSplitCheckoutSale($sale);
        $standard = $this->makeReturn($sale, ['status' => 'COMPLETED']);                          // historical standard
        $pos = $this->makeReturn($sale, ['pos_return_id' => 9, 'status' => 'COMPLETED']);         // POS-linked

        $before = [Sale::withArchived()->find($sale->id)->toArray(), $standard->fresh()->toArray(), $pos->fresh()->toArray()];

        $service = app(SaleReturnLifecycleSyncService::class);
        foreach ([$standard, $pos] as $return) {
            $service->syncSourceSaleReturnStatusFromReceivedReturns($return);
            $service->syncSaleReturnCompletionRollup($return, 1);
            $service->archiveSourceSaleIfFullyReturnedAndCompleted($return->fresh(), 1);
        }

        $this->assertSame(Sale::STATUS_DISPATCHED, Sale::withArchived()->find($sale->id)->status);
        $this->assertSame($before, [Sale::withArchived()->find($sale->id)->toArray(), $standard->fresh()->toArray(), $pos->fresh()->toArray()]);
    }
}

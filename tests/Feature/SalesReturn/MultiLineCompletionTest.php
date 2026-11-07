<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Support\SalesReturn\StandardReturnCoverage;
use App\Support\SalesReturn\StandardReturnCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnAdjustment;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Tests\TestCase;

/** Group 7: completion and archival coverage for multi-line standard returns, end to end. */
class MultiLineCompletionTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow, BuildsFinancialFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn ($user, $ability) => $ability === 'saleReturns.inspect' ? null : true);
        $this->actingAs(User::factory()->create());
        $this->app->instance(DocumentNotificationService::class, \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing());
    }

    private function http(): self
    {
        return $this->withSession(['setting_id' => 1]);
    }

    private function approve(Settlement $row): void
    {
        $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertRedirect();
    }

    /** One return carrying every serial of every line of the sale, received (held custody). */
    private function receivedReturnOfEverything(array $s): SaleReturn
    {
        $first = $s['lines'][0];
        $total = 0;
        foreach ($s['lines'] as $l) {
            $total += (float) $l['saleDetail']->sub_total;
        }
        $return = $this->makeReturnFor($first, count($first['serials']), [
            'approval_status' => 'approved', 'status' => 'Awaiting Receiving', 'total_amount' => $total, 'due_amount' => $total,
        ], collect($first['serials'])->pluck('id')->all(), (float) $first['saleDetail']->sub_total / count($first['serials']));

        foreach (array_slice($s['lines'], 1) as $l) {
            SaleReturnDetail::create([
                'sale_return_id' => $return->id, 'sale_detail_id' => $l['saleDetail']->id, 'dispatch_detail_id' => $l['dispatchDetail']->id,
                'location_id' => 1, 'product_id' => $l['product']->id, 'product_name' => $l['product']->product_name,
                'product_code' => $l['product']->product_code, 'quantity' => count($l['serials']),
                'unit_price' => (float) $l['saleDetail']->sub_total / count($l['serials']), 'price' => (float) $l['saleDetail']->sub_total / count($l['serials']),
                'sub_total' => (float) $l['saleDetail']->sub_total, 'product_discount_amount' => 0, 'product_tax_amount' => 0,
                'serial_number_ids' => collect($l['serials'])->pluck('id')->all(),
            ]);
        }

        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();

        return $return->fresh();
    }

    private function settleSerial(SaleReturn $return, int $lineIndex, int $serialIndex, string $method, array $s, string $status = Settlement::STATUS_SUBMITTED): Settlement
    {
        $serialId = $s['lines'][$lineIndex]['serials'][$serialIndex]->id;
        $detailId = SaleReturnDetail::query()->where('sale_return_id', $return->id)->where('dispatch_detail_id', $s['lines'][$lineIndex]['dispatchDetail']->id)->value('id');
        $row = Settlement::create([
            'sale_return_id' => $return->id, 'sale_return_detail_id' => $detailId,
            'product_serial_number_id' => $serialId, 'method' => $method, 'nominal' => 1000, 'status' => $status,
        ]);
        if ($method === 'CASH_REFUND' && $status === Settlement::STATUS_SUBMITTED) {
            $derived = app(\App\Support\SalesReturn\StandardFinancialService::class)->resolve($row->fresh());
            $row->forceFill(['nominal' => $derived['value'], 'reviewed_value' => $derived['value'], 'reviewed_fingerprint' => $derived['fingerprint']])->save();
        }

        return $row;
    }

    private function reportSnapshot(): array
    {
        $sheet = app(\App\Services\Reports\OperationalBalanceSheetReportService::class)->generate(1, now()->toDateString());
        $events = collect(app(\App\Services\Reports\OperationalMovementEventService::class)->getMovementEvents(1, now()->toDateString()));
        $cash = app(\App\Services\Reports\OperationalCashFlowReportService::class)->generate(1, new \App\Services\Reports\OperationalCashFlowReportFilterData(now()->subDay()->toDateString(), now()->addDay()->toDateString()));
        $day = now()->addDay()->toDateString();   // SQLite compares stored datetimes as text

        return [
            'receivable' => collect($sheet->assets->rows)->firstWhere('name', 'Piutang Usaha')->amount,
            'returned_value' => (float) $events->where('sourceType', 'Retur Penjualan')->where('bucket', 'returns_and_adjustments')->sum('debit'),
            'refund_cash' => (float) $events->where('sourceType', 'Pengembalian Dana')->where('bucket', 'cash_bank')->sum('credit'),
            'cash_flow_refund' => collect($cash->operatingActivities->rows)->firstWhere('name', 'Kartu kredit dan liabilitas jangka pendek lainnya')->amount,
            'customer_receivable' => (float) (new \App\Services\Reports\CustomerReceivablesReportQueryService())
                ->build(new \App\Services\Reports\CustomerReceivablesReportFilterData($day, 1))->get()->sum('sisa_piutang'),
        ];
    }

    // ------------------------------------------------------------------ end to end

    /** @test */
    public function multi_line_return_cash_pending_repair_final_dispatch_and_stable_reports(): void
    {
        // Sale: line 1 (2 serials, Rp2,000) and line 2 (2 serials, Rp2,000), paid Rp1,000 of Rp4,000.
        $s = $this->sale([['qty' => 2, 'sub' => 2000], ['qty' => 2, 'sub' => 2000]], paid: 1000, serial: true);
        $saleBefore = $s['sale']->fresh()->only(['total_amount', 'paid_amount', 'due_amount']);
        $return = $this->receivedReturnOfEverything($s);

        // 1. received: custody held for all four serials; the source sale is untouched
        $this->assertSame(4, \Modules\SalesReturn\Entities\SaleReturnCustody::query()->where('sale_return_id', $return->id)->count());
        $this->assertSame(Sale::STATUS_DISPATCHED, $s['sale']->fresh()->status);
        $this->assertSame('AWAITING SETTLEMENT', strtoupper($return->status));

        // 2. first cash approval: the multi-line return stays processable
        $a1 = $this->settleSerial($return, 0, 0, 'CASH_REFUND', $s);
        $this->approve($a1);
        $this->assertSame(Settlement::STATUS_APPROVED, $a1->fresh()->status);
        $this->assertSame('AWAITING SETTLEMENT', strtoupper($return->fresh()->status), 'one approved item must not complete the return');
        $this->assertSame(Sale::STATUS_RETURNED_PARTIALLY, $s['sale']->fresh()->status);

        // 3. remaining cash items + line 2 cash item; one serial (line 2 / serial 2) has NO settlement yet
        foreach ([[0, 1], [1, 0]] as [$l, $i]) {
            $this->approve($this->settleSerial($return->fresh(), $l, $i, 'CASH_REFUND', $s));
        }
        $this->assertSame('AWAITING SETTLEMENT', strtoupper($return->fresh()->status), 'a missing settlement prevents completion');
        $this->assertFalse(StandardReturnCoverage::returnCompletion($return->fresh())['complete']);
        $beforeCompletion = $this->reportSnapshot();

        // 4. the last serial goes to repair: submitted -> approved awaiting dispatch -> NOT complete yet
        $repair = $this->settleSerial($return->fresh(), 1, 1, 'REPAIR', $s);
        $this->approve($repair);
        $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $repair->fresh()->status);
        $this->assertSame('AWAITING SETTLEMENT', strtoupper($return->fresh()->status));

        // 5. request + approve the original-goods dispatch: now every occurrence is final
        $serial = $s['lines'][1]['serials'][1];
        $this->http()->post(route('sale-return-settlements.item.dispatch', $repair), ['dispatched_serial_number' => $serial->serial_number])->assertRedirect();
        $this->http()->post(route('sale-return-settlements.item.dispatch.approve', $repair))->assertRedirect();
        $this->assertSame(Settlement::STATUS_DISPATCHED, $repair->fresh()->status);
        $this->assertSame('COMPLETED', strtoupper($return->fresh()->status));
        $this->assertTrue(StandardReturnCoverage::returnCompletion($return->fresh())['complete']);

        // 6. cash coverage of line 2 is short (one unit went to repair): the sale is NOT archived
        $sale = Sale::withArchived()->find($s['sale']->id);
        $this->assertNull($sale->archived_at);
        $this->assertSame(Sale::STATUS_RETURNED_PARTIALLY, $sale->status);

        // 7. already committed report effects are identical before and after completion (repair adds no money)
        $this->assertEquals($beforeCompletion, $this->reportSnapshot());
        $this->assertSame(3, SaleReturnAdjustment::query()->count());
        $this->assertEquals($saleBefore['total_amount'], $sale->total_amount, 'gross source evidence untouched');

        // 8. custody stays visible after financial completion: three held (cash), one dispatched (repair)
        $summary = app(StandardReturnCustodyService::class)->summary($return->fresh());
        $this->assertEquals(3, $summary['held']);
        $this->assertEquals(1, $summary['dispatched']);
    }

    /** @test */
    public function cumulative_final_cash_coverage_archives_once_and_a_pending_contributor_blocks_it(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 2000]], paid: 2000, serial: true);
        $line = $s['lines'][0];
        $ids = collect($line['serials'])->pluck('id')->all();

        // two separate returns of one unit each
        $r1 = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [$ids[0]], 1000);
        $r2 = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [$ids[1]], 1000);
        foreach ([$r1, $r2] as $r) {
            $this->http()->post(route('sale-returns.receive', $r))->assertRedirect();
        }
        $pair = fn ($r, $i) => (function () use ($r, $i, $s) {
            $detailId = SaleReturnDetail::query()->where('sale_return_id', $r->id)->value('id');
            $row = Settlement::create(['sale_return_id' => $r->id, 'sale_return_detail_id' => $detailId, 'product_serial_number_id' => $s['lines'][0]['serials'][$i]->id, 'method' => 'CASH_REFUND', 'nominal' => 1000, 'status' => Settlement::STATUS_SUBMITTED]);
            $d = app(\App\Support\SalesReturn\StandardFinancialService::class)->resolve($row->fresh());
            $row->forceFill(['nominal' => $d['value'], 'reviewed_value' => $d['value'], 'reviewed_fingerprint' => $d['fingerprint']])->save();

            return $row;
        })();

        $row1 = $pair($r1->fresh(), 0);
        $this->approve($row1);
        $this->assertSame('COMPLETED', strtoupper($r1->fresh()->status));
        $this->assertNull(Sale::withArchived()->find($s['sale']->id)->archived_at, 'one unit of two is not full coverage');
        $this->assertSame(Sale::STATUS_RETURNED_PARTIALLY, Sale::withArchived()->find($s['sale']->id)->status);

        // the second return's settlement is still submitted: pending contributor, no archival
        $row2 = $pair($r2->fresh(), 1);
        $this->assertFalse(app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)->calculateEffectiveStandardReturnCoverage($s['sale']->id)['fully_covered']);

        $this->approve($row2);
        $archived = Sale::withArchived()->find($s['sale']->id);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame(Sale::STATUS_RETURNED, $archived->status);

        // retry is idempotent: archived exactly once
        $stamp = $archived->archived_at->toDateTimeString();
        $this->approve($row2);
        app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)->archiveSourceSaleIfFullyReturnedAndCompleted($r2->fresh(), 1);
        $again = Sale::withArchived()->find($s['sale']->id);
        $this->assertSame($stamp, $again->archived_at->toDateTimeString());
        $this->assertSame(1, substr_count((string) $again->note, 'Sales Return ' . $r2->fresh()->reference));
    }

    /** @test */
    public function a_debt_only_full_return_archives_with_no_cash_payout(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 0, serial: true);
        $return = $this->receivedReturnOfEverything($s);
        $this->approve($this->settleSerial($return, 0, 0, 'CASH_REFUND', $s));

        $sale = Sale::withArchived()->find($s['sale']->id);
        $this->assertNotNull($sale->archived_at);
        $this->assertSame(Sale::STATUS_RETURNED, $sale->status);
        $this->assertSame(0, \Modules\SalesReturn\Entities\SaleReturnPayment::query()->count());
        $this->assertEquals(0, $sale->due_amount);
    }

    /** @test */
    public function repair_only_returns_complete_without_archiving_or_touching_source_finances(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 400, serial: true);
        $before = $s['sale']->fresh()->only(['status', 'total_amount', 'paid_amount', 'due_amount', 'payment_status', 'archived_at']);
        $return = $this->receivedReturnOfEverything($s);

        $row = $this->settleSerial($return, 0, 0, 'REPAIR', $s);
        $this->approve($row);
        $this->http()->post(route('sale-return-settlements.item.dispatch', $row), ['dispatched_serial_number' => $s['lines'][0]['serials'][0]->serial_number])->assertRedirect();
        $this->http()->post(route('sale-return-settlements.item.dispatch.approve', $row))->assertRedirect();

        $this->assertSame('COMPLETED', strtoupper($return->fresh()->status));
        $this->assertSame($before, Sale::withArchived()->find($s['sale']->id)->only(['status', 'total_amount', 'paid_amount', 'due_amount', 'payment_status', 'archived_at']));
        $this->assertSame(0, SaleReturnAdjustment::query()->count());
    }

    /** @test */
    public function only_final_settlements_count_toward_return_completion(): void
    {
        $s = $this->sale([['qty' => 3, 'sub' => 3000]], paid: 0, serial: true);
        $return = $this->receivedReturnOfEverything($s);

        foreach ([Settlement::STATUS_DRAFT, Settlement::STATUS_SUBMITTED, Settlement::STATUS_REJECTED, Settlement::STATUS_APPROVED_AWAITING_DISPATCH, Settlement::STATUS_DISPATCH_REQUESTED] as $blocking) {
            Settlement::query()->where('sale_return_id', $return->id)->delete();
            $this->settleSerial($return, 0, 0, 'CASH_REFUND', $s, Settlement::STATUS_APPROVED);
            $this->settleSerial($return, 0, 1, 'CASH_REFUND', $s, Settlement::STATUS_APPROVED);
            $this->settleSerial($return, 0, 2, 'REPAIR', $s, $blocking);

            $result = StandardReturnCoverage::returnCompletion($return->fresh());
            $this->assertFalse($result['complete'], "{$blocking} must block completion");
            $this->assertSame(2, $result['final']);
            $this->assertSame(3, $result['expected']);
        }

        Settlement::query()->where('sale_return_id', $return->id)->where('product_serial_number_id', $s['lines'][0]['serials'][2]->id)->update(['status' => Settlement::STATUS_DISPATCHED]);
        $this->assertTrue(StandardReturnCoverage::returnCompletion($return->fresh())['complete']);
    }
}

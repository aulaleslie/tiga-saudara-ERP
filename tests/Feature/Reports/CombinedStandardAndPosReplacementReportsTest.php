<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\OperationalBalanceSheetReportService;
use App\Services\Reports\OperationalCashFlowReportFilterData;
use App\Services\Reports\OperationalCashFlowReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Integration checkpoint: ordinary standard returns AND a cross-owner POS replacement in one fixture,
 * reconciled per business and combined, at several as-of cutoffs.
 *
 * Timeline: D1 2026-01-10 payments (incl. the payment later moved between owners),
 *           D2 2026-02-01 replacement sale B, D3 2026-03-01 standard cash refund + debt reduction.
 * Established convention (verified here, not changed): owner transfers are dated by the backing payment (D1);
 * standard adjustments and linked payouts by their own commit/payout date (D3).
 */
class CombinedStandardAndPosReplacementReportsTest extends TestCase
{
    use RefreshDatabase;

    private const D1 = '2026-01-10';
    private const D2 = '2026-02-01';
    private const D3 = '2026-03-01';

    private Setting $a;
    private Setting $b;
    private \Modules\People\Entities\Customer $customerA;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $currency = Currency::factory()->create(['code' => 'IDR']);
        $this->a = Setting::factory()->create(['company_name' => 'Owner A', 'default_currency_id' => $currency->id]);
        $this->b = Setting::factory()->create(['company_name' => 'Owner B', 'default_currency_id' => $currency->id]);
        $this->seedFixture();
    }

    private function sale(Setting $s, string $ref, float $total, string $date, array $extra = []): Sale
    {
        return Sale::forceCreate(array_merge([
            'setting_id' => $s->id, 'status' => Sale::STATUS_DISPATCHED, 'total_amount' => $total, 'paid_amount' => 0,
            'due_amount' => $total, 'payment_status' => 'Unpaid', 'payment_method' => 'Cash', 'date' => $date,
            'reference' => $ref, 'customer_name' => 'C', 'tax_amount' => 0,
        ], $extra));
    }

    private function pay(Sale $sale, float $amount, string $date): SalePayment
    {
        return SalePayment::forceCreate(['sale_id' => $sale->id, 'amount' => $amount, 'date' => $date,
            'reference' => 'P-' . uniqid(), 'payment_method' => 'Cash', 'status' => 'ACTIVE']);
    }

    private function adjustment(Sale $sale, int $returnId, float $value, float $debt, float $refund, string $at, ?int $paymentId = null): void
    {
        DB::table('sale_return_adjustments')->insert([
            'sale_return_item_settlement_id' => $returnId * 10, 'sale_return_id' => $returnId, 'sale_id' => $sale->id,
            'sale_return_detail_id' => 1, 'quantity' => 1, 'committed_value' => $value, 'debt_reduction' => $debt,
            'cash_refund' => $refund, 'source_fingerprint' => str_repeat('a', 64), 'policy_version' => 1,
            'sale_return_payment_id' => $paymentId, 'committed_at' => $at . ' 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedFixture(): void
    {
        // Ordinary standard returns (owner A)
        $s1 = $this->sale($this->a, 'S1', 1000, self::D1);
        $this->pay($s1, 1000, self::D1);
        $r1 = SaleReturn::forceCreate(['setting_id' => $this->a->id, 'sale_id' => $s1->id, 'customer_id' => 1, 'customer_name' => 'C',
            'date' => self::D3, 'reference' => 'SR-1', 'total_amount' => 200, 'paid_amount' => 200, 'due_amount' => 0,
            'status' => 'Approved', 'approval_status' => 'APPROVED', 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'return_type' => 'Cash Return',
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0]);
        $payout = SaleReturnPayment::forceCreate(['sale_return_id' => $r1->id, 'amount' => 200, 'date' => self::D3,
            'reference' => 'RP-1', 'payment_method' => 'Cash', 'sale_return_item_settlement_id' => $r1->id * 10]);
        $this->adjustment($s1, $r1->id, 200, 0, 200, self::D3, $payout->id);

        $this->customerA = \Modules\People\Entities\Customer::factory()->create();
        $s2 = $this->sale($this->a, 'S2', 500, self::D1, ['customer_id' => $this->customerA->id]);
        $r2 = SaleReturn::forceCreate(array_merge($r1->only(['setting_id', 'customer_id', 'customer_name', 'status', 'approval_status', 'payment_status', 'payment_method', 'return_type',
            'tax_percentage', 'tax_amount', 'discount_percentage', 'discount_amount', 'shipping_amount']), [
            'sale_id' => $s2->id, 'date' => self::D3, 'reference' => 'SR-2', 'total_amount' => 100, 'paid_amount' => 0, 'due_amount' => 0]));
        $this->adjustment($s2, $r2->id, 100, 100, 0, self::D3);

        // POS cross-owner replacement: A's source sale (now 200) paid 600 at D1, 400 of it moved to B's replacement sale
        $src = $this->sale($this->a, 'SP', 200, self::D1);
        $srcPay = $this->pay($src, 600, self::D1);
        $repl = $this->sale($this->b, 'SB', 400, self::D2);
        DB::table('pos_owner_payment_transfers')->insert([
            'execution_key' => 'K-1', 'pos_return_id' => 1, 'sale_return_detail_id' => 1, 'source_sale_id' => $src->id,
            'replacement_sale_id' => $repl->id, 'original_sale_payment_id' => $srcPay->id, 'payment_method' => 'Cash',
            'amount' => 400, 'amount_minor' => 40000, 'executed_at' => self::D2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Archived source with its own adjustment must never reduce unrelated sales
        $arch = $this->sale($this->a, 'SX', 300, self::D1, ['archived_at' => now()]);
        $this->adjustment($arch, 9, 300, 300, 0, self::D1);
    }

    /** @return array{cash: float, recv: float, liab: float} */
    private function bs(array $scope, string $asOf): array
    {
        $r = (new OperationalBalanceSheetReportService())->generate($scope, $asOf);
        $liab = 0.0;
        foreach ($r->liabilities->rows as $row) {
            if ($row->name === 'Hutang Retur Pelanggan') {
                $liab = (float) $row->amount;
            }
        }

        return ['cash' => (float) $r->assets->rows[0]->amount, 'recv' => (float) $r->assets->rows[1]->amount, 'liab' => $liab];
    }

    /** @test */
    public function balance_sheet_reconciles_per_business_and_combined_at_each_cutoff(): void
    {
        $A = $this->a->id;
        $B = $this->b->id;

        // Owner A: payment 1600 - 400 moved out; refund 200 and debt reduction 100 only from D3.
        foreach ([self::D1, self::D2] as $d) {
            $this->assertEquals(['cash' => 1200.0, 'recv' => 500.0, 'liab' => 0.0], $this->bs([$A], $d), "A @ $d");
        }
        $this->assertEquals(['cash' => 1000.0, 'recv' => 400.0, 'liab' => 0.0], $this->bs([$A], self::D3), 'A @ D3');

        // Owner B: receives 400 dated by the backing payment (D1), replacement sale dated D2.
        $this->assertEquals(['cash' => 400.0, 'recv' => 0.0, 'liab' => 400.0], $this->bs([$B], self::D1), 'B @ D1 (transfer precedes its sale)');
        foreach ([self::D2, self::D3] as $d) {
            $this->assertEquals(['cash' => 400.0, 'recv' => 0.0, 'liab' => 0.0], $this->bs([$B], $d), "B @ $d");
        }

        // Combined: cash is additive; the receivable position is additive net of the refund liability at every cutoff.
        foreach ([self::D1, self::D2, self::D3] as $d) {
            $a = $this->bs([$A], $d);
            $b = $this->bs([$B], $d);
            $both = $this->bs([$A, $B], $d);
            $this->assertEquals($a['cash'] + $b['cash'], $both['cash'], "cash @ $d");
            $this->assertEquals(($a['recv'] - $a['liab']) + ($b['recv'] - $b['liab']), $both['recv'] - $both['liab'], "net receivable @ $d");
        }
        $this->assertEquals(['cash' => 1400.0, 'recv' => 400.0, 'liab' => 0.0], $this->bs([$A, $B], self::D3));
    }

    /** @test */
    public function cash_flow_period_reconciles_per_business_and_combined_with_refund_by_its_own_date(): void
    {
        $svc = new OperationalCashFlowReportService();
        $rows = fn (array $scope, string $from, string $to) => collect($svc->generate($scope, new OperationalCashFlowReportFilterData($from, $to))->operatingActivities->rows)
            ->mapWithKeys(fn ($r) => [$r->name => (float) $r->amount]);
        $recv = 'Penerimaan dari pelanggan';
        $ret = 'Kartu kredit dan liabilitas jangka pendek lainnya';

        $a = $rows([$this->a->id], '2026-01-01', '2026-03-31');
        $b = $rows([$this->b->id], '2026-01-01', '2026-03-31');
        $both = $rows([$this->a->id, $this->b->id], '2026-01-01', '2026-03-31');
        $this->assertEquals(1200.0, $a[$recv]);
        $this->assertEquals(-200.0, $a[$ret]);
        $this->assertEquals(400.0, $b[$recv]);
        $this->assertEquals(1600.0, $both[$recv]);
        $this->assertEquals(-200.0, $both[$ret]);

        // Earlier window: refund (D3) is outside; transfer follows its backing payment (D1).
        $early = $rows([$this->a->id], '2026-01-01', '2026-02-28');
        $this->assertEquals(1200.0, $early[$recv]);
        $this->assertEquals(0.0, $early[$ret]);
        // Later window: no receipts, only the refund.
        $late = $rows([$this->a->id, $this->b->id], '2026-02-15', '2026-03-31');
        $this->assertEquals(0.0, $late[$recv]);
        $this->assertEquals(-200.0, $late[$ret]);
    }

    /** @test */
    public function archived_source_adjustment_does_not_reduce_unrelated_receivables(): void
    {
        // SX (archived, 300 debt reduction at D1) leaves owner A's D1 receivable at exactly 500.
        $this->assertEquals(500.0, $this->bs([$this->a->id], self::D1)['recv']);
    }

    /** @test */
    public function customer_and_aged_receivables_apply_debt_reduction_by_commit_date_and_ignore_transfers_and_archived(): void
    {
        $customers = new \App\Services\Reports\CustomerReceivablesReportQueryService();
        $aged = new \App\Services\Reports\AgedReceivablesReportQueryService();
        $balance = function (string $asOf) use ($customers) {
            return (float) $customers->build(new \App\Services\Reports\CustomerReceivablesReportFilterData($asOf, $this->a->id))->get()->sum('sisa_piutang');
        };

        // S2 (customer): 500 before the debt reduction is committed (D3), 400 from D3 on. Nothing else is open:
        // S1 is paid, SP is settled after the 400 transfer, the archived SX never shows.
        $this->assertEquals(500.0, $balance(self::D2));
        $this->assertEquals(400.0, $balance(self::D3));
        $this->assertEquals(0.0, (float) $customers->build(new \App\Services\Reports\CustomerReceivablesReportFilterData(self::D3, $this->b->id))->get()->sum('sisa_piutang'));

        $total = fn (string $asOf, int $scope) => (float) $aged->build(new \App\Services\Reports\AgedReceivablesReportFilterData($asOf, $scope))->get()->sum('total_balance');
        $this->assertEquals(500.0, $total(self::D2, $this->a->id));
        $this->assertEquals(400.0, $total(self::D3, $this->a->id));
        $this->assertEquals(0.0, $total(self::D3, $this->b->id));
    }

    /** @test */
    public function movement_events_reconcile_with_balance_sheet_and_are_additive_across_scopes(): void
    {
        $events = new \App\Services\Reports\OperationalMovementEventService();
        $net = function (array $scope, string $cutoff, string $bucket) use ($events) {
            $sum = 0.0;
            foreach ($events->getMovementEvents($scope, $cutoff) as $e) {
                if ($e['bucket'] === $bucket && substr($e['dt'], 0, 10) <= $cutoff) {
                    $sum += $e['debit'] - $e['credit'];
                }
            }

            return round($sum, 2);
        };
        $A = [$this->a->id];
        $B = [$this->b->id];
        $both = [$this->a->id, $this->b->id];

        foreach ([self::D1, self::D2, self::D3] as $d) {
            foreach ([\App\Services\Reports\OperationalGeneralLedgerBucketConfig::CASH_BANK, \App\Services\Reports\OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE] as $bucket) {
                $this->assertEquals($net($A, $d, $bucket) + $net($B, $d, $bucket), $net($both, $d, $bucket), "$bucket additive @ $d");
            }
            // The ledger's cash and the balance sheet's cash are the same number per scope.
            foreach ([$A, $B, $both] as $scope) {
                $this->assertEquals($this->bs($scope, $d)['cash'], $net($scope, $d, \App\Services\Reports\OperationalGeneralLedgerBucketConfig::CASH_BANK), 'ledger cash = balance sheet cash @ ' . $d . ' ' . json_encode($scope));
                $bs = $this->bs($scope, $d);
                $this->assertEquals($bs['recv'] - $bs['liab'], $net($scope, $d, \App\Services\Reports\OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE), 'ledger AR = balance sheet receivable @ ' . $d . ' ' . json_encode($scope));
            }
        }

        // Trial balance: opening + period = ending in each scope, and totals are additive across scopes.
        $tb = new \App\Services\Reports\OperationalTrialBalanceReportService($events);
        $sum = fn (array $scope) => $tb->generate($scope, '2026-02-15', '2026-03-31');
        $ra = $sum($A);
        $rb = $sum($B);
        $rboth = $sum($both);
        foreach ([$ra, $rb, $rboth] as $r) {
            $this->assertEquals(
                round($r->grandTotalOpeningDebit - $r->grandTotalOpeningCredit + $r->grandTotalPeriodDebit - $r->grandTotalPeriodCredit, 2),
                round($r->grandTotalEndingDebit - $r->grandTotalEndingCredit, 2)
            );
        }
        $this->assertEquals($ra->grandTotalPeriodDebit + $rb->grandTotalPeriodDebit, $rboth->grandTotalPeriodDebit);
        $this->assertEquals($ra->grandTotalPeriodCredit + $rb->grandTotalPeriodCredit, $rboth->grandTotalPeriodCredit);
        $this->assertEquals($ra->grandTotalOpeningDebit + $rb->grandTotalOpeningDebit, $rboth->grandTotalOpeningDebit);
        $this->assertEquals($ra->grandTotalOpeningCredit + $rb->grandTotalOpeningCredit, $rboth->grandTotalOpeningCredit);
    }

    /** @test */
    public function sale_and_completion_reports_net_standard_returns_and_follow_owner_transfers_per_scope(): void
    {
        $headers = fn (int $scope, bool $global = false) => (new \App\Services\Reports\SaleReportQueryService())
            ->build(new \App\Services\Reports\SaleReportFilterData('2026-01-01', '2026-03-31', isGlobal: $global, scopeSettingId: $scope, reportMode: 'header'))
            ->get()->keyBy('reference');
        $completion = fn (int $scope, bool $global = false) => (new \App\Services\Reports\SalesOrderCompletionReportQueryService())
            ->build(new \App\Services\Reports\SalesOrderCompletionReportFilterData('2026-01-01', '2026-03-31', 'Pemesanan', isGlobal: $global, scopeSettingId: $scope))
            ->get()->keyBy('reference');

        // Owner A: standard cash refund nets S1 paid (1000-200) and value (200); S2 carries only its debt reduction;
        // SP's paid is its own payment minus the 400 that moved to B.
        $a = $headers($this->a->id);
        $this->assertEqualsCanonicalizing(['S1', 'S2', 'SP'], $a->keys()->all(), 'archived SX is not reported');
        $this->assertEquals([800.0, 200.0], [(float) $a['S1']->derived_active_paid, (float) $a['S1']->derived_adj_value]);
        $this->assertEquals([0.0, 100.0], [(float) $a['S2']->derived_active_paid, (float) $a['S2']->derived_adj_value]);
        $this->assertEquals([200.0, 0.0], [(float) $a['SP']->derived_active_paid, (float) $a['SP']->derived_adj_value]);

        // Owner B: the replacement sale is fully paid by the moved 400 (transfer-aware, no direct payment rows).
        $b = $headers($this->b->id);
        $this->assertEquals(400.0, (float) $b['SB']->derived_active_paid);

        // Combined scope is the union of both owners with identical per-sale values.
        $both = $headers($this->a->id, true);
        $this->assertEqualsCanonicalizing(['S1', 'S2', 'SP', 'SB'], $both->keys()->all());
        foreach (['S1', 'S2', 'SP'] as $ref) {
            $this->assertEquals((float) $a[$ref]->derived_active_paid, (float) $both[$ref]->derived_active_paid);
        }
        $this->assertEquals(400.0, (float) $both['SB']->derived_active_paid);

        // Completion report: net invoice amount (gross minus committed value) and transfer-aware paid.
        $ca = $completion($this->a->id);
        $this->assertEquals([800.0, 800.0], [(float) $ca['S1']->derived_invoice_amount, (float) $ca['S1']->derived_active_paid]);
        $this->assertEquals([400.0, 0.0], [(float) $ca['S2']->derived_invoice_amount, (float) $ca['S2']->derived_active_paid]);
        $this->assertEquals([200.0, 200.0], [(float) $ca['SP']->derived_invoice_amount, (float) $ca['SP']->derived_active_paid]);
        $cb = $completion($this->b->id);
        $this->assertEquals([400.0, 400.0], [(float) $cb['SB']->derived_invoice_amount, (float) $cb['SB']->derived_active_paid]);
        $this->assertEqualsCanonicalizing(['S1', 'S2', 'SP', 'SB'], $completion($this->a->id, true)->keys()->all());
    }
}

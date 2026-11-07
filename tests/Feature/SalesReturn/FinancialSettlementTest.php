<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\SalesReturn\SaleReturnSettlementForm;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Support\SalesReturn\SaleReturnLifecycleSyncService;
use App\Support\SalesReturn\StandardFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\GlobalSalePaymentService;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnAdjustment;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Tests\TestCase;

/** Group 6: source-derived value, debt first, positive-only payouts, immutable evidence, projections. */
class FinancialSettlementTest extends TestCase
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

    // ------------------------------------------------------------------ worked example + debt first

    /** @test */
    public function rp1000000_sale_paid_400000_returning_800000_reduces_debt_600000_and_refunds_200000(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 400000);
        $return = $this->returnOf($s['lines'][0], 8);
        $row = $this->cash($return);
        $this->assertEquals(800000, $row->fresh()->reviewed_value);

        $this->approve($row);

        $adj = SaleReturnAdjustment::query()->sole();
        $this->assertEquals(800000, $adj->committed_value);
        $this->assertEquals(600000, $adj->debt_reduction);
        $this->assertEquals(200000, $adj->cash_refund);
        $payment = SaleReturnPayment::query()->sole();
        $this->assertEquals(200000, $payment->amount);
        $this->assertSame((int) $payment->id, (int) $adj->sale_return_payment_id);
        $this->assertSame((int) $row->id, (int) $payment->sale_return_item_settlement_id);

        $sale = $s['sale']->fresh();
        $this->assertEquals(1000000, $sale->total_amount, 'gross source evidence preserved');
        $this->assertEquals(200000, $sale->net_total_amount, 'remaining net obligation');
        $this->assertEquals(200000, $sale->paid_amount, 'net paid = 400000 - 200000 refunded');
        $this->assertEquals(0, $sale->due_amount);
        $this->assertEquals(0, $sale->live_due_amount);
        $this->assertSame('PAID', $sale->payment_status);
        $this->assertSame(Settlement::STATUS_APPROVED, $row->fresh()->status);
        $this->assertEquals(200000, $return->fresh()->paid_amount, 'return shows cash actually paid');
        $this->assertEquals(['value' => 800000.0, 'debt' => 600000.0, 'cash' => 200000.0], app(StandardFinancialService::class)->returnTotals($return));
    }

    /** @test */
    public function debt_only_completion_creates_no_cash_payment_row(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 0);
        $return = $this->returnOf($s['lines'][0], 8);
        $this->approve($this->cash($return));

        $adj = SaleReturnAdjustment::query()->sole();
        $this->assertEquals(800000, $adj->debt_reduction);
        $this->assertEquals(0, $adj->cash_refund);
        $this->assertNull($adj->sale_return_payment_id);
        $this->assertSame(0, SaleReturnPayment::query()->count());
        $this->assertEquals(200000, $s['sale']->fresh()->live_due_amount);
        $this->assertEquals(200000, $s['sale']->fresh()->due_amount);
        $this->assertEquals(0, $return->fresh()->paid_amount);
    }

    // ------------------------------------------------------------------ source-derived value

    /** @test */
    public function value_includes_header_discount_and_never_uses_client_or_detail_totals(): void
    {
        // A 600k (3 units) + B 400k (1 unit), header discount 100k: A nets 540k, 180k per unit.
        $s = $this->sale([['qty' => 3, 'sub' => 600000], ['qty' => 1, 'sub' => 400000]], discount: 100000, paid: 900000);
        $return = $this->returnOf($s['lines'][0], 1);
        $resolved = app(StandardFinancialService::class)->resolve($this->cash($return)->fresh());

        $this->assertEquals(180000.0, $resolved['value']);
    }

    /** @test */
    public function one_serial_of_a_multi_serial_detail_is_bounded_by_its_attributable_unit_charge(): void
    {
        $s = $this->sale([['qty' => 3, 'sub' => 600000]], discount: 60000, paid: 540000, serial: true);
        $ids = collect($s['lines'][0]['serials'])->pluck('id')->all();
        $return = $this->returnOf($s['lines'][0], 3, $ids);
        $row = $this->cash($return, $ids[0]);

        $this->assertEquals(180000.0, (float) $row->fresh()->reviewed_value, 'one of three serials, not the whole 540000 detail');
        $this->approve($row);
        $this->assertEquals(180000, SaleReturnAdjustment::query()->sole()->committed_value);
    }

    /** @test */
    public function cumulative_returns_of_one_line_never_exceed_its_net_value_and_the_last_takes_the_rounding_remainder(): void
    {
        $s = $this->sale([['qty' => 3, 'sub' => 100]], paid: 100, serial: true);
        $ids = collect($s['lines'][0]['serials'])->pluck('id')->all();

        foreach ($ids as $id) {
            $return = $this->returnOf($s['lines'][0], 1, [$id]);
            $this->approve($this->cash($return, $id));
        }

        $this->assertEquals([33.33, 33.33, 33.34], SaleReturnAdjustment::query()->orderBy('id')->pluck('committed_value')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(100.0, (float) SaleReturnAdjustment::query()->sum('committed_value'));
    }

    // ------------------------------------------------------------------ stale / ambiguous / zero / caps

    /** @test */
    public function a_source_change_after_review_blocks_approval_without_any_money_effect(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 400000);
        $return = $this->returnOf($s['lines'][0], 8);
        $row = $this->cash($return);

        // a competing payment arrives after the review
        SalePayment::create(['sale_id' => $s['sale']->id, 'amount' => 100000, 'date' => now()->toDateString(), 'reference' => 'LATE', 'payment_method' => 'Cash']);
        $s['sale']->fresh()->reconcileFromActivePayments();

        $this->approve($row);

        $this->assertSame(Settlement::STATUS_SUBMITTED, $row->fresh()->status);
        $this->assertSame(0, SaleReturnAdjustment::query()->count());
        $this->assertSame(0, SaleReturnPayment::query()->count());
    }

    /** @test */
    public function two_reviews_competing_for_one_balance_only_the_first_commits_the_second_needs_renewed_review(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 200]], paid: 200, serial: true);
        $ids = collect($s['lines'][0]['serials'])->pluck('id')->all();
        $return = $this->returnOf($s['lines'][0], 2, $ids);
        $a = $this->cash($return, $ids[0]);
        $b = $this->cash($return, $ids[1]);   // reviewed against the same evidence

        $this->approve($a);
        $this->approve($b);

        $this->assertSame(1, SaleReturnAdjustment::query()->count());
        $this->assertSame(Settlement::STATUS_SUBMITTED, $b->fresh()->status);
        $this->assertEquals(100, SaleReturnPayment::query()->sum('amount'));
    }

    /** @test */
    public function ambiguous_evidence_blocks_financial_review_while_receiving_stays_available(): void
    {
        // Header claims 500 paid but no payment evidence explains it.
        $s = $this->sale([['qty' => 2, 'sub' => 1000]], paid: 0, headerPaid: 500);
        $flow = $s['lines'][0];
        $return = $this->makeReturnFor($flow, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);

        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();
        $this->assertNotNull($return->fresh()->received_at, 'physical receiving is independent of financial approval');

        $settlementReturn = $return->fresh();
        Livewire::test(SaleReturnSettlementForm::class, ['saleReturnId' => $settlementReturn->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_CASH_REFUND)
            ->call('submitLine', 0)
            ->assertHasErrors(['settlementLines.0.nominal']);
        $this->assertSame(0, Settlement::query()->count());

        // and a header that its lines do not explain is equally blocked
        $s2 = $this->sale([['qty' => 2, 'sub' => 1000]], paid: 1000);
        $s2['sale']->forceFill(['total_amount' => 5000])->save();
        $this->expectException(\App\Support\SalesReturn\SalesReturnFinancialBlockedException::class);
        app(StandardFinancialService::class)->resolve($this->cash($this->returnOf($s2['lines'][0], 1))->fresh());
    }

    /** @test */
    public function zero_input_is_rejected_and_the_old_zero_to_subtotal_fallback_is_gone(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 1000]], paid: 1000);
        $return = $this->returnOf($s['lines'][0], 1);

        Livewire::test(SaleReturnSettlementForm::class, ['saleReturnId' => $return->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_CASH_REFUND)
            ->set('settlementLines.0.nominal', 0)
            ->call('submitLine', 0)
            ->assertHasErrors(['settlementLines.0.nominal']);
        $this->assertSame(0, Settlement::query()->count());

        $row = Settlement::create(['sale_return_id' => $return->id, 'sale_return_detail_id' => $return->saleReturnDetails()->value('id'), 'method' => 'CASH_REFUND', 'nominal' => 0, 'status' => Settlement::STATUS_SUBMITTED]);
        $this->assertEquals(0.0, $row->fresh(['detail'])->getEffectiveNominal(), 'a zero nominal is never replaced by the detail subtotal');
    }

    /** @test */
    public function a_valid_submit_through_the_form_records_the_reviewed_value_and_fingerprint(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 1000]], paid: 1000);
        $return = $this->returnOf($s['lines'][0], 1);

        Livewire::test(SaleReturnSettlementForm::class, ['saleReturnId' => $return->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_CASH_REFUND)
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $row = Settlement::query()->sole();
        $this->assertEquals(500, $row->reviewed_value);
        $this->assertSame(64, strlen($row->reviewed_fingerprint));
    }

    /** @test */
    public function a_payout_that_exceeds_the_verified_remaining_payments_is_blocked_not_truncated(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 1000000);
        $earlier = $this->returnOf($s['lines'][0], 1);
        // cash already refunded earlier outside the adjustment ledger (older return)
        SaleReturnPayment::create(['sale_return_id' => $earlier->id, 'amount' => 900000, 'date' => now()->toDateString(), 'reference' => 'OLD', 'payment_method' => 'Cash Refund']);

        $return = $this->returnOf($s['lines'][0], 8);
        $row = $this->cash($return);
        $this->approve($row);

        $this->assertSame(Settlement::STATUS_SUBMITTED, $row->fresh()->status);
        $this->assertSame(0, SaleReturnAdjustment::query()->count());
        $this->assertSame(1, SaleReturnPayment::query()->count(), 'no partial payout');
    }

    // ------------------------------------------------------------------ repair / unprocessed / replay / atomic

    /** @test */
    public function repair_and_cannot_process_leave_source_finances_unchanged(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 400000);
        $before = $s['sale']->fresh()->only(['total_amount', 'paid_amount', 'due_amount', 'payment_status']);

        foreach (['REPAIR', 'UNPROCESSED'] as $method) {
            $return = $this->returnOf($s['lines'][0], 1);
            $row = $this->settlementFor($return, Settlement::STATUS_SUBMITTED, $method);
            $this->approve($row);
            $this->assertSame(Settlement::STATUS_APPROVED_AWAITING_DISPATCH, $row->fresh()->status);
        }

        $this->assertSame($before, $s['sale']->fresh()->only(['total_amount', 'paid_amount', 'due_amount', 'payment_status']));
        $this->assertSame(0, SaleReturnAdjustment::query()->count());
        $this->assertSame(0, SaleReturnPayment::query()->count());
    }

    /** @test */
    public function replayed_approvals_and_duplicate_evidence_are_rejected(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 1000000);
        $return = $this->returnOf($s['lines'][0], 5);
        $row = $this->cash($return);

        $this->approve($row);
        $this->approve($row);
        $this->assertSame(1, SaleReturnAdjustment::query()->count());
        $this->assertSame(1, SaleReturnPayment::query()->count());

        $adj = SaleReturnAdjustment::query()->sole();
        try {
            SaleReturnAdjustment::create($adj->only(['sale_return_item_settlement_id', 'sale_return_id', 'sale_id', 'sale_return_detail_id', 'quantity', 'committed_value', 'debt_reduction', 'cash_refund', 'source_fingerprint', 'policy_version']) + ['committed_at' => now()]);
            $this->fail('a second adjustment for one settlement must be impossible');
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        }
        try {
            $adj->update(['cash_refund' => 1]);
            $this->fail('adjustments are immutable');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, SaleReturnPayment::query()->count());
    }

    /** @test */
    public function a_failure_after_the_money_effects_rolls_back_evidence_payout_and_projections(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 400000);
        $return = $this->returnOf($s['lines'][0], 8);
        $row = $this->cash($return);
        $before = $s['sale']->fresh()->only(['total_amount', 'paid_amount', 'due_amount', 'payment_status']);

        $this->app->instance(SaleReturnLifecycleSyncService::class, \Mockery::mock(SaleReturnLifecycleSyncService::class, function ($m) {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('syncSaleReturnCompletionRollup')->andThrow(new \RuntimeException('injected'));
        }));
        $this->approve($row);

        $this->assertSame(Settlement::STATUS_SUBMITTED, $row->fresh()->status);
        $this->assertSame(0, SaleReturnAdjustment::query()->count());
        $this->assertSame(0, SaleReturnPayment::query()->count());
        $this->assertSame($before, $s['sale']->fresh()->only(['total_amount', 'paid_amount', 'due_amount', 'payment_status']));
        $this->assertEquals(0, $return->fresh()->paid_amount);
    }

    // ------------------------------------------------------------------ projections

    /** @test */
    public function global_collection_revalidates_the_reduced_outstanding_debt(): void
    {
        $coaId = DB::table('chart_of_accounts')->insertGetId(['name' => 'COA SR', 'account_number' => 'ACC-SR-1', 'category' => 'Kas & Bank', 'setting_id' => 1]);
        $method = \Modules\Setting\Entities\PaymentMethod::create(['name' => 'CASH', 'coa_id' => $coaId, 'is_cash' => true]);
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 0);
        $return = $this->returnOf($s['lines'][0], 8);
        $this->approve($this->cash($return));

        $this->assertSame(0, Sale::query()->whereKey($s['sale']->id)->whereLiveDueAmountGreaterThan(200000)->count());
        $this->assertSame(1, Sale::query()->whereKey($s['sale']->id)->whereLiveDueAmountGreaterThan(0)->count());

        $service = app(GlobalSalePaymentService::class);
        $data = ['payment_method_id' => $method->id, 'reference' => 'GP', 'date' => now()->toDateString()];
        try {
            $service->storeMultiPayment(1, $data + ['allocations' => [$s['sale']->id => 300000]]);
            $this->fail('collecting canceled debt must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
            $this->assertStringContainsString('melebihi sisa tagihan', $e->errors()['allocations'][0]);
        }
        $this->assertSame(0, SalePayment::query()->where('sale_id', $s['sale']->id)->count());

        // exactly the remaining (reduced) debt can be collected, once
        $service->storeMultiPayment(1, $data + ['allocations' => [$s['sale']->id => 200000]]);
        $this->assertEquals(0, $s['sale']->fresh()->live_due_amount);
        $this->assertSame('PAID', $s['sale']->fresh()->payment_status);
    }

    /** @test */
    public function the_adjustment_migration_refuses_to_roll_back_once_evidence_exists(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 1000]], paid: 1000);
        $this->approve($this->cash($this->returnOf($s['lines'][0], 1)));

        $this->expectException(\RuntimeException::class);
        (require base_path('Modules/SalesReturn/Database/Migrations/2026_11_10_000100_create_sale_return_adjustments.php'))->down();
    }

    /** @test */
    public function several_payments_are_resolved_without_lazy_loading_and_sum_as_verified_evidence(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 300000);
        SalePayment::create(['sale_id' => $s['sale']->id, 'amount' => 100000, 'date' => now()->toDateString(), 'reference' => 'P2', 'payment_method' => 'Cash']);
        $s['sale']->fresh()->reconcileFromActivePayments();
        $return = $this->returnOf($s['lines'][0], 8);

        $resolved = app(StandardFinancialService::class)->resolve($this->cash($return)->fresh());
        $this->assertEquals(400000.0, $resolved['refundable']);
        $this->assertEquals(600000.0, $resolved['outstanding']);
    }

    // ------------------------------------------------------------------ review follow-up

    /** @test */
    public function percentage_discount_sales_use_the_established_representation_and_deterministic_allocation(): void
    {
        // 10% header discount stored as discount_percentage=10, discount_amount=0 (SaleNormalizer).
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 900000, discountPercentage: 10);
        $this->assertEquals(0, (float) $s['sale']->fresh()->discount_amount);
        $return = $this->returnOf($s['lines'][0], 8);

        $resolved = app(StandardFinancialService::class)->resolve($this->cash($return)->fresh());
        $this->assertEquals(720000.0, $resolved['value'], '8 of 10 units at 90000 each (1,000,000 less 10%)');

        // awkward remainders: the share must equal the sale normalizer's own largest-remainder proration
        $s2 = $this->sale([['qty' => 1, 'sub' => 100.01], ['qty' => 1, 'sub' => 200.02], ['qty' => 1, 'sub' => 33.33]], paid: 0, discountPercentage: 10);
        $expectedShares = app(\Modules\Sale\Services\SaleNormalizer::class)->prorateGlobalDiscount(
            [['sub_total' => 100.01], ['sub_total' => 200.02], ['sub_total' => 33.33]],
            round((100.01 + 200.02 + 33.33) * 0.10, 2)
        );
        foreach ([0, 1, 2] as $i) {
            $value = app(StandardFinancialService::class)->resolve($this->cash($this->returnOf($s2['lines'][$i], 1))->fresh())['value'];
            $this->assertEqualsWithDelta([100.01, 200.02, 33.33][$i] - $expectedShares[$i], $value, 0.001);
        }
    }

    /** @test */
    public function fixed_amount_discount_still_works(): void
    {
        $s = $this->sale([['qty' => 4, 'sub' => 400]], discount: 40, paid: 360);
        $value = app(StandardFinancialService::class)->resolve($this->cash($this->returnOf($s['lines'][0], 1))->fresh())['value'];
        $this->assertEquals(90.0, $value);
    }

    /** @test */
    public function a_fully_settled_return_header_has_no_unpaid_balance_and_keeps_cash_distinct(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 400000);
        $return = $this->returnOf($s['lines'][0], 8);
        $this->approve($this->cash($return->fresh()));

        $fresh = $return->fresh();
        $this->assertEquals(200000, $fresh->paid_amount, 'cash actually paid');
        $this->assertEquals(0, $fresh->due_amount, 'remaining settlement obligation');
        $this->assertSame('PAID', strtoupper($fresh->payment_status));
    }

    /** @test */
    public function a_debt_only_return_is_settled_without_any_cash_paid(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 0);
        $return = $this->returnOf($s['lines'][0], 8);
        $this->approve($this->cash($return->fresh()));

        $fresh = $return->fresh();
        $this->assertEquals(0, $fresh->paid_amount);
        $this->assertEquals(0, $fresh->due_amount);
        $this->assertSame('PAID', strtoupper($fresh->payment_status));
    }

    /** @test */
    public function receivable_reports_show_the_canceled_debt_as_not_outstanding_and_respect_dates(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 400000);
        $return = $this->returnOf($s['lines'][0], 8);
        $customers = new \App\Services\Reports\CustomerReceivablesReportQueryService();
        $aged = new \App\Services\Reports\AgedReceivablesReportQueryService();
        // SQLite compares the stored datetime of `sales.date` as text, so report days are taken a day ahead.
        $today = now()->addDay()->toDateString();

        $outstanding = fn (string $date) => (float) $customers->build(new \App\Services\Reports\CustomerReceivablesReportFilterData($date, 1))->get()->sum('sisa_piutang');
        $agedTotal = fn (string $date) => (float) $aged->build(new \App\Services\Reports\AgedReceivablesReportFilterData($date, 1))->get()->sum('total_balance');

        $this->assertEquals(600000.0, $outstanding($today), 'before the return: 1,000,000 - 400,000');
        $this->approve($this->cash($return->fresh()));

        $this->assertEquals(0.0, $outstanding($today), 'Rp600,000 canceled debt is no longer receivable (live due is zero)');
        $this->assertEquals(0.0, $s['sale']->fresh()->live_due_amount);
        $this->assertEquals(0.0, $agedTotal($today));

        // as-of reporting: the adjustment is committed tomorrow, so today's report still shows the debt
        DB::table('sale_return_adjustments')->update(['committed_at' => now()->addDays(3)]);
        $this->assertEquals(600000.0, $outstanding($today));
        $this->assertEquals(600000.0, $agedTotal($today));
        $this->assertEquals(0.0, $outstanding(now()->addDays(4)->toDateString()));
        $this->assertEquals(0.0, $agedTotal(now()->addDays(4)->toDateString()));
    }

    /** @test */
    public function sale_and_balance_sheet_reports_net_the_adjustment_once(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 400000);
        $return = $this->returnOf($s['lines'][0], 8);
        $this->approve($this->cash($return->fresh()));
        $this->assertSame('COMPLETED', strtoupper($return->fresh()->status));

        // sale report: net total / net paid / zero remaining and a settled status
        $rows = (new \App\Services\Reports\SaleReportQueryService())->build(
            new \App\Services\Reports\SaleReportFilterData(now()->subDay()->toDateString(), now()->addDay()->toDateString(), scopeSettingId: 1, reportMode: 'header')
        )->get();
        $row = \App\Services\Reports\SaleReportQueryService::mapRow($rows->first(), 'header');
        $this->assertEquals(200000.0, $row['Total']);
        $this->assertEquals(200000.0, $row['Pembayaran']);
        $this->assertEquals(0.0, $row['Sisa Tagihan']);
        $this->assertSame('Lunas', $row['Status Pembayaran']);

        // balance sheet: receivable 1,000,000 - 800,000 returned - (400,000 paid - 200,000 refunded) = 0
        $sheet = app(\App\Services\Reports\OperationalBalanceSheetReportService::class)->generate(1, now()->toDateString());
        $receivable = collect($sheet->assets->rows)->firstWhere('name', 'Piutang Usaha');
        $this->assertEquals(0.0, $receivable->amount);

        // general ledger: AR credited once for the returned value, sales return debited
        $events = app(\App\Services\Reports\OperationalMovementEventService::class)->getMovementEvents(1, now()->toDateString());
        $returns = collect($events)->where('sourceType', 'Retur Penjualan');
        $this->assertEquals(800000.0, $returns->where('bucket', 'returns_and_adjustments')->sum('debit'));
        $this->assertEquals(800000.0, $returns->where('bucket', 'accounts_receivable')->sum('credit'));
    }

    // ------------------------------------------------------------------ second review follow-up

    /** @test */
    public function a_discounted_return_is_fully_settled_without_phantom_due(): void
    {
        // list price 1000, 10% header discount: the header total is 1000, the committed value 900.
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 900, discountPercentage: 10);
        $return = $this->returnOf($s['lines'][0], 1);
        $this->assertEquals(1000, $return->total_amount, 'header total is the list-price basis (never rewritten)');

        $this->approve($this->cash($return));

        $fresh = $return->fresh();
        $this->assertEquals(900, SaleReturnAdjustment::query()->sole()->committed_value);
        $this->assertEquals(0, $fresh->due_amount, 'no Rp100 phantom due');
        $this->assertSame('PAID', strtoupper($fresh->payment_status));
        $this->assertEquals(900, $fresh->paid_amount, 'cash actually paid stays distinct');
    }

    /** @test */
    public function operational_reports_recognize_committed_effects_by_their_own_dates_before_the_header_completes(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 400000);
        $return = $this->returnOf($s['lines'][0], 8);
        $this->approve($this->cash($return));

        $snapshot = function () {
            $sheet = app(\App\Services\Reports\OperationalBalanceSheetReportService::class)->generate(1, now()->toDateString());
            $events = collect(app(\App\Services\Reports\OperationalMovementEventService::class)->getMovementEvents(1, now()->toDateString()));
            $cash = app(\App\Services\Reports\OperationalCashFlowReportService::class)->generate(1, new \App\Services\Reports\OperationalCashFlowReportFilterData(now()->subDay()->toDateString(), now()->addDay()->toDateString()));

            return [
                'receivable' => collect($sheet->assets->rows)->firstWhere('name', 'Piutang Usaha')->amount,
                'cash_and_bank' => collect($sheet->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount,
                'returned_value' => (float) $events->where('sourceType', 'Retur Penjualan')->where('bucket', 'returns_and_adjustments')->sum('debit'),
                'refund_cash' => (float) $events->where('sourceType', 'Pengembalian Dana')->where('bucket', 'cash_bank')->sum('credit'),
                'cash_flow_refund' => collect($cash->operatingActivities->rows)->firstWhere('name', 'Kartu kredit dan liabilitas jangka pendek lainnya')->amount,
            ];
        };

        // sibling items unresolved: the return header is NOT completed yet
        DB::table('sale_returns')->where('id', $return->id)->update(['status' => 'AWAITING SETTLEMENT']);
        $open = $snapshot();
        $this->assertEquals(0.0, $open['receivable'], 'Rp600,000 canceled debt must not remain receivable');
        $this->assertEquals(800000.0, $open['returned_value']);
        $this->assertEquals(200000.0, $open['refund_cash']);
        $this->assertEquals(-200000.0, $open['cash_flow_refund']);

        // later completion changes nothing about what was already committed
        DB::table('sale_returns')->where('id', $return->id)->update(['status' => 'COMPLETED']);
        $this->assertEquals($open, $snapshot());
    }

    /** @test */
    public function an_archived_source_cannot_cancel_another_sales_receivable(): void
    {
        // Sale A: paid 1000, fully returned and refunded, then archived. Sale B: an unrelated Rp1,000 receivable.
        $a = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000);
        $this->approve($this->cash($this->returnOf($a['lines'][0], 1)));
        DB::table('sales')->where('id', $a['sale']->id)->update(['status' => 'RETURNED', 'archived_at' => now()]);
        $b = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 0);

        $sheet = app(\App\Services\Reports\OperationalBalanceSheetReportService::class)->generate(1, now()->toDateString());
        $this->assertEquals(1000.0, collect($sheet->assets->rows)->firstWhere('name', 'Piutang Usaha')->amount, 'only B is receivable; A (archived) reduces nothing twice');

        $events = collect(app(\App\Services\Reports\OperationalMovementEventService::class)->getMovementEvents(1, now()->toDateString()));
        $this->assertEquals(0.0, (float) $events->where('sourceType', 'Retur Penjualan')->sum('debit'), 'ledger books no adjustment for an ineligible (archived) source');
        $this->assertEquals(0.0, (float) $events->where('sourceType', 'Pengembalian Dana')->sum('credit'), 'nor its linked payout');
    }
}

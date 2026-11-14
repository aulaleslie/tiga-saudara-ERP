<?php

namespace Modules\Sale\Tests\Feature;

use App\Livewire\Sale\SaleTable;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Livewire\SaleSummaryCards;
use Modules\Sale\Services\GlobalSaleSummaryQuery;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * quicken-global-payment-lists: grouped SQL summary equivalence, Sale reduction read aggregate,
 * deferred/retained global summary state and scoped card-filter completion.
 */
class GlobalSaleSummaryAggregateTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting1;
    private Setting $setting2;
    private Customer $customer1;
    private Customer $customer2;
    private int $seq = 0;
    private int $settlementSeq = 0;
    private bool $allowGlobal = true;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA defer_foreign_keys = ON'); // fixture ledgers reference rows not modelled here; transaction is rolled back
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00'));

        $this->setting1 = Setting::factory()->create(['company_name' => 'Bisnis A']);
        $this->setting2 = Setting::factory()->create(['company_name' => 'Bisnis B']);
        session(['setting_id' => $this->setting1->id]);
        $this->customer1 = Customer::factory()->create(['setting_id' => $this->setting1->id]);
        $this->customer2 = Customer::factory()->create(['setting_id' => $this->setting1->id]);

        $this->actingAs(\App\Models\User::factory()->create());
        Gate::before(fn () => $this->allowGlobal ? true : null);
    }

    private function sale(array $attrs = []): Sale
    {
        $this->seq++;

        return Sale::create(array_merge([
            'date' => '2026-11-01',
            'due_date' => '2026-11-20',
            'reference' => 'AGG-' . $this->seq,
            'customer_id' => $this->customer1->id,
            'customer_name' => 'Agg Customer',
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'UNPAID',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting1->id,
        ], $attrs));
    }

    private function payment(Sale $sale, float $amount, string $status = SalePayment::STATUS_ACTIVE, string $date = '2026-11-10'): SalePayment
    {
        $this->seq++;

        return SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => $amount,
            'date' => $date,
            'reference' => 'PAY-AGG-' . $this->seq,
            'status' => $status,
            'payment_method' => 'Cash',
        ]);
    }

    private function credit(SalePayment $payment, float $amount): void
    {
        DB::table('sale_payment_credit_applications')->insert([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => ++$this->seq,
            'amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transfer(Sale $source, Sale $replacement, float $amount, int $originalPaymentId): void
    {
        DB::table('pos_owner_payment_transfers')->insert([
            'execution_key' => 'exec-' . (++$this->seq),
            'pos_return_id' => 1,
            'sale_return_detail_id' => $this->seq,
            'source_sale_id' => $source->id,
            'replacement_sale_id' => $replacement->id,
            'original_sale_payment_id' => $originalPaymentId,
            'payment_method' => 'Cash',
            'amount' => $amount,
            'amount_minor' => (int) round($amount * 100),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function reduction(Sale $sale, float $debtReduction, float $cashRefund = 0): void
    {
        DB::table('sale_return_adjustments')->insert([
            'sale_return_item_settlement_id' => ++$this->settlementSeq,
            'sale_return_id' => 1,
            'sale_id' => $sale->id,
            'sale_return_detail_id' => $this->settlementSeq,
            'quantity' => 1,
            'committed_value' => $debtReduction + $cashRefund,
            'debt_reduction' => $debtReduction,
            'cash_refund' => $cashRefund,
            'source_fingerprint' => str_repeat('a', 64),
            'policy_version' => 1,
            'committed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Authoritative per-Sale reads, as the pre-change summary computed them. */
    private function authoritative(?int $customerId = null, array $business = [], ?string $docFrom = null, ?string $docTo = null, ?string $dueFrom = null, ?string $dueTo = null): array
    {
        $scope = function ($q) use ($customerId, $business, $docFrom, $docTo, $dueFrom, $dueTo) {
            $q->globalPaymentEligible()->whereNull('archived_at')
                ->when($customerId, fn ($qq) => $qq->where('customer_id', $customerId))
                ->when($business, fn ($qq) => $qq->whereIn('setting_id', $business))
                ->when($docFrom, fn ($qq) => $qq->where('date', '>=', $docFrom))
                ->when($docTo, fn ($qq) => $qq->where('date', '<=', $docTo))
                ->when($dueFrom, fn ($qq) => $qq->where('due_date', '>=', $dueFrom))
                ->when($dueTo, fn ($qq) => $qq->where('due_date', '<=', $dueTo));
        };

        $outstanding = Sale::query()->tap($scope)->whereLiveDueAmountGreaterThan(0)->get();
        $overdue = Sale::query()->tap($scope)->where('due_date', '<', Carbon::today())->whereLiveDueAmountGreaterThan(0)->get();

        return [
            'outstanding' => ['count' => $outstanding->count(), 'total' => round((float) $outstanding->sum(fn ($s) => $s->live_due_amount), 2)],
            'overdue' => ['count' => $overdue->count(), 'total' => round((float) $overdue->sum(fn ($s) => $s->live_due_amount), 2)],
        ];
    }

    private function grouped(?int $customerId = null, array $business = [], ?string $docFrom = null, ?string $docTo = null, ?string $dueFrom = null, ?string $dueTo = null): array
    {
        $r = app(GlobalSaleSummaryQuery::class)->summarize($customerId, $business, $docFrom, $docTo, $dueFrom, $dueTo);
        foreach (['outstanding', 'overdue'] as $k) {
            $r[$k]['total'] = round($r[$k]['total'], 2);
        }

        return $r;
    }

    private function seedLedgerMix(): array
    {
        // Unpaid, overdue.
        $unpaid = $this->sale(['due_date' => '2026-11-10']);
        // Partial: two active payments + invalidated one + credit on active, credit on invalidated.
        $partial = $this->sale(['total_amount' => 2000.10, 'due_date' => '2026-11-13']);
        $p1 = $this->payment($partial, 300.05);
        $this->payment($partial, 200);
        $inv = $this->payment($partial, 900, SalePayment::STATUS_INVALIDATED);
        $this->credit($p1, 100);
        $this->credit($p1, 50);
        $this->credit($inv, 400);
        // Fully paid with overpayment (clamped to zero), recent payment.
        $over = $this->sale(['total_amount' => 500]);
        $this->payment($over, 700);
        // Transfers: incoming from active original, outgoing from active original, transfer whose original is invalidated/absent.
        $source = $this->sale(['total_amount' => 800, 'due_date' => '2026-11-14']);
        $srcPay = $this->payment($source, 800);
        $replacement = $this->sale(['total_amount' => 600, 'due_date' => '2026-11-01']);
        $this->transfer($source, $replacement, 250, $srcPay->id);
        $this->transfer($source, $replacement, 50, $srcPay->id);
        $this->transfer($source, $replacement, 999, $inv->id);       // invalidated original: ignored
        $this->transfer($source, $replacement, 777, 987654);         // absent original: ignored
        // Committed return reductions (multiple rows) on a partially paid sale.
        $returned = $this->sale(['total_amount' => 1500, 'status' => Sale::STATUS_RETURNED_PARTIALLY, 'due_date' => '2026-11-02']);
        $this->payment($returned, 100);
        $this->reduction($returned, 200.004);
        $this->reduction($returned, 300, 50);
        // Reduction exceeding the remaining due: clamped per Sale (still counted by the canonical predicate only when raw > 0).
        $overReduced = $this->sale(['total_amount' => 400]);
        $this->payment($overReduced, 100);
        $this->reduction($overReduced, 500);
        // Exclusions: archived, ineligible status, other customer/business.
        $this->sale(['archived_at' => now()]);
        $this->sale(['status' => 'DRAFTED']);
        $this->sale(['customer_id' => $this->customer2->id, 'setting_id' => $this->setting2->id, 'total_amount' => 333]);

        return compact('unpaid', 'partial', 'over', 'source', 'replacement', 'returned', 'overReduced');
    }

    public function test_grouped_summary_equals_authoritative_per_sale_balances_across_ledgers(): void
    {
        $this->seedLedgerMix();

        $expected = $this->authoritative();
        $actual = $this->grouped();

        $this->assertEquals($expected['outstanding'], $actual['outstanding']);
        $this->assertEquals($expected['overdue'], $actual['overdue']);
        // Hand-checked: unpaid 1000 + partial 1350.05 + source 300 + replacement 300 + returned 900 (899.996 rounded per Sale)
        // + other customer 333; overReduced (raw -200) excluded = 6 Sales / 4183.05;
        // overdue = unpaid, partial, replacement, returned.
        $this->assertSame(6, $actual['outstanding']['count']);
        $this->assertEquals(4183.05, $actual['outstanding']['total']);
        $this->assertSame(4, $actual['overdue']['count']);
        $this->assertEquals(3550.05, $actual['overdue']['total']);
    }

    public function test_grouped_summary_respects_customer_business_and_inclusive_date_scope(): void
    {
        $this->seedLedgerMix();
        $this->sale(['date' => '2026-10-31', 'due_date' => '2026-11-05']);
        $this->sale(['date' => '2026-11-14', 'due_date' => '2026-11-30']);

        $scopes = [
            [$this->customer1->id, [], null, null, null, null],
            [$this->customer2->id, [], null, null, null, null],
            [null, [$this->setting2->id], null, null, null, null],
            [null, [], '2026-11-01', '2026-11-14', null, null],
            [null, [], '2026-10-31', '2026-10-31', null, null],
            [null, [], null, null, '2026-11-01', '2026-11-13'],
            [$this->customer1->id, [$this->setting1->id], '2026-11-01', null, null, '2026-11-14'],
        ];

        foreach ($scopes as $args) {
            $expected = $this->authoritative(...$args);
            $actual = $this->grouped(...$args);
            $this->assertEquals($expected['outstanding'], $actual['outstanding'], json_encode($args));
            $this->assertEquals($expected['overdue'], $actual['overdue'], json_encode($args));
        }
    }

    public function test_recent_collections_keep_active_payment_amount_meaning(): void
    {
        $this->seedLedgerMix();
        $paidOld = $this->sale(['total_amount' => 100]);
        $this->payment($paidOld, 100, SalePayment::STATUS_ACTIVE, '2026-10-15'); // inclusive 30-day boundary (today - 30)
        $paidTooOld = $this->sale(['total_amount' => 100]);
        $this->payment($paidTooOld, 100, SalePayment::STATUS_ACTIVE, '2026-10-14');

        $recent = $this->grouped()['recent'];

        // Pre-change component query, evaluated with per-payment correlated subqueries.
        $expected = SalePayment::query()
            ->where('date', '>=', '2026-10-15')->where('date', '<=', Carbon::today()->endOfDay())
            ->where('status', SalePayment::STATUS_ACTIVE)
            ->whereHas('sale', fn ($q) => $q->whereNull('archived_at')->globalPaymentEligible()->whereLiveDueAmountLessThanOrEqual(0))
            ->selectRaw('COUNT(DISTINCT sale_id) as cnt, SUM(amount) as total')->first();

        $this->assertSame((int) $expected->cnt, $recent['count']);
        $this->assertEquals((float) $expected->total, $recent['total']);
        // Overpaid sale (700), over-reduced sale (100, raw due < 0), boundary-dated sale (100).
        $this->assertSame(3, $recent['count']);
        $this->assertEquals(900.0, $recent['total']);
    }

    public function test_summary_query_count_is_constant_and_selection_does_not_recalculate(): void
    {
        $component = new SaleSummaryCards();
        $component->mount(globalMode: true);

        $counts = [];
        foreach ([10, 100] as $target) {
            while ($this->seq < $target * 2) {
                $s = $this->sale();
                $this->payment($s, 100);
                $this->reduction($s, 10);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $component->retrySummary();
            $counts[$target] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame($counts[10], $counts[100]);
        $this->assertLessThanOrEqual(2, $counts[100]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->toggleCardFilter('unpaid');
        $component->getPiutangBelumTertagihProperty();
        $component->getPiutangTelatProperty();
        $component->getPenerimaanProperty();
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_table_reduction_aggregate_matches_fallback_without_per_row_queries(): void
    {
        $fixtures = $this->seedLedgerMix();

        $loaded = Sale::query()->withLiveSettlementAggregates()->whereIn('id', collect($fixtures)->pluck('id'))->get()->keyBy('id');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $aggregateDues = $loaded->map(fn ($s) => $s->live_due_amount);
        $this->assertCount(0, DB::getQueryLog(), 'Aggregated rows must not query reductions or balances per row.');
        DB::disableQueryLog();

        foreach ($fixtures as $sale) {
            $fresh = Sale::find($sale->id);
            $this->assertEquals($fresh->getCommittedDebtReduction(), $loaded[$sale->id]->getCommittedDebtReduction());
            $this->assertEquals($fresh->live_due_amount, $aggregateDues[$sale->id]);
        }
        // Known zero is used without a query.
        $this->assertEquals(0.0, $loaded[$fixtures['unpaid']->id]->getCommittedDebtReduction());
    }

    public function test_reconciliation_discards_preloaded_reduction_aggregate(): void
    {
        $sale = $this->sale(['total_amount' => 1000]);
        $loaded = Sale::query()->withLiveSettlementAggregates()->findOrFail($sale->id);
        $this->assertEquals(1000.0, $loaded->live_due_amount);

        // Settlement data changes after the aggregate was read.
        $this->reduction($sale, 250);
        $this->payment($sale, 100);

        $loaded->reconcileFromActivePayments();

        $this->assertEquals(650.0, (float) $loaded->fresh()->due_amount);
        $this->assertArrayNotHasKey('committed_debt_reduction_sum', $loaded->getAttributes());
    }

    public function test_setting_scoped_sale_table_still_renders_with_reduction_aggregate(): void
    {
        $sale = $this->sale(['reference' => 'SCOPED-ROW']);
        $this->reduction($sale, 100);
        $this->sale(['reference' => 'OTHER-ROW', 'setting_id' => $this->setting2->id]);

        Livewire::test(SaleTable::class, ['settingId' => $this->setting1->id])
            ->assertSee('SCOPED-ROW')
            ->assertDontSee('OTHER-ROW');
    }

    public function test_initial_render_defers_summary_and_load_calculates_once(): void
    {
        $this->sale();
        $calls = 0;
        $this->app->bind(GlobalSaleSummaryQuery::class, function () use (&$calls) {
            return new class($calls) extends GlobalSaleSummaryQuery {
                public function __construct(private int &$calls) {}
                public function summarize(...$args): array { $this->calls++; return parent::summarize(...$args); }
            };
        });

        $component = Livewire::test(SaleSummaryCards::class, ['globalMode' => true])
            ->assertSet('summary', null)
            ->assertSeeHtml('wire:init="loadSummary"')
            ->assertSeeHtml('data-sale-summary-placeholder')
            ->assertDontSee('Rp0');
        $this->assertSame(0, $calls);

        $component->call('loadSummary')
            ->assertSet('summary.outstanding.count', 1)
            ->assertDontSeeHtml('data-sale-summary-placeholder')
            ->assertDontSeeHtml('wire:init="loadSummary"');
        $this->assertSame(1, $calls);

        // Selection, unchanged Apply and a repeated init do not recalculate.
        $component->call('toggleCardFilter', 'unpaid', 3)
            ->assertDispatched('sale-filter', type: 'unpaid', workspace: 'standalone', seq: 3);
        $component->dispatch('global-sale-filters-changed', globalBusinessFilters: [], selectedCardFilter: 'unpaid');
        $component->call('loadSummary');
        $this->assertSame(1, $calls);

        // Changed inputs recalculate; explicit Reset recalculates even from default state.
        $component->dispatch('global-sale-filters-changed', globalBusinessFilters: [$this->setting2->id]);
        $this->assertSame(2, $calls);
        $component->assertSet('summary.outstanding.count', 0);
        $component->dispatch('global-sale-filters-changed', globalBusinessFilters: [], forceRefresh: true);
        $component->dispatch('global-sale-filters-changed', globalBusinessFilters: [], forceRefresh: true);
        $this->assertSame(4, $calls);
    }

    public function test_filter_applied_before_initialization_is_used_by_initial_load(): void
    {
        $this->sale();
        $this->sale(['setting_id' => $this->setting2->id]);

        Livewire::test(SaleSummaryCards::class, ['globalMode' => true])
            ->dispatch('global-sale-filters-changed', globalBusinessFilters: [$this->setting2->id])
            ->assertSet('summary', null)
            ->call('loadSummary')
            ->assertSet('summary.outstanding.count', 1)
            // Successive applies settle on the latest inputs.
            ->dispatch('global-sale-filters-changed', globalBusinessFilters: [$this->setting1->id])
            ->dispatch('global-sale-filters-changed', globalBusinessFilters: [$this->setting1->id, $this->setting2->id])
            ->assertSet('summary.outstanding.count', 2);
    }

    public function test_embedded_customer_constraint_survives_deferred_load(): void
    {
        $this->sale();
        $this->sale(['customer_id' => $this->customer2->id]);

        Livewire::test(SaleSummaryCards::class, ['globalMode' => true, 'customerId' => $this->customer2->id])
            ->call('loadSummary')
            ->assertSet('summary.outstanding.count', 1)
            ->assertSet('customerId', $this->customer2->id);
    }

    public function test_deferred_actions_are_forbidden_without_global_access(): void
    {
        $component = Livewire::test(SaleSummaryCards::class, ['globalMode' => true]);

        // Revoke access after mount: deferred and retry actions re-check authorization.
        $this->allowGlobal = false;

        $component->call('loadSummary')->assertForbidden();
    }

    public function test_summary_failure_shows_retry_and_never_zero(): void
    {
        $this->sale();
        $fail = true;
        $this->app->bind(GlobalSaleSummaryQuery::class, function () use (&$fail) {
            return new class($fail) extends GlobalSaleSummaryQuery {
                public function __construct(private bool &$fail) {}
                public function summarize(...$args): array
                {
                    if ($this->fail) {
                        throw new \RuntimeException('boom');
                    }

                    return parent::summarize(...$args);
                }
            };
        });

        $component = Livewire::test(SaleSummaryCards::class, ['globalMode' => true])
            ->call('loadSummary')
            ->assertSet('summary', null)
            ->assertSeeHtml('data-sale-summary-error')
            ->assertSee('Coba Lagi')
            ->assertSee('Tidak tersedia')
            ->assertDontSee('0 Transaksi');

        $fail = false;
        $component->call('retrySummary')
            ->assertSet('summaryError', null)
            ->assertSet('summary.outstanding.count', 1);
    }

    public function test_table_reports_scoped_card_filter_completion_with_sequence(): void
    {
        Livewire::test(SaleTable::class, ['globalMode' => true, 'customerId' => $this->customer1->id])
            ->dispatch('sale-filter', type: 'overdue', workspace: 'customer-' . $this->customer1->id, seq: 7)
            ->assertSet('selectedCardFilter', 'overdue')
            ->assertDispatched('sale-card-filter-applied', workspace: 'customer-' . $this->customer1->id, seq: 7);

        // Another workspace's selection is ignored.
        Livewire::test(SaleTable::class, ['globalMode' => true])
            ->dispatch('sale-filter', type: 'overdue', workspace: 'customer-999', seq: 1)
            ->assertSet('selectedCardFilter', null)
            ->assertNotDispatched('sale-card-filter-applied');
    }

    public function test_reset_forces_summary_refresh_and_table_has_loading_overlay(): void
    {
        Livewire::test(SaleTable::class, ['globalMode' => true])
            ->assertSeeHtml('data-sale-table-loading')
            ->call('resetGlobalFilters')
            ->assertDispatched('global-sale-filters-changed', forceRefresh: true);
    }

    public function test_retry_with_failed_filter_inputs_calculates_the_new_scope(): void
    {
        $this->sale(['date' => '2026-01-10', 'total_amount' => 1000]);
        $this->sale(['date' => '2026-01-20', 'total_amount' => 400]);
        $this->sale(['date' => '2026-06-10', 'total_amount' => 700]);

        $component = new SaleSummaryCards();
        $component->mount(globalMode: true, selectedCardFilter: 'unpaid');
        $component->loadSummary();
        $this->assertSame(3, $component->getPiutangBelumTertagihProperty()['count']);
        $this->assertEquals(2100, $component->getPiutangBelumTertagihProperty()['total']);

        // Apply (January, "unpaid" selected) failed before PHP; the user then selects "overdue".
        $component->toggleCardFilter('overdue');

        $component->retrySummary([
            'globalBusinessFilters' => [],
            'documentDateFrom' => '2026-01-01',
            'documentDateTo' => '2026-01-31',
            'dueDateFrom' => null,
            'dueDateTo' => null,
            // selectedCardFilter omitted: the client marks it superseded by the newer selection.
        ]);

        // Financial scope is replayed, the newer card selection is kept.
        $this->assertSame(2, $component->getPiutangBelumTertagihProperty()['count']);
        $this->assertEquals(1400, $component->getPiutangBelumTertagihProperty()['total']);
        $this->assertSame('overdue', $component->selectedCardFilter);
    }

    public function test_retry_after_failed_reset_restores_reset_selection(): void
    {
        $this->sale(['date' => '2026-01-10']);
        $this->sale(['date' => '2026-06-10']);

        $component = new SaleSummaryCards();
        $component->mount(globalMode: true, documentDateFrom: '2026-01-01', documentDateTo: '2026-01-31', selectedCardFilter: 'unpaid');
        $component->loadSummary();
        $this->assertSame(1, $component->getPiutangBelumTertagihProperty()['count']);

        // Reset event failed; no newer card selection, so the client replays its null selection.
        $component->retrySummary([
            'globalBusinessFilters' => [], 'documentDateFrom' => null, 'documentDateTo' => null,
            'dueDateFrom' => null, 'dueDateTo' => null, 'selectedCardFilter' => null, 'forceRefresh' => true,
        ]);

        $this->assertNull($component->selectedCardFilter);
        $this->assertSame(2, $component->getPiutangBelumTertagihProperty()['count']);
    }

    public function test_filter_event_before_initialization_does_not_calculate(): void
    {
        $this->sale();
        $component = new SaleSummaryCards();
        $component->mount(globalMode: true);

        // Initial load failed at HTTP level: not initialized, so a later filter event only stores inputs.
        $component->handleFiltersChanged([], '2026-01-01', '2026-12-31');
        $this->assertFalse($component->summaryInitialized);
        $this->assertNull($component->summary);

        // Retry (no client-retained inputs) calculates with the stored scope.
        $component->retrySummary();
        $this->assertSame(1, $component->getPiutangBelumTertagihProperty()['count']);
    }

    public function test_workspace_wires_transport_guard_sequenced_failures_and_client_retry(): void
    {
        $html = (string) $this->blade("@include('sale::global-payments.partials.workspace', ['keyPrefix' => 'standalone'])");

        // Rejected fetch() becomes a handled Livewire failure instead of a stuck queue.
        $this->assertStringContainsString('data-livewire-transport-guard', $html);
        // Body-read failures after headers are converted too (body buffered inside the guarded chain).
        $this->assertStringContainsString('response.text().then(', $html);
        $this->assertStringContainsString('.catch(transportFailure)', $html);
        $this->assertStringContainsString('failure.preventDefault()', $html);

        // Card failures release pending state only for the current sequence.
        // Batched commits may carry several sequences; release if any is the current one.
        $this->assertStringContainsString('const seqs = this.failedCommitSeqs(commit);', $html);
        $this->assertStringContainsString('if (seqs.includes(this.cardFilterSeq)) this.cardFilterPending = false;', $html);

        // Summary request failures that never reach PHP still surface a retry and mark totals stale.
        $this->assertStringContainsString('summaryRequestFailed', $html);
        $this->assertStringContainsString('data-sale-summary-request-error', $html);
        // Retry replays the latest failed filter inputs; banner overrides Bootstrap's d-flex !important.
        $this->assertStringContainsString('$wire.retrySummary(retryInputs())', $html);
        $this->assertStringContainsString('x-show.important="summaryRequestFailed"', $html);
        // A filter-only success after a failed initial load must not hide the retry.
        $this->assertStringContainsString('if (!calculates && this.initialLoadFailed) return;', $html);
        // Failed event's selection is replayed unless a later card selection superseded it.
        $this->assertStringContainsString('if (this.selectionSuperseded) delete inputs.selectedCardFilter;', $html);
        $this->assertStringContainsString('succeed(() => { if (this.failedSummaryFilters) this.selectionSuperseded = true; });', $html);
        $this->assertStringNotContainsString('x-show="summaryRequestFailed"', $html);
        $this->assertStringContainsString("'opacity-50': summaryRequestFailed", $html);
    }

    public function test_single_query_summarize_empty_scope_zero_amount_and_future_payment_boundary(): void
    {
        $service = app(GlobalSaleSummaryQuery::class);

        // 1. Empty scope returns exact zeroed integer counts and float totals in 1 SQL query
        DB::flushQueryLog();
        DB::enableQueryLog();
        $empty = $service->summarize($this->customer2->id, [$this->setting2->id], null, null, null, null);
        $emptyQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $emptyQueries);
        $this->assertSame([
            'outstanding' => ['count' => 0, 'total' => 0.0],
            'overdue' => ['count' => 0, 'total' => 0.0],
            'recent' => ['count' => 0, 'total' => 0.0],
        ], $empty);

        // 2. Settled Sale with a 0-amount active payment in the recent window still counts toward recent.count
        $zeroRecent = $this->sale([
            'total_amount' => 500,
            'customer_id' => $this->customer1->id,
            'setting_id' => $this->setting1->id,
            'reference' => 'AGG-ZERO-RECENT',
        ]);
        $this->payment($zeroRecent, 500, SalePayment::STATUS_ACTIVE, '2026-08-01');
        $this->payment($zeroRecent, 0, SalePayment::STATUS_ACTIVE, '2026-11-12');

        // 3. Preserved card-vs-table distinction: future-dated active payment counts for table paidLast30DaysOnly
        //    but NOT for GlobalSaleSummaryQuery::summarize()['recent'] (which bounds <= today endOfDay).
        $futureSettled = $this->sale([
            'total_amount' => 300,
            'customer_id' => $this->customer1->id,
            'setting_id' => $this->setting1->id,
            'reference' => 'AGG-FUTURE-SETTLED',
            'payment_status' => 'PAID',
        ]);
        $this->payment($futureSettled, 300, SalePayment::STATUS_ACTIVE, '2026-11-20');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary = $service->summarize($this->customer1->id, [$this->setting1->id], null, null, null, null);
        $summaryQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $summaryQueries);
        $this->assertSame(1, $summary['recent']['count']);
        $this->assertEquals(0.0, $summary['recent']['total']);

        // SaleTable paidLast30DaysOnly includes both (no upper date bound in table predicate)
        Livewire::test(SaleTable::class, ['globalMode' => true])
            ->set('paidLast30DaysOnly', true)
            ->assertSee('AGG-ZERO-RECENT')
            ->assertSee('AGG-FUTURE-SETTLED');
    }
}


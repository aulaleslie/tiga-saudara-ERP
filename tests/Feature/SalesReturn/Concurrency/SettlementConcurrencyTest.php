<?php

namespace Tests\Feature\SalesReturn\Concurrency;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturnAdjustment;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Setting\Entities\PaymentMethod;
use Tests\Feature\SalesReturn\BuildsFinancialFlow;
use Tests\Feature\SalesReturn\BuildsStandardFlow;
use Tests\TestCase;

/**
 * Task 6.6: concurrent payment/return approvals against REAL MySQL row locks, with real OS processes
 * calling the production controller/services (see settlement_worker.php).
 *
 * @group mysql
 */
class SettlementConcurrencyTest extends TestCase
{
    use DatabaseTruncation, BuildsStandardFlow, BuildsFinancialFlow;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }
        $this->seedBase();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateDatabaseTables();
        } finally {
            parent::tearDown();
        }
    }

    /** @param array<int, array<int, string>> $jobs argument lists, one process each */
    private function race(array $jobs): array
    {
        $barrier = sys_get_temp_dir() . '/sr_settlement_barrier_' . uniqid() . '.lock';
        $procs = $pipes = [];
        foreach ($jobs as $i => $args) {
            $cmd = 'php ' . escapeshellarg(__DIR__ . '/settlement_worker.php') . ' ' . implode(' ', array_map('escapeshellarg', array_merge([$args[0], (string) $this->user->id], array_slice($args, 1), [$barrier])));
            $procs[$i] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            $this->assertIsResource($procs[$i]);
        }
        usleep(400000);   // let every worker boot and wait on the barrier
        touch($barrier);

        $results = [];
        foreach ($jobs as $i => $_) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            array_map('fclose', $pipes[$i]);
            $code = proc_close($procs[$i]);
            $this->assertSame(0, $code, "worker {$i} failed: {$err} {$out}");
            preg_match('/RESULT:(.+)/', $out, $m);
            $results[] = trim($m[1] ?? 'none');
        }
        @unlink($barrier);

        return $results;
    }

    /** @test */
    public function the_same_settlement_approved_by_many_processes_commits_exactly_once(): void
    {
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 400000);
        $row = $this->cash($this->returnOf($s['lines'][0], 8));

        $results = $this->race(array_fill(0, 4, ['approve', (string) $row->id]));

        $this->assertSame(1, SaleReturnAdjustment::query()->count(), implode(',', $results));
        $this->assertSame(1, SaleReturnPayment::query()->count());
        $this->assertEquals(200000, SaleReturnPayment::query()->sum('amount'));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'approved')));
    }

    /** @test */
    public function two_reviews_racing_for_one_balance_never_over_refund_or_half_commit(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 200]], paid: 200, serial: true);
        $ids = collect($s['lines'][0]['serials'])->pluck('id')->all();
        $a = $this->cash($this->returnOf($s['lines'][0], 1, [$ids[0]]), $ids[0]);
        $b = $this->cash($this->returnOf($s['lines'][0], 1, [$ids[1]]), $ids[1]);

        $this->race([['approve', (string) $a->id], ['approve', (string) $b->id]]);

        $adjustments = SaleReturnAdjustment::query()->get();
        $this->assertLessThanOrEqual(1, $adjustments->count(), 'the second review is stale and needs renewed review');
        $this->assertLessThanOrEqual(200.0, (float) SaleReturnPayment::query()->sum('amount'));
        $this->assertSame($adjustments->count(), SaleReturnPayment::query()->count(), 'payout row iff the adjustment paid cash');
        foreach ($adjustments as $adj) {
            $this->assertEqualsWithDelta((float) $adj->committed_value, (float) $adj->debt_reduction + (float) $adj->cash_refund, 0.001);
        }
    }

    /** @test */
    public function a_payment_racing_a_debt_cancelling_return_never_both_commit(): void
    {
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA SR', 'account_number' => 'ACC-SR-1', 'category' => 'Kas & Bank', 'setting_id' => 1,
        ]);
        $method = PaymentMethod::create(['name' => 'CASH', 'coa_id' => $coaId, 'is_cash' => true]);
        $s = $this->sale([['qty' => 10, 'sub' => 1000000]], paid: 0);
        $row = $this->cash($this->returnOf($s['lines'][0], 8));

        $this->race([
            ['approve', (string) $row->id],
            ['pay', '1', (string) $s['sale']->id, '600000', (string) $method->id],
        ]);

        $sale = Sale::query()->findOrFail($s['sale']->id);
        $adjusted = SaleReturnAdjustment::query()->exists();
        $paid = (float) SalePayment::query()->where('sale_id', $sale->id)->active()->sum('amount');

        $this->assertFalse($adjusted && $paid > 0, 'a 600000 payment on top of 800000 cancelled debt would collect canceled debt');
        $this->assertGreaterThanOrEqual(0.0, $sale->live_due_amount);
        // header equals the projection built from evidence, in whichever order the locks resolved
        $debt = (float) SaleReturnAdjustment::query()->sum('debt_reduction');
        $refund = (float) SaleReturnAdjustment::query()->sum('cash_refund');
        $this->assertEqualsWithDelta(max(0, 1000000 - $paid - $debt), (float) $sale->due_amount, 0.01);
        $this->assertEqualsWithDelta($paid - $refund, (float) $sale->paid_amount, 0.01);
    }

    /** @test (group 4/5 carry-over) two inspectors releasing the same held custody conserve it */
    public function concurrent_releases_of_one_custody_conserve_the_quantity(): void
    {
        $flow = $this->makeDispatchedSale(2);
        $return = $this->makeReturnFor($flow, 2, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()]);
        $return->forceFill(['custody_policy_version' => 1, 'custody_policy_applied_at' => now()])->save();
        DB::transaction(fn () => app(\App\Support\SalesReturn\StandardReturnCustodyService::class)->receive($return->fresh(), now(), $this->user->id));
        $custody = \Modules\SalesReturn\Entities\SaleReturnCustody::query()->sole();
        $this->settlementFor($return->fresh(), Settlement::STATUS_APPROVED, 'CASH_REFUND');

        $results = $this->race([
            ['release', (string) $return->id, (string) $custody->id, '2'],
            ['release', (string) $return->id, (string) $custody->id, '2'],
        ]);

        $fresh = $custody->fresh();
        $this->assertEquals(2, (float) $fresh->quantity_released, implode(',', $results));
        $this->assertEquals(0, $fresh->held_quantity);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'released')));
        $this->assertEquals(2, \Modules\Product\Entities\Product::query()->whereKey($flow['product']->id)->value('product_quantity'), 'sellable stock credited once');
    }
}

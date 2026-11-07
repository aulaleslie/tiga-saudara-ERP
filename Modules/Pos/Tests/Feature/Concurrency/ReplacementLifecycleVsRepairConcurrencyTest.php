<?php

namespace Modules\Pos\Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosOwnerPaymentTransfer;
use Modules\Pos\Entities\PosReplacementAccountingRepairAudit;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReplacementAccountingRepairService;
use Modules\Pos\Tests\Feature\Support\BuildsSyntheticTransaction10717Fixture;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;

/**
 * Full-operation contention on REAL MySQL row locks: real OS processes run the production repair apply()
 * and the production lifecycle approval at the same time (not the scratch lock-order emulation).
 *
 * SCOPE (deliberately narrow): the two competing repairs overlap on the SAME sales, payments, transfers and
 * checkout rows, which is the contention proven here (one applies, the other is refused by fingerprint
 * revalidation under lock). The lifecycle racer works on a SEPARATE checkout/sale/payment group; it shares only
 * the business settings, locations, products and stock rows (asserted below), so it demonstrates absence of
 * deadlock on shared stock/setting rows, NOT contention over the repaired accounting rows. Lifecycle-vs-repair
 * contention over the same sale/payment rows is not covered because the lifecycle refuses an already
 * completed return and the fixture cannot place a pending return on the repaired checkout.
 * Data must be committed for other processes to see it, so the surrounding transaction is disabled and
 * every table is truncated afterwards.
 *
 * @group mysql
 */
class ReplacementLifecycleVsRepairConcurrencyTest extends PosTransactionFeatureTestCase
{
    use BuildsSyntheticTransaction10717Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml).');
        }
    }

    public function beginDatabaseTransaction()
    {
        // Intentionally committed: worker processes must see the fixture.
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql_test') {
            $schema = DB::connection()->getDatabaseName();
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (DB::select('SHOW TABLES') as $row) {
                $table = array_values((array) $row)[0];
                if ($table !== 'migrations') {
                    DB::table($table)->truncate();
                }
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->assertStringEndsWith('_test', $schema);
        }
        parent::tearDown();
    }

    /** @param array<int, array<int, string>> $jobs */
    private function race(array $jobs): array
    {
        $barrier = sys_get_temp_dir() . '/pos_lc_repair_barrier_' . uniqid() . '.lock';
        $procs = $pipes = [];
        foreach ($jobs as $i => $args) {
            $cmd = 'php ' . escapeshellarg(__DIR__ . '/lifecycle_repair_worker.php') . ' '
                . implode(' ', array_map('escapeshellarg', array_merge($args, [$barrier])));
            $procs[$i] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            $this->assertIsResource($procs[$i]);
        }
        usleep(1500000); // let every worker boot and wait on the barrier
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
    public function repair_and_lifecycle_race_without_deadlock_duplicate_evidence_or_fingerprint_bypass(): void
    {
        $stale = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $pending = $this->buildSyntheticTransaction10717Fixture('pending_approval', [
            'setting_a' => $stale['setting_a'], 'terminal_a' => $stale['terminal_a'], 'location_a' => $stale['location_a'],
            'setting_b' => $stale['setting_b'], 'location_b' => $stale['location_b'],
            'setting_unrelated' => $stale['setting_unrelated'], 'location_unrelated' => $stale['location_unrelated'],
            'cashier' => $stale['cashier'], 'session' => $stale['session'],
            'original_serial' => 'XE4Q304991', 'replacement_serial' => 'XE4Q318992',
        ]);

        // Measure the real overlap instead of claiming it.
        $groupSales = fn (array $f) => array_values(array_filter([$f['source_sale']->id, $f['replacement_sale']->id ?? null]));
        $this->assertSame([], array_intersect($groupSales($stale), $groupSales($pending)), 'accounting rows are NOT shared between the two groups');

        $settingId = $stale['setting_a']->id;
        $actor = $stale['cashier']->id;
        $plan = app(PosReplacementAccountingRepairService::class)->plan($stale['transaction']->id, $settingId);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_REPAIRABLE, $plan['classification']);
        $fingerprint = $plan['evidence_fingerprint'];
        $staleTransferCount = PosOwnerPaymentTransfer::query()->count();

        $repair = ['repair', (string) $actor, (string) $settingId, (string) $stale['transaction']->id, $fingerprint];
        $results = $this->race([
            $repair,
            $repair, // second repair with the SAME reviewed fingerprint must not apply twice
            ['lifecycle', (string) $actor, (string) $settingId, (string) $pending['pos_return']->id],
        ]);

        $summary = implode(' | ', $results);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'applied:repaired')), 'exactly one repair applies: ' . $summary);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'lifecycle-ok')), 'lifecycle approval completes: ' . $summary);
        // The losing repair must be refused by fingerprint revalidation (before or under lock) - never a deadlock or other error.
        $refusals = array_values(array_filter($results, fn ($r) => str_starts_with($r, 'refused:')));
        $this->assertCount(1, $refusals, $summary);
        $this->assertMatchesRegularExpression(
            '/^refused:RuntimeException:(Evidence fingerprint mismatch before lock!|Evidence changed under lock!)/',
            $refusals[0],
            $summary
        );
        $this->assertStringNotContainsString('Deadlock', $summary);
        $this->assertContains('lifecycle-ok', $results, 'the lifecycle approval must complete: ' . implode(' | ', $results));

        $this->assertSame(1, PosReplacementAccountingRepairAudit::query()->where('pos_transaction_id', $stale['transaction']->id)->count());
        $this->assertSame(PosReturn::STATUS_COMPLETED, $pending['pos_return']->fresh()->status);

        // Re-running preview on the repaired transaction is a no-op; the reviewed fingerprint is spent.
        $after = app(PosReplacementAccountingRepairService::class)->plan($stale['transaction']->id, $settingId);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_UNCHANGED, $after['classification']);
        $transfersAfter = PosOwnerPaymentTransfer::query()->count();
        $this->assertGreaterThan($staleTransferCount, $transfersAfter);
        $this->assertSame($transfersAfter, PosOwnerPaymentTransfer::query()->distinct('execution_key')->count('execution_key'), 'no duplicate execution keys');
    }
}

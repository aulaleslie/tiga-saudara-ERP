<?php

namespace Tests\Feature\SalesReturn\Concurrency;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Tests\Feature\Support\BuildsPosSerialRegistrationFixture;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;

/**
 * Task 6.2 (POS side): competing POS approvals / claims against REAL MySQL row locks and unique indexes,
 * with real OS processes (see pos_registration_worker.php). Data must be committed so workers can see it,
 * hence truncation instead of the usual wrapping transaction.
 *
 * @group mysql
 */
class PosRegistrationConcurrencyTest extends PosTransactionFeatureTestCase
{
    use BuildsPosSerialRegistrationFixture;

    protected function refreshTestDatabase()
    {
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;

            return;
        }

        Schema::disableForeignKeyConstraints();
        foreach (DB::connection()->getDoctrineSchemaManager()->listTableNames() as $table) {
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    /** @param array<int, array<int, string>> $jobs argument lists (mode first), one process each */
    private function race(array $jobs): array
    {
        $barrier = sys_get_temp_dir() . '/sr_pos_registration_barrier_' . uniqid() . '.lock';
        $procs = $pipes = [];
        foreach ($jobs as $i => $args) {
            $cmd = 'php ' . escapeshellarg(__DIR__ . '/pos_registration_worker.php') . ' '
                . implode(' ', array_map('escapeshellarg', array_merge([$args[0], (string) $this->user->id, (string) $this->setting->id], array_slice($args, 1), [$barrier])));
            $procs[$i] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            $this->assertIsResource($procs[$i]);
        }
        usleep(800000);
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
    public function two_pos_approvals_registering_the_same_serial_commit_exactly_one_registration(): void
    {
        $f = $this->fixture();
        $a = $this->submitted($f, [$this->pendingLine($f, 'dup-1')]);
        $b = $this->submitted($f, [$this->pendingLine($f, 'dup-1'), $this->pendingLine($f, 'b-only')]);

        $results = $this->race([['approve', (string) $a->id], ['approve', (string) $b->id]]);
        $summary = implode(' | ', $results);

        $this->assertSame(1, ProductSerialNumber::query()->where('serial_number', 'DUP-1')->count(), $summary);
        $completed = PosReturn::query()->where('status', PosReturn::STATUS_COMPLETED)->get();
        $this->assertCount(1, $completed, "exactly one approval wins: {$summary}");
        $loser = PosReturn::query()->where('status', '!=', PosReturn::STATUS_COMPLETED)->sole();
        $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $loser->status, "the loser rolled back completely: {$summary}");
        $this->assertSame(
            $completed->first()->lines()->count(),
            ProductSerialNumber::query()->count(),
            'only the winner\'s serials exist (no half registration)'
        );
        $this->assertSame(
            ProductSerialNumber::query()->count(),
            ReturnSerialRegistration::query()->whereNotNull('registered_serial_id')->count()
        );
        $this->assertSame(
            $loser->lines()->count(),
            ReturnSerialRegistration::query()->where('pos_return_id', $loser->id)->whereNull('registered_serial_id')->count(),
            'loser keeps its proposed text'
        );
    }

    /** @test */
    public function pos_approval_racing_a_purchase_style_creator_of_the_same_serial_never_duplicates_or_half_commits(): void
    {
        $f = $this->fixture();
        $return = $this->submitted($f, [$this->pendingLine($f, 'race-1'), $this->pendingLine($f, 'race-2')]);

        $results = $this->race([
            ['approve', (string) $return->id],
            ['serial', (string) $f['product']->id, (string) $this->location->id, 'RACE-1'],
        ]);
        $summary = implode(' | ', $results);

        $this->assertSame(1, ProductSerialNumber::query()->where('serial_number', 'RACE-1')->count(), $summary);
        if ($return->fresh()->status === PosReturn::STATUS_COMPLETED) {
            $this->assertContains('duplicate', $results, 'the creator lost the unique index');
            $this->assertSame(2, ReturnSerialRegistration::query()->whereNotNull('registered_serial_id')->count());
        } else {
            $this->assertSame(PosReturn::STATUS_PENDING_APPROVAL, $return->fresh()->status, $summary);
            $this->assertSame(1, ProductSerialNumber::query()->count(), 'approval rolled back; only the competing creator exists');
            $this->assertSame(0, ReturnSerialRegistration::query()->whereNotNull('registered_serial_id')->count());
        }
    }

    /** @test */
    public function competing_pos_submissions_cannot_claim_more_units_than_the_source_has(): void
    {
        $f = $this->fixture();
        $a = $this->store($f, [$this->pendingLine($f, 'x-1'), $this->pendingLine($f, 'x-2')]);
        $b = $this->store($f, [$this->pendingLine($f, 'y-1'), $this->pendingLine($f, 'y-2')]);

        $results = $this->race([['submit', (string) $a->id], ['submit', (string) $b->id]]);
        $summary = implode(' | ', $results);

        $submitted = PosReturn::query()->where('status', PosReturn::STATUS_PENDING_APPROVAL)->get();
        $claimed = PosReturnLine::query()->whereIn('pos_return_id', $submitted->pluck('id'))->sum('quantity');
        $this->assertLessThanOrEqual(3, (int) $claimed, "committed claims never exceed the source quantity: {$summary}");
        $this->assertSame(0, ProductSerialNumber::query()->count(), 'submission never creates serial inventory');
    }

    /** @test */
    public function competing_draft_saves_cannot_both_reserve_the_same_replacement_serial(): void
    {
        $f = $this->fixture();
        $replacement = $this->createSerialNumber($f['product'], $this->location, 'REPL-RACE');
        $args = fn (string $text) => ['store', (string) $f['transaction']->id, (string) $f['saleDetail']->id, (string) $f['sale']->id, $text, (string) $replacement->id];

        $results = $this->race([$args('rc-a'), $args('rc-b')]);
        $summary = implode(' | ', $results);

        $reserving = PosReturnLine::query()->where('replacement_serial_id', $replacement->id)
            ->whereHas('posReturn', fn ($q) => $q->where('status', PosReturn::STATUS_DRAFT))->count();
        $this->assertSame(1, $reserving, "exactly one draft may hold the replacement serial: {$summary}");
        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'stored'))), $summary);
        $this->assertSame(0, ProductSerialNumber::where('serial_number', 'RC-A')->orWhere('serial_number', 'RC-B')->count(), 'drafts register nothing');
    }

    /** @test */
    public function two_approvals_holding_the_same_replacement_serial_never_both_dispatch_it(): void
    {
        // Reached by forcing two pending returns onto one replacement serial (a state the draft guard prevents), to prove
        // the approval path itself is safe even if that guard were ever bypassed.
        $f = $this->fixture();
        $replacement = $this->createSerialNumber($f['product'], $this->location, 'REPL-APPR');
        $a = $this->submitted($f, [$this->pendingLine($f, 'ap-a', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, ['replacement_serial_id' => $replacement->id])]);
        $second = $this->createSerialNumber($f['product'], $this->location, 'REPL-APPR-2');
        $b = $this->submitted($f, [$this->pendingLine($f, 'ap-b', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, ['replacement_serial_id' => $second->id])]);
        PosReturnLine::query()->where('pos_return_id', $b->id)->update(['replacement_serial_id' => $replacement->id]);

        $results = $this->race([['approve', (string) $a->id], ['approve', (string) $b->id]]);
        $summary = implode(' | ', $results);

        $dispatches = \Modules\Sale\Entities\DispatchDetail::query()->whereNotNull('replacement_returned_serial_id')->count();
        $this->assertLessThanOrEqual(1, $dispatches, "the replacement serial is never dispatched twice: {$summary}");
        $this->assertSame($dispatches === 1 ? ProductSerialNumber::STATUS_SOLD : ProductSerialNumber::STATUS_ACTIVE, $replacement->fresh()->status, $summary);
        $this->assertLessThanOrEqual(1, PosReturn::query()->where('status', PosReturn::STATUS_COMPLETED)->count(), $summary);
    }
}

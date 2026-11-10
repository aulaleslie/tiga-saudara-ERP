<?php

namespace Modules\Consignment\Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Real-OS-process race plumbing shared by the MySQL buyout race tests. Expects $this->user (the acting user, who also
 * holds the needed permissions) and $this->setting (the business) to be set by the using test.
 */
trait RunsBuyoutRaceWorkers
{
    protected function truncateAll(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array<int, array<int, string>> $jobs each = [mode, id, extra...] */
    protected function race(array $jobs, array $delaysMs = []): array
    {
        $barrier = sys_get_temp_dir() . '/buyout_race_barrier_' . uniqid() . '.lock';
        $procs = $pipes = [];
        foreach ($jobs as $i => $args) {
            [$mode, $id] = $args;
            $extra = array_slice($args, 2);
            $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/Feature/Concurrency/buyout_race_worker.php') . ' ' . implode(' ', array_map('escapeshellarg',
                array_merge([$mode, (string) $this->user->id, (string) $this->setting->id, (string) $id], $extra, [$barrier])));
            $procs[$i] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, ['MYSQL_TEST_PORT' => '33066', 'RACE_DELAY_MS' => (string) ($delaysMs[$i] ?? 0)] + getenv());
            $this->assertIsResource($procs[$i]);
        }
        usleep(2500000); // every worker boots and spins on the barrier
        touch($barrier);

        $results = [];
        foreach ($jobs as $i => $_) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            array_map('fclose', $pipes[$i]);
            $code = proc_close($procs[$i]);
            $this->assertSame(0, $code, "worker {$i} crashed: {$err} {$out}");
            preg_match('/RESULT:(.+)/', $out, $m);
            $results[] = trim($m[1] ?? 'none');
        }
        @unlink($barrier);
        $this->assertStringNotContainsString('Deadlock', implode('|', $results), 'deadlock surfaced to a caller');
        $this->assertStringNotContainsString('Lock wait timeout', implode('|', $results));

        return $results;
    }

    protected function ok(array $results): int
    {
        return count(array_filter($results, fn ($r) => str_starts_with($r, 'ok')));
    }

}

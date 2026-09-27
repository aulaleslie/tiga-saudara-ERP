<?php

namespace Tests\Feature\Services\Sequence\Concurrency;

use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Services\AdjustmentReferenceService;
use Tests\TestCase;

/**
 * Genuine multi-process concurrency coverage for AdjustmentReferenceService,
 * following the same real-connection pattern SequenceConcurrencyWorkerTest
 * uses for Purchase/Sale: each worker is a SEPARATE PHP process with its own
 * database connection, racing to allocate a reference in the same
 * prefix/year/month namespace. Two sequential transactions in a single
 * process/connection (as an earlier version of this test suite used) do not
 * exercise the FOR UPDATE row lock at all, since nothing is actually
 * contending for it -- only overlapping transactions from independent
 * connections can prove allocate()'s lock genuinely serializes concurrent
 * allocators rather than merely appearing correct under non-overlapping
 * calls.
 *
 * @group mysql
 */
class AdjustmentReferenceConcurrencyWorkerTest extends TestCase
{
    private string $workerScriptPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerScriptPath = base_path('tests/Feature/Services/Sequence/Concurrency/adjustment_reference_worker.php');
    }

    public function test_concurrent_breakage_reference_allocations_across_processes_are_unique_and_monotonic(): void
    {
        $locationId = $this->createLocationFixture('Adjustment Concurrency Warehouse');

        $prefix = AdjustmentReferenceService::PREFIX_BREAKAGE;
        $year = (int) date('Y');
        $month = (int) date('m');

        $barrierFile = sys_get_temp_dir() . '/adj_ref_barrier_' . uniqid() . '.lock';
        if (file_exists($barrierFile)) {
            unlink($barrierFile);
        }

        $numWorkers = 4;
        $iterationsPerWorker = 10;
        $processes = [];
        $pipes = [];

        for ($i = 0; $i < $numWorkers; $i++) {
            $cmd = sprintf(
                'php %s %s %s %s %s %s %s',
                escapeshellarg($this->workerScriptPath),
                escapeshellarg($prefix),
                escapeshellarg((string) $locationId),
                escapeshellarg((string) $year),
                escapeshellarg((string) $month),
                escapeshellarg((string) $iterationsPerWorker),
                escapeshellarg($barrierFile)
            );

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open($cmd, $descriptors, $pipes[$i]);
            $this->assertIsResource($proc);
            $processes[$i] = $proc;
        }

        // Release the barrier once all workers have had a chance to reach it,
        // so their allocation attempts genuinely overlap in time rather than
        // running strictly one after another.
        usleep(50000);
        touch($barrierFile);

        $allAllocatedReferences = [];
        for ($i = 0; $i < $numWorkers; $i++) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][0]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $exitCode = proc_close($processes[$i]);

            $this->assertSame(0, $exitCode, "Worker {$i} failed with stderr: {$stderr}");

            $lines = array_filter(explode("\n", trim($stdout)));
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'REF:')) {
                    $allAllocatedReferences[] = substr($trimmed, 4);
                }
            }
        }
        @unlink($barrierFile);

        $expectedTotal = $numWorkers * $iterationsPerWorker;

        // Every worker process actually produced a reference (no silent
        // failures/timeouts collapsed into an empty stdout).
        $this->assertCount($expectedTotal, $allAllocatedReferences);

        // The core assertion this test exists for: despite N processes each
        // holding their own database connection and racing to allocate in
        // the exact same prefix/year/month namespace, not one reference is
        // duplicated. This is only possible because allocate()'s FOR UPDATE
        // lock on the counter row genuinely blocks concurrent transactions
        // from another connection, not just from another call on the same
        // connection.
        $this->assertCount($expectedTotal, array_unique($allAllocatedReferences), 'Concurrent workers produced duplicate references.');

        $counterRow = DB::connection('mysql_test')->table('adjustment_reference_sequences')
            ->where('prefix', $prefix)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        $this->assertNotNull($counterRow);
        $this->assertEquals($expectedTotal, $counterRow->last_number);

        $insertedCount = DB::connection('mysql_test')->table('adjustments')
            ->where('reference', 'like', $prefix . '-%')
            ->count();
        $this->assertEquals($expectedTotal, $insertedCount);
    }

    private function createLocationFixture(string $name): int
    {
        $currencyId = DB::connection('mysql_test')->table('currencies')->insertGetId([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settingId = (int) DB::connection('mysql_test')->table('settings')->insertGetId([
            'company_name' => $name,
            'company_email' => 'store.' . uniqid() . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Test Address',
            'default_currency_id' => $currencyId,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'is_pkp' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::connection('mysql_test')->table('locations')->insertGetId([
            'setting_id' => $settingId,
            'name' => 'Gudang Konkurensi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

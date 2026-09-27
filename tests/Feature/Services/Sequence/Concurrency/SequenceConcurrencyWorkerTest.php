<?php

namespace Tests\Feature\Services\Sequence\Concurrency;

use App\Services\Sequence\DocumentSequence;
use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use App\Services\Sequence\SequenceNamespace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @group mysql
 */
class SequenceConcurrencyWorkerTest extends TestCase
{
    private string $workerScriptPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerScriptPath = base_path('tests/Feature/Services/Sequence/Concurrency/worker.php');
    }

    public function test_concurrent_purchase_allocations_and_insertions_are_monotonic(): void
    {
        $settingId = $this->createSettingFixture('Purchase Concurrency Store');
        $this->createSupplierFixture($settingId);

        $prefix = 'TS-PO';
        $year = (int) date('Y');
        $month = (int) date('m');

        // Pre-create sequence row as done in migration bootstrap
        DB::connection('mysql_test')->table('document_sequences')->insert([
            'document_type' => 'purchase',
            'setting_id' => $settingId,
            'prefix' => $prefix,
            'period_year' => $year,
            'period_month' => $month,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $barrierFile = sys_get_temp_dir() . '/seq_barrier_p_' . uniqid() . '.lock';
        if (file_exists($barrierFile)) {
            unlink($barrierFile);
        }

        $numWorkers = 4;
        $iterationsPerWorker = 10;
        $processes = [];
        $pipes = [];

        for ($i = 0; $i < $numWorkers; $i++) {
            $cmd = sprintf(
                'php %s %s %s %s %s %s %s %s',
                escapeshellarg($this->workerScriptPath),
                escapeshellarg('purchase'),
                escapeshellarg((string) $settingId),
                escapeshellarg($prefix),
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

        // Release barrier for all workers
        usleep(50000); // 50ms for workers to reach barrier
        touch($barrierFile);

        $allAllocatedReferences = [];
        for ($i = 0; $i < $numWorkers; $i++) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][0]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $exitCode = proc_close($processes[$i]);

            $this->assertSame(0, $exitCode, "Purchase Worker {$i} failed with stderr: {$stderr}");

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
        $this->assertCount($expectedTotal, $allAllocatedReferences);
        $this->assertCount($expectedTotal, array_unique($allAllocatedReferences));

        $counterRow = DB::connection('mysql_test')->table('document_sequences')
            ->where('document_type', 'purchase')
            ->where('setting_id', $settingId)
            ->where('prefix', $prefix)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        $this->assertNotNull($counterRow);
        $this->assertEquals($expectedTotal, $counterRow->last_number);

        $insertedCount = DB::connection('mysql_test')->table('purchases')
            ->where('setting_id', $settingId)
            ->count();
        $this->assertEquals($expectedTotal, $insertedCount);
    }

    public function test_concurrent_sale_allocations_and_insertions_are_monotonic(): void
    {
        $settingId = $this->createSettingFixture('Sale Concurrency Store');
        $this->createCustomerFixture($settingId);

        $prefix = 'TS-SO';
        $year = (int) date('Y');
        $month = (int) date('m');

        // Pre-create sequence row as done in migration bootstrap
        DB::connection('mysql_test')->table('document_sequences')->insert([
            'document_type' => 'sale',
            'setting_id' => $settingId,
            'prefix' => $prefix,
            'period_year' => $year,
            'period_month' => $month,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $barrierFile = sys_get_temp_dir() . '/seq_barrier_s_' . uniqid() . '.lock';
        if (file_exists($barrierFile)) {
            unlink($barrierFile);
        }

        $numWorkers = 4;
        $iterationsPerWorker = 10;
        $processes = [];
        $pipes = [];

        for ($i = 0; $i < $numWorkers; $i++) {
            $cmd = sprintf(
                'php %s %s %s %s %s %s %s %s',
                escapeshellarg($this->workerScriptPath),
                escapeshellarg('sale'),
                escapeshellarg((string) $settingId),
                escapeshellarg($prefix),
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

        usleep(50000); // 50ms for workers to reach barrier
        touch($barrierFile);

        $allAllocatedReferences = [];
        for ($i = 0; $i < $numWorkers; $i++) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][0]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $exitCode = proc_close($processes[$i]);

            $this->assertSame(0, $exitCode, "Sale Worker {$i} failed with stderr: {$stderr}");

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
        $this->assertCount($expectedTotal, $allAllocatedReferences);
        $this->assertCount($expectedTotal, array_unique($allAllocatedReferences));

        $counterRow = DB::connection('mysql_test')->table('document_sequences')
            ->where('document_type', 'sale')
            ->where('setting_id', $settingId)
            ->where('prefix', $prefix)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        $this->assertNotNull($counterRow);
        $this->assertEquals($expectedTotal, $counterRow->last_number);

        $insertedCount = DB::connection('mysql_test')->table('sales')
            ->where('setting_id', $settingId)
            ->count();
        $this->assertEquals($expectedTotal, $insertedCount);
    }

    public function test_concurrent_pos_split_allocations_and_canonical_locking_are_deadlock_free(): void
    {
        $settingA = $this->createSettingFixture('POS Store A', 'PD-S1', 'SL');
        $settingB = $this->createSettingFixture('POS Store B', 'PD-S2', 'SL');
        $this->createCustomerFixture($settingA);

        $prefixA = 'PD-S1-SL';
        $prefixB = 'PD-S2-SL';
        $year = (int) date('Y');
        $month = (int) date('m');

        // Pre-create sequence rows as done in migration bootstrap
        DB::connection('mysql_test')->table('document_sequences')->insert([
            [
                'document_type' => 'sale',
                'setting_id' => $settingA,
                'prefix' => $prefixA,
                'period_year' => $year,
                'period_month' => $month,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'document_type' => 'sale',
                'setting_id' => $settingB,
                'prefix' => $prefixB,
                'period_year' => $year,
                'period_month' => $month,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $barrierFile = sys_get_temp_dir() . '/seq_barrier_pos_' . uniqid() . '.lock';
        if (file_exists($barrierFile)) {
            unlink($barrierFile);
        }

        $numWorkers = 4;
        $iterationsPerWorker = 8;
        $processes = [];
        $pipes = [];

        // Launch concurrent workers supplying namespaces in alternating opposite order [A, B] vs [B, A]
        for ($i = 0; $i < $numWorkers; $i++) {
            $reverseOrder = ($i % 2 === 1) ? '1' : '0';
            $cmd = sprintf(
                'php %s %s %s %s %s %s %s %s %s %s %s',
                escapeshellarg($this->workerScriptPath),
                escapeshellarg('pos_split'),
                escapeshellarg((string) $settingA),
                escapeshellarg((string) $settingB),
                escapeshellarg($prefixA),
                escapeshellarg($prefixB),
                escapeshellarg((string) $year),
                escapeshellarg((string) $month),
                escapeshellarg((string) $iterationsPerWorker),
                escapeshellarg($reverseOrder),
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

            $this->assertSame(0, $exitCode, "POS Split Worker {$i} failed with stderr: {$stderr}");

            $lines = array_filter(explode("\n", trim($stdout)));
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'REF:')) {
                    $allAllocatedReferences[] = substr($trimmed, 4);
                }
            }
        }
        @unlink($barrierFile);

        $expectedAllocationsPerSetting = $numWorkers * $iterationsPerWorker;
        $expectedTotalReferences = $expectedAllocationsPerSetting * 2;
        $this->assertCount($expectedTotalReferences, $allAllocatedReferences);
        $this->assertCount($expectedTotalReferences, array_unique($allAllocatedReferences));

        // Verify sequence counters for both setting namespaces
        $counterRowA = DB::connection('mysql_test')->table('document_sequences')
            ->where('document_type', 'sale')
            ->where('setting_id', $settingA)
            ->where('prefix', $prefixA)
            ->first();
        $this->assertNotNull($counterRowA);
        $this->assertEquals($expectedAllocationsPerSetting, $counterRowA->last_number);

        $counterRowB = DB::connection('mysql_test')->table('document_sequences')
            ->where('document_type', 'sale')
            ->where('setting_id', $settingB)
            ->where('prefix', $prefixB)
            ->first();
        $this->assertNotNull($counterRowB);
        $this->assertEquals($expectedAllocationsPerSetting, $counterRowB->last_number);
    }

    public function test_concurrent_missing_sequence_row_initialization_retries_deadlocks_and_succeeds(): void
    {
        $settingId = $this->createSettingFixture('Missing Row Concurrency Store');
        $this->createSupplierFixture($settingId);

        $prefix = 'TS-PO';
        $year = (int) date('Y');
        $month = (int) date('m');

        // Note: Do NOT pre-create the sequence row. Workers will race to create it simultaneously.
        $this->assertDatabaseMissing('document_sequences', [
            'document_type' => 'purchase',
            'setting_id' => $settingId,
            'prefix' => $prefix,
            'period_year' => $year,
            'period_month' => $month,
        ], 'mysql_test');

        $barrierFile = sys_get_temp_dir() . '/seq_barrier_missing_' . uniqid() . '.lock';
        if (file_exists($barrierFile)) {
            unlink($barrierFile);
        }

        $numWorkers = 6;
        $iterationsPerWorker = 5;
        $processes = [];
        $pipes = [];

        for ($i = 0; $i < $numWorkers; $i++) {
            $cmd = sprintf(
                'php %s %s %s %s %s %s %s %s',
                escapeshellarg($this->workerScriptPath),
                escapeshellarg('purchase'),
                escapeshellarg((string) $settingId),
                escapeshellarg($prefix),
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

        // Release barrier simultaneously so all workers race on initial missing row
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

            $this->assertSame(0, $exitCode, "Missing-row Worker {$i} failed with stderr: {$stderr}");

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
        $this->assertCount($expectedTotal, $allAllocatedReferences);
        $this->assertCount($expectedTotal, array_unique($allAllocatedReferences));

        $counterRow = DB::connection('mysql_test')->table('document_sequences')
            ->where('document_type', 'purchase')
            ->where('setting_id', $settingId)
            ->where('prefix', $prefix)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        $this->assertNotNull($counterRow);
        $this->assertEquals($expectedTotal, $counterRow->last_number);

        $insertedCount = DB::connection('mysql_test')->table('purchases')
            ->where('setting_id', $settingId)
            ->count();
        $this->assertEquals($expectedTotal, $insertedCount);
    }

    private function createSettingFixture(string $name, string $docPrefix = 'TS', string $salePrefix = 'SO'): int
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

        return (int) DB::connection('mysql_test')->table('settings')->insertGetId([
            'company_name' => $name,
            'company_email' => 'store.' . uniqid() . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Test Address',
            'default_currency_id' => $currencyId,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => $docPrefix,
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => $salePrefix,
            'pos_enabled' => true,
            'is_pkp' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSupplierFixture(int $settingId): void
    {
        $existing = DB::connection('mysql_test')->table('suppliers')->where('id', 1)->first();
        if (!$existing) {
            DB::connection('mysql_test')->table('suppliers')->insert([
                'id' => 1,
                'setting_id' => $settingId,
                'supplier_name' => 'Test Supplier',
                'supplier_email' => 'supplier@example.com',
                'supplier_phone' => '0812345678',
                'city' => 'Jakarta',
                'country' => 'Indonesia',
                'address' => 'Test Supplier Address',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createCustomerFixture(int $settingId): void
    {
        $existing = DB::connection('mysql_test')->table('customers')->where('id', 1)->first();
        if (!$existing) {
            DB::connection('mysql_test')->table('customers')->insert([
                'id' => 1,
                'setting_id' => $settingId,
                'customer_name' => 'Test Customer',
                'customer_email' => 'customer@example.com',
                'customer_phone' => '0812345678',
                'city' => 'Jakarta',
                'country' => 'Indonesia',
                'address' => 'Test Customer Address',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

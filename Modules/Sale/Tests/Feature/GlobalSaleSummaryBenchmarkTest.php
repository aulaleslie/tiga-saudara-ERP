<?php

namespace Modules\Sale\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Livewire\SaleSummaryCards;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Matched before/after measurement for quicken-global-payment-lists.
 * Prints SQL count/time, elapsed and peak memory for the global Sales summary (and card selection)
 * at 10 and 100 eligible Sales; asserts only that summary query count does not grow per Sale.
 */
class GlobalSaleSummaryBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;
    private int $settingId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00'));

        $setting = Setting::factory()->create(['company_name' => 'Bench']);
        $this->settingId = $setting->id;
        session(['setting_id' => $setting->id]);
        $this->customerId = Customer::factory()->create(['setting_id' => $setting->id])->id;
        $this->actingAs($this->globalUser());
    }

    private function globalUser()
    {
        $user = \App\Models\User::factory()->create();
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        return $user;
    }

    private function seedSales(int $count): void
    {
        static $next = 0;
        for ($n = 0; $n < $count; $n++) {
            $i = $next++;
            $sale = Sale::create([
                'date' => now()->subDays($i % 40),
                'due_date' => now()->subDays(($i % 7) - 3),
                'reference' => 'BENCH-' . $i,
                'customer_name' => 'Bench Customer',
                'customer_id' => $this->customerId,
                'payment_method' => 'Cash',
                'status' => Sale::STATUS_DISPATCHED,
                'payment_status' => 'PARTIAL',
                'total_amount' => 1000,
                'paid_amount' => 0,
                'due_amount' => 1000,
                'setting_id' => $this->settingId,
            ]);
            SalePayment::create([
                'sale_id' => $sale->id,
                'amount' => $i % 3 === 0 ? 1000 : 250,
                'date' => now()->subDays($i % 20),
                'reference' => 'BP-' . $i,
                'status' => SalePayment::STATUS_ACTIVE,
                'payment_method' => 'Cash',
            ]);
        }
    }

    /** @return array{queries:int, sql_ms:float, elapsed_ms:float, peak_mb:float} */
    private function measure(callable $work): array
    {
        $work(); // warmup
        $samples = [];
        for ($s = 0; $s < 3; $s++) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            memory_reset_peak_usage();
            $start = hrtime(true);
            $work();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $log = DB::getQueryLog();
            DB::disableQueryLog();
            $samples[] = [
                'queries' => count($log),
                'sql_ms' => array_sum(array_column($log, 'time')),
                'elapsed_ms' => $elapsed,
                'peak_mb' => memory_get_peak_usage() / 1048576,
            ];
        }
        usort($samples, fn ($a, $b) => $a['elapsed_ms'] <=> $b['elapsed_ms']);

        return $samples[1]; // median by elapsed
    }

    private function summaryWork(): callable
    {
        return function () {
            $c = new SaleSummaryCards();
            $c->mount(globalMode: true);
            if (method_exists($c, 'loadSummary')) {
                $c->loadSummary();
            }
            $c->getPiutangBelumTertagihProperty();
            $c->getPiutangTelatProperty();
            $c->getPenerimaanProperty();
        };
    }

    private function selectionWork(): callable
    {
        return function () {
            static $c = null;
            if ($c === null) {
                $c = new SaleSummaryCards();
                $c->mount(globalMode: true);
                if (method_exists($c, 'loadSummary')) {
                    $c->loadSummary();
                }
            }
            DB::flushQueryLog();
            $c->toggleCardFilter('unpaid');
            $c->getPiutangBelumTertagihProperty();
            $c->getPiutangTelatProperty();
            $c->getPenerimaanProperty();
        };
    }

    public function test_summary_query_count_does_not_grow_per_sale(): void
    {
        $results = [];
        $seeded = 0;
        foreach ([10, 100] as $size) {
            $this->seedSales($size - $seeded);
            $seeded = $size;
            $results[$size] = [
                'summary' => $this->measure($this->summaryWork()),
                'selection' => $this->measure($this->selectionWork()),
            ];
        }

        foreach ($results as $size => $phases) {
            foreach ($phases as $phase => $m) {
                fwrite(STDERR, sprintf(
                    "BENCH size=%d phase=%s queries=%d sql_ms=%.2f elapsed_ms=%.2f peak_mb=%.2f\n",
                    $size, $phase, $m['queries'], $m['sql_ms'], $m['elapsed_ms'], $m['peak_mb']
                ));
            }
        }

        if (getenv('BENCH_BASELINE')) {
            $this->assertTrue(true);

            return;
        }

        $this->assertSame($results[10]['summary']['queries'], $results[100]['summary']['queries']);
        $this->assertSame(0, $results[100]['selection']['queries']);
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sale\Entities\PosDraft;

class PosBenchmarkCommand extends Command
{
    protected $signature = 'pos:benchmark
        {--setting_id= : Batasi benchmark ke setting tertentu}
        {--iterations=200 : Jumlah iterasi lookup}
        {--sample=50 : Jumlah draft sample untuk random lookup}';

    protected $description = 'Run non-blocking POS draft lookup benchmark and print p95 report.';

    public function handle(): int
    {
        $settingId = $this->option('setting_id');
        $iterations = max(1, (int) $this->option('iterations'));
        $sampleSize = max(1, (int) $this->option('sample'));

        $sampleQuery = PosDraft::query()
            ->select(['id', 'setting_id', 'document_number'])
            ->when($settingId, fn ($query) => $query->where('setting_id', (int) $settingId))
            ->latest('id')
            ->limit($sampleSize);

        $sample = $sampleQuery->get();

        if ($sample->isEmpty()) {
            $this->warn('Tidak ada POS draft untuk dibenchmark.');
            return self::SUCCESS;
        }

        $durationsMs = [];
        $sampleCount = $sample->count();

        for ($i = 0; $i < $iterations; $i++) {
            $target = $sample[$i % $sampleCount];

            $started = microtime(true);
            PosDraft::query()
                ->where('setting_id', $target->setting_id)
                ->where('document_number', $target->document_number)
                ->first();
            $ended = microtime(true);

            $durationsMs[] = ($ended - $started) * 1000;
        }

        sort($durationsMs);

        $avg = array_sum($durationsMs) / count($durationsMs);
        $p95Index = (int) floor((count($durationsMs) - 1) * 0.95);
        $p95 = $durationsMs[$p95Index] ?? end($durationsMs);
        $max = max($durationsMs);

        $report = [
            'iterations' => $iterations,
            'sample_size' => $sampleCount,
            'setting_id' => $settingId ? (int) $settingId : null,
            'avg_ms' => round($avg, 3),
            'p95_ms' => round((float) $p95, 3),
            'max_ms' => round($max, 3),
            'timestamp' => now()->toIso8601String(),
        ];

        $this->info('POS benchmark report:');
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

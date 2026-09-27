<?php

namespace Modules\Sale\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Sale\Support\BackfillCostCalculator;
use Modules\Purchase\Services\HistoricalReplayEngine;
use Carbon\Carbon;

class BackfillSalesCostSnapshotsCommand extends Command
{
    protected $signature = 'sales:backfill-cost-snapshots 
                            {--write : Write the computed snapshots to the database}
                            {--force : Recompute and overwrite existing snapshots}
                            {--product= : Limit to a specific product ID}
                            {--setting= : Limit to a specific setting ID}
                            {--start= : Start date (YYYY-MM-DD)}
                            {--end= : End date (YYYY-MM-DD)}';

    protected $description = 'Backfill historical sales cost snapshots using effective-date purchase replay';

    protected $summary = [
        'scanned' => 0,
        'fillable' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'skipped' => 0,
        'missing_product_price' => 0,
        'negative_stock' => 0,
        'archived_skipped' => 0,
        'future_purchase_fallback' => 0,
        'no_purchase_fallback' => 0,
        'non_stock_zero' => 0,
        'missing_receipt_data' => 0,
        'suspicious_unit_cost' => 0,
    ];

    protected $suspiciousWarnings = [];
    protected $updateBatch = [];
    protected $batchSize = 500;

    public function handle()
    {
        $isDryRun = !$this->option('write');
        $force = $this->option('force');
        $productId = $this->option('product');
        $settingId = $this->option('setting');
        $startDate = $this->option('start');
        $endDate = $this->option('end');

        if ($isDryRun) {
            $this->info("Starting backfill in DRY RUN mode. No data will be written.");
        } else {
            $this->warn("Starting backfill in WRITE mode. Data will be modified.");
        }

        $query = Product::withoutEagerLoads()->select('id', 'product_code', 'stock_managed');
        if ($productId) {
            $query->where('id', $productId);
        }

        $count = $query->count();
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $settings = \Modules\Setting\Entities\Setting::all()->keyBy('id');

        $query->chunk(100, function ($products) use ($bar, $settingId, $isDryRun, $startDate, $endDate, $force, $settings) {
            foreach ($products as $product) {
                $this->processProduct($product, $settingId, $startDate, $endDate, $isDryRun, $force, $settings);
                $bar->advance();
            }
        });

        $this->flushUpdates(); // Ensure remaining batch is written

        $bar->finish();
        $this->line('');
        $this->info("Backfill completed.");

        $this->table(
            ['Metric', 'Count'],
            collect($this->summary)->map(fn($val, $key) => [$key, $val])->toArray()
        );

        if (!empty($this->suspiciousWarnings)) {
            $this->warn("Suspicious unit costs encountered:");
            $this->table(
                ['Product ID', 'Code', 'Detail ID', 'Date', 'Run Qty', 'Run Value', 'Computed Cost'],
                $this->suspiciousWarnings
            );
        }
    }

    protected function processProduct(Product $product, $settingId, $startDate, $endDate, $isDryRun, $force, $settings)
    {
        // Use HistoricalReplayEngine to collect timeline events
        $replayEngine = new HistoricalReplayEngine();
        $events = $replayEngine->collectTimelineEvents(
            productId: $product->id,
            untilDate: $endDate ? Carbon::parse($endDate) : null,
        );

        // Collect all sales from events for processing
        $allEvents = $events;
        if ($events->isEmpty()) {
            // Even with no historical events, we still need to check for future purchases
            // for fallback averages. Collect all sales without a date limit.
            $allEvents = $replayEngine->collectTimelineEvents(
                productId: $product->id,
                untilDate: null,
            );
            if ($allEvents->isEmpty()) {
                return;
            }
        }

        // Use engine's bucket isolation replay to get sale snapshots and fallback handling
        $replayResult = $replayEngine->replayWithBucketIsolation($events);

        $saleSnapshots = $replayResult['sale_snapshots'];
        if (!empty($replayResult['warnings']['negative_stock'])) {
            $this->summary['negative_stock'] += count($replayResult['warnings']['negative_stock']);
        }

        // Build fallback averages from future purchases (for sales with no historical cost)
        // Use the earliest sale date as baseline for finding "future" purchases
        $fallbackDate = $endDate ? Carbon::parse($endDate) : null;
        if (!$fallbackDate) {
            $earliestSale = $allEvents
                ->filter(fn($e) => $e['type'] === 'sale')
                ->sortBy('date')
                ->first();
            if ($earliestSale) {
                $fallbackDate = Carbon::parse($earliestSale['date']);
            }
        }

        $fallbackAverages = $replayEngine->buildFallbackAverages(
            productId: $product->id,
            afterDate: $fallbackDate,
        );

        // Process sales with snapshot filtering and scope checks
        foreach ($allEvents as $event) {
            if ($event['type'] !== 'sale') {
                continue;
            }

            $sd = $event['model'];

            $inScope = true;
            $eventDate = Carbon::parse($sd->sale->date)->startOfDay();
            if ($startDate && $eventDate->lt(Carbon::parse($startDate)->startOfDay())) {
                $inScope = false;
            }
            if ($endDate && $eventDate->gt(Carbon::parse($endDate)->startOfDay())) {
                $inScope = false;
            }
            if ($settingId && $sd->sale->setting_id != $settingId) {
                $inScope = false;
            }

            if ($inScope) {
                $this->summary['scanned']++;
            }

            // Skip if:
            // 1. Snapshot is from imported HPP (never overwrite, regardless of --force)
            // 2. Snapshot is from backfill AND not force flag
            $isImportedHpp = $sd->cost_snapshot_source === 'HPP_SNAPSHOT_IMPORT';
            $shouldSkipSnapshot = $isImportedHpp || (!$force && $sd->cost_snapshot_source && str_starts_with($sd->cost_snapshot_source, 'BACKFILL_'));

            if ($inScope && $shouldSkipSnapshot) {
                $this->summary['skipped']++;
            } elseif ($inScope) {
                $this->summary['fillable']++;

                // Use snapshot from replay engine if available
                $unitCost = $saleSnapshots[$event['id']] ?? 0;
                $source = '';

                if (!$product->stock_managed) {
                    $unitCost = 0;
                    $source = 'NON_STOCK_ZERO';
                    $this->summary['non_stock_zero']++;
                } else {
                    if ($unitCost > 0) {
                        $source = 'BACKFILL_RUNNING_AVERAGE';
                    } else {
                        // Try future purchase fallback before zero fallback
                        $companyName = $settings[$sd->sale->setting_id]->company_name ?? null;
                        $bucket = BackfillCostCalculator::classifyBucket($companyName);

                        if ($fallbackAverages[$bucket] !== null) {
                            $unitCost = $fallbackAverages[$bucket];
                            $source = 'BACKFILL_FUTURE_PURCHASE';
                            $this->summary['future_purchase_fallback']++;
                        } else {
                            $source = 'BACKFILL_ZERO_FALLBACK';
                            $this->summary['no_purchase_fallback']++;
                        }
                    }
                }

                $maxCost = (float) config('sale.suspicious_unit_cost_max', 100000000);
                $isSuspicious = $unitCost < 0 || !is_finite($unitCost) || $unitCost > $maxCost;

                if ($isSuspicious) {
                    $this->summary['suspicious_unit_cost']++;
                    $this->suspiciousWarnings[] = [
                        $product->id,
                        $product->product_code,
                        $sd->id,
                        $sd->sale->date,
                        0, // runningQty - not available from engine
                        0, // runningValue - not available from engine
                        $unitCost,
                    ];
                } else {
                    $totalCost = $unitCost * $sd->quantity;

                    if (!$isDryRun) {
                        $this->updateBatch[] = [
                            'id' => $sd->id,
                            'cost_unit_snapshot' => round($unitCost, 6),
                            'cost_total_snapshot' => round($totalCost, 2),
                            'cost_snapshot_source' => $source,
                            'cost_snapshot_at' => now()->format('Y-m-d H:i:s'),
                        ];
                        $this->summary['updated']++;

                        if (count($this->updateBatch) >= $this->batchSize) {
                            $this->flushUpdates();
                        }
                    }
                }
            }
        }
    }

    protected function flushUpdates()
    {
        if (empty($this->updateBatch)) {
            return;
        }

        DB::transaction(function () {
            foreach ($this->updateBatch as $update) {
                DB::table('sale_details')
                    ->where('id', $update['id'])
                    ->update([
                        'cost_unit_snapshot' => $update['cost_unit_snapshot'],
                        'cost_total_snapshot' => $update['cost_total_snapshot'],
                        'cost_snapshot_source' => $update['cost_snapshot_source'],
                        'cost_snapshot_at' => $update['cost_snapshot_at'],
                        'updated_at' => $update['cost_snapshot_at'],
                    ]);
            }
        });

        $this->updateBatch = [];
    }
}

<?php

namespace Modules\Sale\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Support\BundleItemOwnerLineageResolver;
use Modules\Purchase\Services\HistoricalReplayEngine;
use Carbon\Carbon;

/**
 * Bundle-component counterpart to sales:backfill-cost-snapshots. Backfills
 * historical HPP for sale_bundle_items rows that predate the owner-aware
 * component snapshotting introduced by harden-product-bundle-hpp, using the
 * same effective-date purchase replay engine on each component product's
 * timeline. Reports parent and component updates separately: this command
 * never writes to sale_details, and never rolls component cost into an
 * imported parent HPP value.
 */
class BackfillSaleBundleItemCostSnapshotsCommand extends Command
{
    protected $signature = 'sales:backfill-bundle-item-cost-snapshots
                            {--write : Write the computed snapshots to the database}
                            {--force : Recompute and overwrite existing non-imported snapshots}
                            {--product= : Limit to a specific component product ID}
                            {--start= : Start date (YYYY-MM-DD)}
                            {--end= : End date (YYYY-MM-DD)}';

    protected $description = 'Backfill historical sale_bundle_items cost snapshots using effective-date purchase replay';

    protected $summary = [
        'scanned' => 0,
        'fillable' => 0,
        'updated' => 0,
        'skipped_existing_snapshot' => 0,
        'skipped_ambiguous_owner' => 0,
        'non_stock_zero' => 0,
        'future_purchase_fallback' => 0,
        'no_purchase_fallback' => 0,
    ];

    protected array $skippedOwnerRows = [];
    protected array $updateBatch = [];
    protected int $batchSize = 500;

    public function handle(): void
    {
        $isDryRun = !$this->option('write');
        $force = (bool) $this->option('force');
        $productId = $this->option('product');
        $startDate = $this->option('start');
        $endDate = $this->option('end');

        if ($isDryRun) {
            $this->info('Starting bundle-item backfill in DRY RUN mode. No data will be written.');
        } else {
            $this->warn('Starting bundle-item backfill in WRITE mode. Data will be modified.');
        }

        $query = Product::withoutEagerLoads()
            ->select('id', 'product_code', 'stock_managed')
            ->whereIn('id', function ($q) use ($productId) {
                $q->select('product_id')->from('sale_bundle_items')->distinct();
                if ($productId) {
                    $q->where('product_id', $productId);
                }
            });

        $count = $query->count();
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunk(50, function ($products) use ($bar, $startDate, $endDate, $isDryRun, $force) {
            foreach ($products as $product) {
                $this->processProduct($product, $startDate, $endDate, $isDryRun, $force);
                $bar->advance();
            }
        });

        $this->flushUpdates();

        $bar->finish();
        $this->line('');
        $this->info('Bundle-item backfill completed.');

        $this->table(
            ['Metric', 'Count'],
            collect($this->summary)->map(fn ($val, $key) => [$key, $val])->toArray()
        );

        if (!empty($this->skippedOwnerRows)) {
            $this->warn('Bundle-component rows skipped for ambiguous/absent physical owner lineage:');
            $this->table(
                ['Sale Bundle Item ID', 'Sale ID', 'Product ID', 'Bundle Item ID', 'Reason'],
                $this->skippedOwnerRows
            );
        }
    }

    protected function processProduct(Product $product, $startDate, $endDate, bool $isDryRun, bool $force): void
    {
        $eligibleBundleItems = SaleBundleItem::query()
            ->where('product_id', $product->id)
            ->when(!$force, fn ($q) => $q->whereNull('cost_snapshot_source'))
            ->get(['id', 'sale_id', 'bundle_id', 'bundle_item_id', 'product_id', 'quantity', 'cost_snapshot_source']);

        if ($eligibleBundleItems->isEmpty()) {
            return;
        }

        $lineage = app(BundleItemOwnerLineageResolver::class)->resolve($eligibleBundleItems);
        $ownerSettings = $lineage['resolved'];

        foreach ($lineage['skipped'] as $skipped) {
            $this->summary['skipped_ambiguous_owner']++;
            $this->skippedOwnerRows[] = [
                $skipped['sale_bundle_item_id'],
                $skipped['sale_id'],
                $skipped['product_id'],
                $skipped['bundle_item_id'],
                $skipped['reason'],
            ];
        }

        if ($ownerSettings->isEmpty()) {
            return;
        }

        $replayEngine = new HistoricalReplayEngine();
        $events = $replayEngine->collectTimelineEvents(
            productId: $product->id,
            untilDate: $endDate ? Carbon::parse($endDate) : null,
            includeBundleItems: true,
            bundleItemOwnerSettings: $ownerSettings,
        );

        if ($events->isEmpty()) {
            return;
        }

        $replayResult = $replayEngine->replayWithBucketIsolation($events);
        $saleSnapshots = $replayResult['sale_snapshots'];

        $fallbackDate = $endDate ? Carbon::parse($endDate) : null;
        if (!$fallbackDate) {
            $earliest = $events->sortBy('date')->first();
            if ($earliest) {
                $fallbackDate = Carbon::parse($earliest['date']);
            }
        }
        $fallbackAverages = $replayEngine->buildFallbackAverages(productId: $product->id, afterDate: $fallbackDate);

        $bundleItemsById = $eligibleBundleItems->keyBy('id');

        foreach ($events as $event) {
            if (($event['origin'] ?? null) !== 'sale_bundle_item') {
                continue;
            }

            $bi = $event['model'];
            $bundleItem = $bundleItemsById->get($bi->id);
            if (!$bundleItem) {
                continue;
            }

            $inScope = true;
            $eventDate = Carbon::parse($event['date'])->startOfDay();
            if ($startDate && $eventDate->lt(Carbon::parse($startDate)->startOfDay())) {
                $inScope = false;
            }
            if ($endDate && $eventDate->gt(Carbon::parse($endDate)->startOfDay())) {
                $inScope = false;
            }

            if (!$inScope) {
                continue;
            }

            $this->summary['scanned']++;

            if (!$force && $bundleItem->cost_snapshot_source !== null) {
                $this->summary['skipped_existing_snapshot']++;
                continue;
            }

            $this->summary['fillable']++;

            $ownerSettingId = (int) $ownerSettings->get($bundleItem->id);
            $unitCost = $saleSnapshots[$event['id']] ?? 0;
            $source = 'BACKFILL_RUNNING_AVERAGE';

            if (!$product->stock_managed) {
                $unitCost = 0;
                $source = 'NON_STOCK_ZERO';
                $this->summary['non_stock_zero']++;
            } elseif ($unitCost <= 0) {
                $bucket = \Modules\Sale\Support\BackfillCostCalculator::classifyBucket(
                    \Modules\Setting\Entities\Setting::query()->whereKey($ownerSettingId)->value('company_name')
                );

                if (($fallbackAverages[$bucket] ?? null) !== null) {
                    $unitCost = $fallbackAverages[$bucket];
                    $source = 'BACKFILL_FUTURE_PURCHASE';
                    $this->summary['future_purchase_fallback']++;
                } else {
                    $source = 'BACKFILL_ZERO_FALLBACK';
                    $this->summary['no_purchase_fallback']++;
                }
            }

            $totalCost = $unitCost * (float) $bundleItem->quantity;

            if (!$isDryRun) {
                $this->updateBatch[] = [
                    'id' => $bundleItem->id,
                    'cost_unit_snapshot' => round($unitCost, 6),
                    'cost_total_snapshot' => round($totalCost, 2),
                    'cost_snapshot_source' => $source,
                    'cost_snapshot_setting_id' => $ownerSettingId ?: null,
                    'cost_snapshot_at' => now()->format('Y-m-d H:i:s'),
                ];
                $this->summary['updated']++;

                if (count($this->updateBatch) >= $this->batchSize) {
                    $this->flushUpdates();
                }
            }
        }
    }

    protected function flushUpdates(): void
    {
        if (empty($this->updateBatch)) {
            return;
        }

        DB::transaction(function () {
            foreach ($this->updateBatch as $update) {
                DB::table('sale_bundle_items')
                    ->where('id', $update['id'])
                    ->update([
                        'cost_unit_snapshot' => $update['cost_unit_snapshot'],
                        'cost_total_snapshot' => $update['cost_total_snapshot'],
                        'cost_snapshot_source' => $update['cost_snapshot_source'],
                        'cost_snapshot_setting_id' => $update['cost_snapshot_setting_id'],
                        'cost_snapshot_at' => $update['cost_snapshot_at'],
                        'updated_at' => $update['cost_snapshot_at'],
                    ]);
            }
        });

        $this->updateBatch = [];
    }
}

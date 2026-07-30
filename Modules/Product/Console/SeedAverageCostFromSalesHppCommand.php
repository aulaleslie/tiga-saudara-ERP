<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;

class SeedAverageCostFromSalesHppCommand extends Command
{
    protected $signature = 'product:seed-average-cost-from-sales-hpp {--write : Apply the changes to the database}';
    protected $description = 'Seed average purchase prices from the latest imported HPP sales snapshots.';

    public function handle()
    {
        $isWrite = $this->option('write');
        $mode = $isWrite ? 'WRITE' : 'DRY-RUN';

        $this->info("Starting product average-cost seeding in $mode mode...");

        $settings = Setting::all();
        $specialSettingIds = $this->getSpecialSettingIds($settings);

        $consideredCount = 0;
        $skippedCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;

        Product::where('stock_managed', true)
            ->chunkById(100, function ($products) use (
                $isWrite,
                $settings,
                $specialSettingIds,
                &$consideredCount,
                &$skippedCount,
                &$createdCount,
                &$updatedCount,
                &$unchangedCount
            ) {
                foreach ($products as $product) {
                    $consideredCount++;

                    $buckets = $this->resolveProductBuckets($product, $settings, $specialSettingIds);

                    $this->processProductBuckets(
                        $product,
                        $buckets,
                        $specialSettingIds,
                        $isWrite,
                        $skippedCount,
                        $createdCount,
                        $updatedCount,
                        $unchangedCount
                    );
                }
            });

        $this->outputDryRunReport(
            $mode,
            $consideredCount,
            $skippedCount,
            $createdCount,
            $updatedCount,
            $unchangedCount
        );

        return 0;
    }

    private function getSpecialSettingIds(Collection $settings): array
    {
        $tigaNusaIds = [];
        $topItIds = [];

        foreach ($settings as $setting) {
            $name = strtolower(trim($setting->company_name ?? ''));
            if ($name === 'cv tiga nusa computer') {
                $tigaNusaIds[] = $setting->id;
            } elseif ($name === 'cv top it internusa') {
                $topItIds[] = $setting->id;
            }
        }

        return [
            'tiga_nusa' => $tigaNusaIds,
            'top_it' => $topItIds,
        ];
    }

    private function resolveProductBuckets(Product $product, Collection $settings, array $specialSettingIds): array
    {
        $buckets = [
            'tiga_nusa' => null,
            'top_it' => null,
            'rest' => null,
        ];

        $candidates = $this->getHppImportCandidates($product);

        foreach ($candidates as $candidate) {
            $bucket = $this->getBucketForSetting($candidate['setting_id'], $specialSettingIds);
            if ($bucket && !isset($buckets[$bucket])) {
                $buckets[$bucket] = $candidate;
            }
        }

        return array_filter($buckets, fn($v) => $v !== null);
    }

    private function getHppImportCandidates(Product $product): array
    {
        $candidates = SaleDetails::where('product_id', $product->id)
            ->where('cost_snapshot_source', 'HPP_SNAPSHOT_IMPORT')
            ->whereRaw('cost_unit_snapshot > 0')
            ->with('sale:id,date,setting_id')
            ->get()
            ->map(fn($detail) => [
                'detail_id' => $detail->id,
                'sale_id' => $detail->sale_id,
                'sale_date' => is_string($detail->sale->date)
                    ? $detail->sale->date
                    : $detail->sale->date->format('Y-m-d H:i:s'),
                'setting_id' => $detail->sale->setting_id,
                'cost_unit_snapshot' => $detail->cost_unit_snapshot,
            ])
            ->toArray();

        usort($candidates, function ($a, $b) {
            if ($a['sale_date'] !== $b['sale_date']) {
                return $b['sale_date'] <=> $a['sale_date'];
            }
            if ($a['sale_id'] !== $b['sale_id']) {
                return $b['sale_id'] <=> $a['sale_id'];
            }
            return $b['detail_id'] <=> $a['detail_id'];
        });

        return $candidates;
    }

    private function getBucketForSetting(int $settingId, array $specialSettingIds): ?string
    {
        if (in_array($settingId, $specialSettingIds['tiga_nusa'])) {
            return 'tiga_nusa';
        }
        if (in_array($settingId, $specialSettingIds['top_it'])) {
            return 'top_it';
        }
        return 'rest';
    }

    private function processProductBuckets(
        Product $product,
        array $buckets,
        array $specialSettingIds,
        bool $isWrite,
        &$skippedCount,
        &$createdCount,
        &$updatedCount,
        &$unchangedCount
    ): void {
        $allSettings = Setting::all();

        foreach ($allSettings as $setting) {
            $bucket = $this->getBucketForSetting($setting->id, $specialSettingIds);
            $candidate = null;

            if (isset($buckets[$bucket])) {
                $candidate = $buckets[$bucket];
            } elseif (in_array($bucket, ['tiga_nusa', 'top_it']) && isset($buckets['rest'])) {
                $candidate = $buckets['rest'];
            }

            if (!$candidate) {
                $skippedCount++;
                continue;
            }

            $existingPrice = ProductPrice::where('product_id', $product->id)
                ->where('setting_id', $setting->id)
                ->first();

            if ($existingPrice) {
                if ($existingPrice->average_purchase_price == $candidate['cost_unit_snapshot']) {
                    $unchangedCount++;
                } else {
                    if ($isWrite) {
                        $existingPrice->update(['average_purchase_price' => $candidate['cost_unit_snapshot']]);
                    }
                    $updatedCount++;
                }
            } else {
                if ($isWrite) {
                    $this->createProductPrice($product, $setting, $candidate);
                }
                $createdCount++;
            }
        }
    }

    private function createProductPrice(Product $product, Setting $setting, array $candidate): void
    {
        $template = ProductPrice::where('product_id', $product->id)->first();

        $data = [
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'average_purchase_price' => $candidate['cost_unit_snapshot'],
            'last_purchase_price' => $template?->last_purchase_price ?? 0,
            'sale_price' => $template?->sale_price ?? 0,
            'purchase_tax_id' => $template?->purchase_tax_id,
            'sale_tax_id' => $template?->sale_tax_id,
        ];

        if ($template) {
            $data['tier_1_price'] = $template->tier_1_price;
            $data['tier_2_price'] = $template->tier_2_price;
        }

        ProductPrice::create($data);
    }

    private function outputDryRunReport(
        string $mode,
        int $consideredCount,
        int $skippedCount,
        int $createdCount,
        int $updatedCount,
        int $unchangedCount
    ): void {
        $this->line('');
        $this->info("== $mode MODE ==");
        $this->info("Considered products: $consideredCount");
        $this->info("Skipped: $skippedCount");
        $this->info("Created: $createdCount");
        $this->info("Updated: $updatedCount");
        $this->info("Unchanged: $unchangedCount");
    }
}

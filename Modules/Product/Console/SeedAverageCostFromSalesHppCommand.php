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
        $unresolvedCount = 0;
        $createdCount = 0;
        $baselineFilledCount = 0;
        $specialOverlayUpdatedCount = 0;
        $unchangedCount = 0;

        Product::where('stock_managed', true)
            ->chunkById(100, function ($products) use (
                $isWrite,
                $settings,
                $specialSettingIds,
                &$consideredCount,
                &$unresolvedCount,
                &$createdCount,
                &$baselineFilledCount,
                &$specialOverlayUpdatedCount,
                &$unchangedCount
            ) {
                $productIds = $products->pluck('id')->toArray();

                foreach ($products as $product) {
                    $consideredCount++;

                    $candidates = $this->getHppImportCandidates($product);
                    $baseline = $this->resolveBaselineFromCandidates($candidates, $specialSettingIds);
                    $specialOverlays = $this->resolveSpecialOverlaysFromCandidates($candidates, $specialSettingIds);

                    $this->processProduct(
                        $product,
                        $settings,
                        $baseline,
                        $specialOverlays,
                        $specialSettingIds,
                        $isWrite,
                        $unresolvedCount,
                        $createdCount,
                        $baselineFilledCount,
                        $specialOverlayUpdatedCount,
                        $unchangedCount
                    );
                }
            });

        $this->outputReport(
            $mode,
            $consideredCount,
            $unresolvedCount,
            $createdCount,
            $baselineFilledCount,
            $specialOverlayUpdatedCount,
            $unchangedCount
        );

        return 0;
    }

    private function getSpecialSettingIds(Collection $settings): array
    {
        $tigaNusaIds = [];
        $topItIds = [];
        $perdanaIds = [];

        foreach ($settings as $setting) {
            $name = strtolower(trim($setting->company_name ?? ''));
            if ($name === 'cv tiga nusa computer') {
                $tigaNusaIds[] = $setting->id;
            } elseif ($name === 'cv top it internusa') {
                $topItIds[] = $setting->id;
            } elseif ($name === 'perdana') {
                $perdanaIds[] = $setting->id;
            }
        }

        return [
            'tiga_nusa' => $tigaNusaIds,
            'top_it' => $topItIds,
            'perdana' => $perdanaIds,
        ];
    }

    private function resolveBaselineFromCandidates(array $candidates, array $specialSettingIds): ?array
    {
        $perdanaCandidate = null;
        $topItCandidate = null;
        $tigaNusaCandidate = null;

        foreach ($candidates as $candidate) {
            $bucket = $this->getBucketForSetting($candidate['setting_id'], $specialSettingIds);
            if ($bucket === 'perdana' && !$perdanaCandidate) {
                $perdanaCandidate = $candidate;
            } elseif ($bucket === 'top_it' && !$topItCandidate) {
                $topItCandidate = $candidate;
            } elseif ($bucket === 'tiga_nusa' && !$tigaNusaCandidate) {
                $tigaNusaCandidate = $candidate;
            }
        }

        if ($perdanaCandidate) {
            return $perdanaCandidate;
        }
        if ($topItCandidate) {
            return $topItCandidate;
        }
        return $tigaNusaCandidate;
    }

    private function resolveSpecialOverlaysFromCandidates(array $candidates, array $specialSettingIds): array
    {
        $overlays = [
            'top_it' => null,
            'tiga_nusa' => null,
        ];

        foreach ($candidates as $candidate) {
            $bucket = $this->getBucketForSetting($candidate['setting_id'], $specialSettingIds);
            if ($bucket === 'top_it' && !$overlays['top_it']) {
                $overlays['top_it'] = $candidate;
            } elseif ($bucket === 'tiga_nusa' && !$overlays['tiga_nusa']) {
                $overlays['tiga_nusa'] = $candidate;
            }
        }

        return $overlays;
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
        if (in_array($settingId, $specialSettingIds['perdana'])) {
            return 'perdana';
        }
        return null;
    }

    private function processProduct(
        Product $product,
        Collection $settings,
        ?array $baseline,
        array $specialOverlays,
        array $specialSettingIds,
        bool $isWrite,
        &$unresolvedCount,
        &$createdCount,
        &$baselineFilledCount,
        &$specialOverlayUpdatedCount,
        &$unchangedCount
    ): void {
        if (!$baseline) {
            $unresolvedCount++;
            return;
        }

        $existingPrices = ProductPrice::where('product_id', $product->id)
            ->get()
            ->keyBy('setting_id');

        foreach ($settings as $setting) {
            $bucket = $this->getBucketForSetting($setting->id, $specialSettingIds);
            $existingPrice = $existingPrices->get($setting->id);

            $hppToUse = $baseline;
            $isSpecialOverlay = false;

            if ($bucket === 'top_it' && $specialOverlays['top_it']) {
                $hppToUse = $specialOverlays['top_it'];
                $isSpecialOverlay = true;
            } elseif ($bucket === 'tiga_nusa' && $specialOverlays['tiga_nusa']) {
                $hppToUse = $specialOverlays['tiga_nusa'];
                $isSpecialOverlay = true;
            }

            if ($existingPrice) {
                $this->updateExistingPrice(
                    $product,
                    $existingPrice,
                    $hppToUse,
                    $isSpecialOverlay,
                    $isWrite,
                    $unchangedCount,
                    $baselineFilledCount,
                    $specialOverlayUpdatedCount
                );
            } else {
                $this->createNewPrice(
                    $product,
                    $setting,
                    $hppToUse,
                    $isWrite,
                    $createdCount
                );
            }
        }
    }

    private function updateExistingPrice(
        Product $product,
        ProductPrice $price,
        array $hppCandidate,
        bool $isSpecialOverlay,
        bool $isWrite,
        &$unchangedCount,
        &$baselineFilledCount,
        &$specialOverlayUpdatedCount
    ): void {
        $currentAverage = (float) $price->average_purchase_price;
        $newAverage = (float) $hppCandidate['cost_unit_snapshot'];

        if ($currentAverage > 0 && !$isSpecialOverlay) {
            $unchangedCount++;
            return;
        }

        if ($currentAverage <= 0 || $isSpecialOverlay) {
            if ($currentAverage !== $newAverage) {
                if ($isWrite) {
                    $price->update(['average_purchase_price' => $newAverage]);
                }
                if ($isSpecialOverlay) {
                    $specialOverlayUpdatedCount++;
                } else {
                    $baselineFilledCount++;
                }
            } else {
                $unchangedCount++;
            }
        }
    }

    private function createNewPrice(
        Product $product,
        Setting $setting,
        array $baseline,
        bool $isWrite,
        &$createdCount
    ): void {
        $template = ProductPrice::where('product_id', $product->id)->first();

        $data = [
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'average_purchase_price' => $baseline['cost_unit_snapshot'],
            'sale_price' => $template?->sale_price ?? 0,
            'tier_1_price' => $template?->tier_1_price ?? 0,
            'tier_2_price' => $template?->tier_2_price ?? 0,
            'purchase_tax_id' => $template?->purchase_tax_id,
            'sale_tax_id' => $template?->sale_tax_id,
        ];

        if ($isWrite) {
            ProductPrice::create($data);
        }
        $createdCount++;
    }

    private function outputReport(
        string $mode,
        int $consideredCount,
        int $unresolvedCount,
        int $createdCount,
        int $baselineFilledCount,
        int $specialOverlayUpdatedCount,
        int $unchangedCount
    ): void {
        $this->line('');
        $this->info("== $mode MODE ==");
        $this->info("Considered products: $consideredCount");
        $this->info("Created: $createdCount");
        $this->info("Baseline-filled: $baselineFilledCount");
        $this->info("Special-overlay-updated: $specialOverlayUpdatedCount");
        $this->info("Unchanged: $unchangedCount");
        $this->info("Unresolved: $unresolvedCount");
    }

}

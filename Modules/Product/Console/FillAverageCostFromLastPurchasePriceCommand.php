<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;

class FillAverageCostFromLastPurchasePriceCommand extends Command
{
    protected $signature = 'product:fill-average-cost-from-last-purchase-price {--write : Apply the changes to the database}';
    protected $description = 'Terminal fallback beneath the purchase-history and sales-HPP commands to fill average cost from last purchase price.';

    public function handle()
    {
        $isWrite = $this->option('write');
        $mode = $isWrite ? 'WRITE' : 'DRY-RUN';

        $this->info("Starting product average-cost fill from last purchase price in $mode mode...");

        $settings = Setting::all();
        $specialSettingIds = $this->getSpecialSettingIds($settings);

        $consideredCount = 0;
        $ownFillCount = 0;
        $donorFillCount = 0;
        $unchangedCount = 0;
        $unresolvedCount = 0;

        $donorSnapshot = [];
        foreach (ProductPrice::select('product_id', 'setting_id', 'last_purchase_price')
            ->where('last_purchase_price', '>', 0)
            ->cursor() as $row) {
            $donorSnapshot[$row->product_id][] = [
                'setting_id' => $row->setting_id,
                'last_purchase_price' => $row->last_purchase_price,
            ];
        }

        ProductPrice::chunkById(100, function ($prices) use (
            $isWrite,
            $specialSettingIds,
            &$consideredCount,
            &$ownFillCount,
            &$donorFillCount,
            &$unchangedCount,
            &$unresolvedCount,
            $donorSnapshot
        ) {

            foreach ($prices as $price) {
                $consideredCount++;

                $currentAverage = (float) $price->average_purchase_price;

                if ($currentAverage > 0) {
                    $unchangedCount++;
                    continue;
                }

                // Row is eligible (average is zero or null)
                $ownLast = (float) $price->last_purchase_price;

                if ($ownLast > 0) {
                    // Own-fill path
                    if ($isWrite) {
                        $price->update(['average_purchase_price' => $ownLast]);
                    }
                    $ownFillCount++;
                    continue;
                }

                // Donor path
                $siblings = $donorSnapshot[$price->product_id] ?? [];
                
                $donorLast = $this->resolveDonor($price, $siblings, $specialSettingIds);

                if ($donorLast !== null) {
                    if ($isWrite) {
                        $price->update([
                            'average_purchase_price' => $donorLast,
                            'last_purchase_price' => $donorLast,
                        ]);
                    }
                    $donorFillCount++;
                } else {
                    $unresolvedCount++;
                }
            }
        });

        $this->outputReport(
            $mode,
            $consideredCount,
            $ownFillCount,
            $donorFillCount,
            $unchangedCount,
            $unresolvedCount
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

    private function resolveDonor(ProductPrice $targetPrice, array $siblings, array $specialSettingIds): ?float
    {
        $eligibleDonors = collect($siblings)->filter(function ($sibling) use ($targetPrice) {
            return $sibling['setting_id'] !== $targetPrice->setting_id 
                && (float) $sibling['last_purchase_price'] > 0;
        });

        if ($eligibleDonors->isEmpty()) {
            return null;
        }

        $rankedDonors = $eligibleDonors->map(function ($donor) use ($specialSettingIds) {
            $settingId = $donor['setting_id'];
            $rank = 4; // Unranked

            if (in_array($settingId, $specialSettingIds['perdana'])) {
                $rank = 1;
            } elseif (in_array($settingId, $specialSettingIds['top_it'])) {
                $rank = 2;
            } elseif (in_array($settingId, $specialSettingIds['tiga_nusa'])) {
                $rank = 3;
            }

            return [
                'last_purchase_price' => (float) $donor['last_purchase_price'],
                'rank' => $rank,
                'setting_id' => $settingId,
            ];
        })->sortBy([
            ['rank', 'asc'],
            ['setting_id', 'asc']
        ])->values();

        return $rankedDonors->first()['last_purchase_price'] ?? null;
    }

    private function outputReport(
        string $mode,
        int $consideredCount,
        int $ownFillCount,
        int $donorFillCount,
        int $unchangedCount,
        int $unresolvedCount
    ): void {
        $this->line('');
        $this->info("== $mode MODE ==");
        $this->info("Considered rows: $consideredCount");
        $this->info("Filled (own last_purchase_price): $ownFillCount");
        $this->info("Filled (donor owner): $donorFillCount");
        $this->info("Unchanged (positive average): $unchangedCount");
        $this->info("Unresolved (no donor): $unresolvedCount");
    }
}

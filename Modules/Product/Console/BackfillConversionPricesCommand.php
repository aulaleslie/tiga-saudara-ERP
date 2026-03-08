<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Setting\Entities\Setting;

class BackfillConversionPricesCommand extends Command
{
    protected $signature = 'product:backfill-conversion-prices';

    protected $description = 'Backfill missing product_unit_conversion_prices rows for all active settings.';

    public function handle(): int
    {
        $this->info('Starting conversion price backfill...');

        // Get all active settings
        $settings = Setting::query()->get();

        if ($settings->isEmpty()) {
            $this->warn('No settings found.');
            return 0;
        }

        $settingIds = $settings->pluck('id')->toArray();
        $this->info('Found ' . count($settingIds) . ' settings.');

        // Find all conversions
        $conversions = ProductUnitConversion::query()
            ->with('product')
            ->get();

        if ($conversions->isEmpty()) {
            $this->info('No unit conversions found.');
            return 0;
        }

        $this->info('Found ' . $conversions->count() . ' unit conversions.');

        $totalProcessed = 0;
        $totalBackfilled = 0;
        $totalErrors = 0;

        foreach ($conversions as $conversion) {
            if (! $conversion->product) {
                $this->warn("Skipping conversion {$conversion->id}: product not found.");
                continue;
            }

            foreach ($settingIds as $settingId) {
                $totalProcessed++;

                try {
                    // Check if price row exists
                    $exists = ProductUnitConversionPrice::query()
                        ->where('product_unit_conversion_id', $conversion->id)
                        ->where('setting_id', $settingId)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    // Backfill with 0 as default price (can be updated later by admin)
                    ProductUnitConversionPrice::create([
                        'product_unit_conversion_id' => $conversion->id,
                        'setting_id' => $settingId,
                        'price' => 0,
                    ]);

                    $totalBackfilled++;
                } catch (\Exception $e) {
                    $this->error("Error backfilling conversion {$conversion->id} for setting {$settingId}: " . $e->getMessage());
                    $totalErrors++;
                }
            }
        }

        $this->info("Backfill complete.");
        $this->info("Processed: {$totalProcessed} | Backfilled: {$totalBackfilled} | Errors: {$totalErrors}");

        if ($totalErrors > 0) {
            return 1;
        }

        return 0;
    }
}

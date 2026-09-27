<?php

namespace Modules\Product\Services;

use Illuminate\Support\Collection;

class SerialConversionPoolAggregator
{
    /**
     * Aggregate product stock rows into owner-level pools.
     *
     * Returns a array structure keyed by owner/setting ID:
     * [
     *   setting_id => [
     *     'setting_id' => int,
     *     'setting_name' => string,
     *     'pools' => [
     *        'normal_non_tax' => int,
     *        'normal_tax'     => int,
     *        'broken_non_tax' => int,
     *        'broken_tax'     => int,
     *     ],
     *     'total' => int,
     *   ]
     * ]
     */
    public function aggregate(Collection $productStocks): array
    {
        $owners = [];

        foreach ($productStocks as $stock) {
            $location = $stock->location;
            if (! $location) {
                continue;
            }

            $settingId = $location->setting_id;
            $settingName = $location->setting?->company_name ?? "Cabang #{$settingId}";

            if (! isset($owners[$settingId])) {
                $owners[$settingId] = [
                    'setting_id' => $settingId,
                    'setting_name' => $settingName,
                    'pools' => [
                        'normal_non_tax' => 0,
                        'normal_tax' => 0,
                        'broken_non_tax' => 0,
                        'broken_tax' => 0,
                    ],
                    'total' => 0,
                ];
            }

            $normalNonTax = (int) round((float) $stock->quantity_non_tax);
            $normalTax = (int) round((float) $stock->quantity_tax);
            $brokenNonTax = (int) round((float) $stock->broken_quantity_non_tax);
            $brokenTax = (int) round((float) $stock->broken_quantity_tax);

            $owners[$settingId]['pools']['normal_non_tax'] += $normalNonTax;
            $owners[$settingId]['pools']['normal_tax'] += $normalTax;
            $owners[$settingId]['pools']['broken_non_tax'] += $brokenNonTax;
            $owners[$settingId]['pools']['broken_tax'] += $brokenTax;

            $owners[$settingId]['total'] += ($normalNonTax + $normalTax + $brokenNonTax + $brokenTax);
        }

        // Sort by setting_id for stable structure
        ksort($owners);

        return $owners;
    }
}

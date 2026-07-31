<?php

namespace Modules\Product\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;

class TigaNusaPriceExportService
{
    public function buildQuery(): Builder
    {
        // Resolve CV TIGA NUSA COMPUTER setting
        $setting = Setting::where('company_name', 'CV TIGA NUSA COMPUTER')->first();

        if (!$setting) {
            throw new \Exception('Setting with company name "CV TIGA NUSA COMPUTER" not found.');
        }

        // Build query: all products left-joined to the resolved setting's product_prices
        return Product::query()
            ->select(
                'products.id',
                'products.product_name',
                'product_prices.sale_price',
                'product_prices.tier_1_price',
                'product_prices.tier_2_price'
            )
            ->leftJoin(
                'product_prices',
                function ($join) use ($setting) {
                    $join->on('products.id', '=', 'product_prices.product_id')
                         ->where('product_prices.setting_id', '=', $setting->id);
                }
            )
            ->orderBy('products.product_name', 'asc');
    }

    public function resolveTargetSetting(): Setting
    {
        $settings = Setting::where('company_name', 'CV TIGA NUSA COMPUTER')->get();

        if ($settings->count() === 0) {
            throw new \Exception('No setting found with company name "CV TIGA NUSA COMPUTER".');
        }

        if ($settings->count() > 1) {
            throw new \Exception('Multiple settings found with company name "CV TIGA NUSA COMPUTER". Please verify the database.');
        }

        return $settings->first();
    }
}

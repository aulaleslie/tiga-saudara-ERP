<?php

namespace Modules\Product\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;

class TigaNusaPriceExportService
{
    public const TIGA_NUSA_COMPANY_NAME = 'CV TIGA NUSA COMPUTER';
    public const TOP_IT_COMPANY_NAME = 'CV TOP IT INTERNUSA';

    /**
     * Resolve every company setting required by the export, in worksheet order.
     *
     * @return array<int, Setting>
     */
    public function resolveTargetSettings(): array
    {
        return [
            $this->resolveSettingByCompanyName(self::TIGA_NUSA_COMPANY_NAME),
            $this->resolveSettingByCompanyName(self::TOP_IT_COMPANY_NAME),
        ];
    }

    public function resolveTargetSetting(): Setting
    {
        return $this->resolveSettingByCompanyName(self::TIGA_NUSA_COMPANY_NAME);
    }

    public function resolveSettingByCompanyName(string $companyName): Setting
    {
        $settings = Setting::where('company_name', $companyName)->get();

        if ($settings->count() === 0) {
            throw new \Exception("No setting found with company name \"{$companyName}\".");
        }

        if ($settings->count() > 1) {
            throw new \Exception("Multiple settings found with company name \"{$companyName}\". Please verify the database.");
        }

        return $settings->first();
    }

    /**
     * Build the product price query scoped to a single company setting.
     */
    public function buildQuery(?Setting $setting = null): Builder
    {
        $setting = $setting ?: $this->resolveTargetSetting();

        return Product::query()
            ->select(
                'products.id',
                'products.product_name',
                'products.purchase_price as product_purchase_price',
                'product_prices.sale_price',
                'product_prices.tier_1_price',
                'product_prices.tier_2_price',
                'product_prices.last_purchase_price',
                'product_prices.average_purchase_price'
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

    /**
     * Effective last purchase price: company value, else the product-level purchase price.
     * Null and zero are treated as unavailable.
     */
    public function resolveLastPurchasePrice($companyLastPurchasePrice, $productPurchasePrice): ?float
    {
        return $this->firstPositive($companyLastPurchasePrice, $productPurchasePrice);
    }

    /**
     * Effective average purchase price: company value, else the effective last purchase price.
     * Null and zero are treated as unavailable.
     */
    public function resolveAveragePurchasePrice($companyAveragePurchasePrice, ?float $effectiveLastPurchasePrice): ?float
    {
        return $this->firstPositive($companyAveragePurchasePrice, $effectiveLastPurchasePrice);
    }

    private function firstPositive(...$candidates): ?float
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $value = (float) $candidate;

            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }
}

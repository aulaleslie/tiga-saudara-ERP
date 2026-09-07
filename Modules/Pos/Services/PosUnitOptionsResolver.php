<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use Illuminate\Support\Collection;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Unit;

class PosUnitOptionsResolver
{
    /**
     * Resolve unit selection options for a single product in a setting context.
     *
     * @param  Product|int  $product
     * @param  int  $settingId
     * @return array{
     *     base_unit: array{id: int|null, name: string, short_name: string|null},
     *     has_selectable_units: bool,
     *     conversions: array<int, array{
     *         id: int,
     *         unit_id: int,
     *         unit_name: string,
     *         short_name: string|null,
     *         conversion_factor: int|float,
     *         is_valid: bool,
     *         invalid_reason: string|null,
     *         sales_enabled: bool,
     *         price_for_setting: float|null
     *     }>
     * }
     */
    public function resolveForProduct(Product|int $product, int $settingId): array
    {
        $productId = $product instanceof Product ? (int) $product->id : (int) $product;
        $resolved = $this->resolveForProductIds([$productId], $settingId);

        return $resolved[$productId] ?? $this->emptyPayload();
    }

    /**
     * Batch resolve unit selection options for multiple product IDs.
     *
     * @param  array<int>  $productIds
     * @param  int  $settingId
     * @return array<int, array{
     *     base_unit: array{id: int|null, name: string, short_name: string|null},
     *     has_selectable_units: bool,
     *     conversions: array<int, array{
     *         id: int,
     *         unit_id: int,
     *         unit_name: string,
     *         short_name: string|null,
     *         conversion_factor: int|float,
     *         is_valid: bool,
     *         invalid_reason: string|null,
     *         sales_enabled: bool,
     *         price_for_setting: float|null
     *     }>
     * }>
     */
    public function resolveForProductIds(array $productIds, int $settingId): array
    {
        $cleanIds = array_values(array_filter(array_unique(array_map('intval', $productIds)), fn ($id) => $id > 0));
        if (empty($cleanIds)) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', $cleanIds)
            ->with([
                'unit',
                'baseUnit',
                'conversions.unit',
                'conversions.baseUnit',
                'conversions.prices',
            ])
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($cleanIds as $pId) {
            $product = $products->get($pId);
            if (! $product) {
                $result[$pId] = $this->emptyPayload();
                continue;
            }

            $baseUnitModel = $product->baseUnit ?? $product->unit;
            $baseUnitId = $baseUnitModel ? (int) $baseUnitModel->id : null;
            $baseUnitName = $baseUnitModel ? (string) $baseUnitModel->name : 'PCS';
            $baseUnitShort = $baseUnitModel ? ($baseUnitModel->short_name ? (string) $baseUnitModel->short_name : null) : null;

            $conversionsList = [];
            $hasEligibleSalesConversion = false;

            foreach ($product->conversions as $conv) {
                $unit = $conv->unit;
                $unitName = $unit ? (string) $unit->name : 'Unit';
                $unitShort = $unit ? ($unit->short_name ? (string) $unit->short_name : null) : null;
                $rawFactor = (float) $conv->conversion_factor;
                $isIntegerFactor = is_finite($rawFactor) && abs($rawFactor - round($rawFactor)) < 1e-6;
                $factorInt = $isIntegerFactor ? (int) round($rawFactor) : $rawFactor;

                $isValid = true;
                $invalidReason = null;

                if ($unit && ! $unit->is_active) {
                    $isValid = false;
                    $invalidReason = 'Unit dinonaktifkan.';
                } elseif ($conv->base_unit_id !== null && $baseUnitId !== null && (int) $conv->base_unit_id !== $baseUnitId) {
                    $isValid = false;
                    $invalidReason = 'Unit dasar konversi tidak cocok.';
                } elseif (! is_finite($rawFactor) || $rawFactor <= 1.0) {
                    $isValid = false;
                    $invalidReason = 'Faktor konversi harus lebih dari 1.';
                } elseif (! $isIntegerFactor) {
                    $isValid = false;
                    $invalidReason = 'Faktor konversi harus berupa bilangan bulat.';
                }

                $isSalesEnabled = $conv->isSalesEnabledForSetting($settingId);
                $priceRow = $conv->priceForSetting($settingId);
                $priceForSetting = $priceRow && $priceRow->price !== null ? (float) $priceRow->price : null;

                if ($isValid && $isSalesEnabled) {
                    $hasEligibleSalesConversion = true;
                }

                $conversionsList[] = [
                    'id' => (int) $conv->id,
                    'unit_id' => (int) $conv->unit_id,
                    'unit_name' => $unitName,
                    'short_name' => $unitShort,
                    'conversion_factor' => $factorInt,
                    'is_valid' => $isValid,
                    'invalid_reason' => $invalidReason,
                    'sales_enabled' => $isSalesEnabled,
                    'price_for_setting' => $priceForSetting,
                ];
            }

            $result[$pId] = [
                'base_unit' => [
                    'id' => $baseUnitId,
                    'name' => $baseUnitName,
                    'short_name' => $baseUnitShort,
                ],
                'has_selectable_units' => $hasEligibleSalesConversion,
                'conversions' => $conversionsList,
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *     base_unit: array{id: null, name: string, short_name: null},
     *     has_selectable_units: false,
     *     conversions: array<empty>
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'base_unit' => [
                'id' => null,
                'name' => 'PCS',
                'short_name' => null,
            ],
            'has_selectable_units' => false,
            'conversions' => [],
        ];
    }
}

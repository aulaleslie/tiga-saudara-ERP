<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Sale\Support\PendingDispatchSerialGuard;
use Modules\Setting\Entities\Unit;

class PosScanResolverService
{
    /**
     * Resolve scan query to determine the action type.
     *
     * Resolution order:
     * 1. Exact barcode match on products
     * 2. Exact barcode match on product_unit_conversions
     * 3. Exact serial number match
     * 4. If no match, return none
     *
     * @param  int  $settingId
     * @param  string  $query
     * @return array{
     *     type: 'product_exact'|'serial_exact'|'none',
     *     product?: array{id: int, name: string, code: string, barcode: ?string, sale_price: float, serial_number_required: bool},
     *     serial?: array{serial_number: string, product_id: int, tax_id: ?int, location_id: int}
     * }
     */
    public function resolve(int $settingId, string $query): array
    {
        if (! $query || $query === '') {
            return ['type' => 'none'];
        }

        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();
        if (empty($allowedLocationIds)) {
            return ['type' => 'none'];
        }

        $query = trim($query);
        $queryLower = strtolower($query);

        // 1. Exact barcode match on products
        $productByBarcode = Product::query()
            ->active()
            ->where(function($q) {
                $q->where('stock_managed', true)
                  ->orWhere('is_sold', true);
            })
            ->whereRaw('LOWER(barcode) = ?', [$queryLower])
            ->first();

        if ($productByBarcode && (! $productByBarcode->stock_managed || $this->hasStockInAllowedLocations($productByBarcode->id, $allowedLocationIds)) && $this->hasPriceForSetting($productByBarcode->id, $settingId)) {
            return $this->formatProductExact($productByBarcode, $settingId);
        }

        // 2. Exact barcode match on product_unit_conversions
        $unitConversionBarcode = ProductUnitConversion::query()
            ->whereHas('product', fn ($q) => $q->where(function($query) {
                $query->where('stock_managed', true)
                      ->orWhere('is_sold', true);
            }))
            ->whereRaw('LOWER(barcode) = ?', [$queryLower])
            ->with(['product', 'unit'])
            ->first();

        if ($unitConversionBarcode && $unitConversionBarcode->product && (! $unitConversionBarcode->product->stock_managed || $this->hasStockInAllowedLocations($unitConversionBarcode->product->id, $allowedLocationIds)) && $this->hasPriceForSetting($unitConversionBarcode->product->id, $settingId)) {
            return $this->formatProductExact($unitConversionBarcode->product, $settingId, $unitConversionBarcode);
        }

        // 3. Exact serial number match
        $serialRecord = ProductSerialNumber::query()
            ->where('serial_number', $query)
            ->where('status', 'ACTIVE')
            ->whereNull('dispatch_detail_id')
            ->whereIn('location_id', $allowedLocationIds)
            ->with('product')
            ->first();

        if (
            $serialRecord
            && ! PendingDispatchSerialGuard::isReserved((string) $serialRecord->serial_number)
            && $serialRecord->product
            && $serialRecord->product->stock_managed
            && $this->hasPriceForSetting($serialRecord->product->id, $settingId)
        ) {
            $isBundleParent = $this->isBundleParent((int) $serialRecord->product->id, $settingId);

            return [
                'type' => 'serial_exact',
                'serial' => [
                    'serial_number' => (string) $serialRecord->serial_number,
                    'product_id' => (int) $serialRecord->product_id,
                    'tax_id' => $serialRecord->tax_id !== null ? (int) $serialRecord->tax_id : null,
                    'location_id' => (int) $serialRecord->location_id,
                ],
                'product' => [
                    'id' => (int) $serialRecord->product->id,
                    'name' => (string) $serialRecord->product->product_name,
                    'code' => (string) ($serialRecord->product->product_code ?? ''),
                    'barcode' => $serialRecord->product->barcode !== null ? (string) $serialRecord->product->barcode : null,
                    'sale_price' => (float) ($serialRecord->product->product_price ?? 0),
                    'serial_number_required' => (bool) $serialRecord->product->serial_number_required,
                    'is_bundle_parent' => $isBundleParent,
                ],
            ];
        }

        // 4. If no exact matches, return none
        return ['type' => 'none'];
    }

    /**
     * Format product for exact match response.
     *
     * @param  Product  $product
     * @param  int  $settingId
     * @param  ProductUnitConversion|null  $conversion  Optional conversion if matched via conversion barcode
     * @return array{type: string, product: array<string, mixed>}
     */
    private function formatProductExact(Product $product, int $settingId, ?ProductUnitConversion $conversion = null): array
    {
        $conversionMetadata = null;
        $isBundleParent = $this->isBundleParent((int) $product->id, $settingId);

        if ($conversion !== null) {
            // Look up the conversion price for this setting
            $conversionPrice = ProductUnitConversionPrice::query()
                ->where('product_unit_conversion_id', $conversion->id)
                ->where('setting_id', $settingId)
                ->first();

            $unitName = $conversion->unit ? (string) $conversion->unit->name : 'Unit';
            $priceForSetting = $conversionPrice ? (float) $conversionPrice->price : null;
            $priceSource = $conversionPrice ? 'conversion_price' : 'base_fallback';

            // If no conversion price found, use base product price as fallback
            if ($priceForSetting === null) {
                $priceForSetting = (float) ($product->product_price ?? 0);
            }

            $conversionMetadata = [
                'id' => (int) $conversion->id,
                'unit_id' => (int) $conversion->unit_id,
                'unit_name' => $unitName,
                'conversion_factor' => (float) $conversion->conversion_factor,
                'price_for_setting' => round($priceForSetting, 2),
                'price_source' => $priceSource,
            ];
        }

        return [
            'type' => 'product_exact',
            'product' => [
                'id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) ($product->product_code ?? ''),
                'barcode' => $product->barcode !== null ? (string) $product->barcode : null,
                'sale_price' => (float) ($product->product_price ?? 0),
                'serial_number_required' => (bool) $product->serial_number_required,
                'is_bundle_parent' => $isBundleParent,
                'resolved_via' => $conversion !== null ? 'conversion_barcode' : 'product_barcode',
                'conversion' => $conversionMetadata,
            ],
        ];
    }

    private function isBundleParent(int $productId, int $settingId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        return DB::table('product_bundles')
            ->where('parent_product_id', $productId)
            ->where('setting_id', $settingId)
            ->exists();
    }

    /**
     * Check if product has stock in any of the allowed locations.
     */
    private function hasStockInAllowedLocations(int $productId, array $allowedLocationIds): bool
    {
        if (empty($allowedLocationIds)) {
            return false;
        }

        return DB::table('product_stocks')
            ->where('product_id', $productId)
            ->whereIn('location_id', $allowedLocationIds)
            ->where('quantity', '>', 0)
            ->exists();
    }

    /**
     * Check if product has a price row for the given setting.
     */
    private function hasPriceForSetting(int $productId, int $settingId): bool
    {
        return DB::table('product_prices')
            ->where('product_id', $productId)
            ->where('setting_id', $settingId)
            ->exists();
    }
}

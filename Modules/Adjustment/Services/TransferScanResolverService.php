<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Sale\Support\PendingDispatchSerialGuard;
use Modules\Setting\Entities\Location;
use InvalidArgumentException;

class TransferScanResolverService
{
    /**
     * Resolve scan query to determine the action type.
     */
    public function resolve(int $settingId, string $query, int $originLocationId): array
    {
        if (! $query || $query === '') {
            return ['type' => 'none'];
        }

        // Validate origin location belongs to current tenant
        $originLocation = Location::where('id', $originLocationId)
            ->where('setting_id', $settingId)
            ->first();

        if (!$originLocation) {
            throw new InvalidArgumentException("Invalid origin location for current tenant.");
        }

        $allowedLocationIds = [$originLocationId];
        $query = trim($query);
        $queryLower = strtolower($query);

        // 1. Exact barcode match on products
        $productByBarcode = Product::query()
            ->active()
            ->where('setting_id', $settingId)
            ->where('stock_managed', true)
            ->whereRaw('LOWER(barcode) = ?', [$queryLower])
            ->first();

        if ($productByBarcode && $this->hasStockInAllowedLocations($productByBarcode->id, $allowedLocationIds)) {
            return $this->formatProductExact($productByBarcode, $settingId);
        }

        // 2. Exact barcode match on product_unit_conversions
        $unitConversionBarcode = ProductUnitConversion::query()
            ->whereHas('product', fn ($q) => $q->where('setting_id', $settingId)->where('stock_managed', true))
            ->whereRaw('LOWER(barcode) = ?', [$queryLower])
            ->with(['product', 'unit'])
            ->first();

        if ($unitConversionBarcode && $unitConversionBarcode->product && $this->hasStockInAllowedLocations($unitConversionBarcode->product->id, $allowedLocationIds)) {
            return $this->formatProductExact($unitConversionBarcode->product, $settingId, $unitConversionBarcode);
        }

        // 3. Exact serial number match
        $serialRecord = ProductSerialNumber::query()
            ->where('serial_number', $query)
            ->where('status', 'ACTIVE')
            ->whereNull('dispatch_detail_id')
            ->whereIn('location_id', $allowedLocationIds)
            ->whereHas('product', fn ($q) => $q->where('setting_id', $settingId))
            ->with('product')
            ->first();

        if (
            $serialRecord
            && ! PendingDispatchSerialGuard::isReserved((string) $serialRecord->serial_number)
            && $serialRecord->product
            && $serialRecord->product->stock_managed
            && (int) $serialRecord->product->setting_id === $settingId
        ) {
            return [
                'type' => 'serial_exact',
                'serial' => [
                    'id' => (int) $serialRecord->id,
                    'serial_number' => (string) $serialRecord->serial_number,
                    'product_id' => (int) $serialRecord->product_id,
                    'tax_id' => $serialRecord->tax_id !== null ? (int) $serialRecord->tax_id : null,
                    'location_id' => (int) $serialRecord->location_id,
                    'is_broken' => (bool) $serialRecord->is_broken,
                ],
                'product' => [
                    'id' => (int) $serialRecord->product->id,
                    'product_name' => (string) $serialRecord->product->product_name,
                    'product_code' => (string) ($serialRecord->product->product_code ?? ''),
                    'barcode' => $serialRecord->product->barcode !== null ? (string) $serialRecord->product->barcode : null,
                    'serial_number_required' => (bool) $serialRecord->product->serial_number_required,
                ],
            ];
        }

        // 4. If no exact matches, return none
        return ['type' => 'none'];
    }

    private function formatProductExact(Product $product, int $settingId, ?ProductUnitConversion $conversion = null): array
    {
        $conversionMetadata = null;

        if ($conversion !== null) {
            $unitName = $conversion->unit ? (string) $conversion->unit->name : 'Unit';

            $conversionMetadata = [
                'id' => (int) $conversion->id,
                'unit_id' => (int) $conversion->unit_id,
                'unit_name' => $unitName,
                'conversion_factor' => (float) $conversion->conversion_factor,
            ];
        }

        return [
            'type' => 'product_exact',
            'product' => [
                'id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) ($product->product_code ?? ''),
                'barcode' => $product->barcode !== null ? (string) $product->barcode : null,
                'serial_number_required' => (bool) $product->serial_number_required,
                'resolved_via' => $conversion !== null ? 'conversion_barcode' : 'product_barcode',
                'conversion' => $conversionMetadata,
            ],
        ];
    }

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
}

<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;

class PosScanResolverService
{
    /**
     * Resolve scan query to determine the action type.
     *
     * Resolution order:
     * 1. Exact barcode match on products
     * 2. Exact barcode match on product_unit_conversions
     * 3. Exact SKU/product_code match
     * 4. Exact serial number match
     * 5. If multiple matches, return ambiguous
     * 6. If no match, return none
     *
     * @param  int  $settingId
     * @param  string  $query
     * @return array{
     *     type: 'product_exact'|'serial_exact'|'ambiguous'|'none',
     *     product?: array{id: int, name: string, code: string, barcode: ?string, sale_price: float, serial_number_required: bool},
     *     serial?: array{serial_number: string, product_id: int, tax_id: ?int, location_id: int},
     *     results?: array<int, array<string, mixed>>
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
            ->where('setting_id', $settingId)
            ->where('stock_managed', true)
            ->whereRaw('LOWER(barcode) = ?', [$queryLower])
            ->first();

        if ($productByBarcode) {
            return $this->formatProductExact($productByBarcode, $settingId);
        }

        // 2. Exact barcode match on product_unit_conversions
        $unitConversionBarcode = ProductUnitConversion::query()
            ->where('setting_id', $settingId)
            ->whereRaw('LOWER(barcode) = ?', [$queryLower])
            ->with(['product' => fn ($q) => $q->where('setting_id', $settingId)])
            ->first();

        if ($unitConversionBarcode && $unitConversionBarcode->product) {
            return $this->formatProductExact($unitConversionBarcode->product, $settingId);
        }

        // 3. Exact SKU/product_code match
        $productByCode = Product::query()
            ->where('setting_id', $settingId)
            ->where('stock_managed', true)
            ->whereRaw('LOWER(product_code) = ?', [$queryLower])
            ->first();

        if ($productByCode) {
            return $this->formatProductExact($productByCode, $settingId);
        }

        // 4. Exact serial number match
        $serialRecord = ProductSerialNumber::query()
            ->where('serial_number', $query)
            ->where('status', 'ACTIVE')
            ->whereNull('dispatch_detail_id')
            ->whereIn('location_id', $allowedLocationIds)
            ->with(['product' => fn ($q) => $q->where('setting_id', $settingId)])
            ->first();

        if ($serialRecord && $serialRecord->product) {
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
                ],
            ];
        }

        // 5. If no exact matches, return none (ambiguous search not needed for scanner)
        return ['type' => 'none'];
    }

    /**
     * Format product for exact match response.
     *
     * @param  Product  $product
     * @param  int  $settingId
     * @return array{type: string, product: array<string, mixed>}
     */
    private function formatProductExact(Product $product, int $settingId): array
    {
        return [
            'type' => 'product_exact',
            'product' => [
                'id' => (int) $product->id,
                'name' => (string) $product->product_name,
                'code' => (string) ($product->product_code ?? ''),
                'barcode' => $product->barcode !== null ? (string) $product->barcode : null,
                'sale_price' => (float) ($product->product_price ?? 0),
                'serial_number_required' => (bool) $product->serial_number_required,
            ],
        ];
    }
}

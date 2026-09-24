<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Sale\Support\PendingDispatchSerialGuard;
use Modules\Setting\Entities\Location;
use InvalidArgumentException;
use Modules\Adjustment\Services\TransferStockVisibility;

class TransferScanResolverService
{
    /**
     * Resolve scan query across exact product barcodes, conversion barcodes, and serials.
     *
     * Returns:
     * - ['status' => 'not_found', 'type' => 'none', 'message' => ...]
     * - ['status' => 'rejected', 'type' => 'serial_rejected', 'message' => ...]
     * - ['status' => 'resolved', 'type' => 'product_exact'|'serial_exact', 'candidate' => [...], ...]
     * - ['status' => 'ambiguous', 'type' => 'ambiguous', 'candidates' => [...]]
     *
     * @param int $settingId
     * @param string $query
     * @param int $originLocationId
     * @param bool|null $isBrokenMode If specified, validates condition compatibility.
     * @param bool $allowZeroStock If true (e.g. forward dispatch observation), permits scanning products even if system stock is 0.
     * @return array
     */
    public function resolve(int $settingId, string $query, int $originLocationId, ?bool $isBrokenMode = null, bool $allowZeroStock = false): array
    {
        if (! $query || $query === '') {
            return [
                'status' => 'not_found',
                'type' => 'none',
            ];
        }

        // Validate origin location belongs to current tenant
        $originLocation = Location::where('id', $originLocationId)
            ->where('setting_id', $settingId)
            ->first();

        if (!$originLocation) {
            throw new InvalidArgumentException("Invalid origin location for current tenant.");
        }

        return $this->resolveWithin([$originLocationId], $settingId, $query, $isBrokenMode, $allowZeroStock, true);
    }

    /**
     * Workflow version 3 entry: resolves across every active location of
     * every business, with no origin prerequisite. The same canonical
     * barcode, conversion, serial and ambiguity rules apply, but serial
     * candidates carry no location or business provenance, and no scan is
     * resolved to a first match when identifiers collide.
     */
    public function resolveAcrossBusinesses(string $query, bool $isBrokenMode): array
    {
        if (trim($query) === '') {
            return ['status' => 'not_found', 'type' => 'none'];
        }

        $allowedLocationIds = Location::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Exact identifiers only: a scan never falls through to name search.
        return $this->resolveWithin($allowedLocationIds, 0, $query, $isBrokenMode, false, false, false);
    }

    /**
     * Workflow version 3 deliberate product search across every active
     * location. Every whitespace token must match the product name, code,
     * barcode, category or brand (same rules as the legacy search modal).
     * Returns identity/display fields only -- no stock, location or business
     * provenance -- for explicit selection; callers must never auto-apply a
     * result, even a single one.
     *
     * @return array<int, array>
     */
    public function searchAcrossBusinesses(string $query, bool $isBrokenMode, int $limit = 20): array
    {
        $tokens = array_values(array_filter(explode(' ', strtolower(trim($query))), fn ($token) => $token !== ''));
        if ($tokens === []) {
            return [];
        }

        $allowedLocationIds = Location::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($allowedLocationIds === []) {
            return [];
        }

        $products = Product::query()
            ->active()
            ->where('stock_managed', true)
            ->with(['category', 'brand', 'baseUnit'])
            ->where(function ($outer) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';
                    $outer->where(function ($sub) use ($like) {
                        $sub->whereRaw('LOWER(product_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(product_code) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(barcode) LIKE ?', [$like])
                            ->orWhereHas('category', fn ($c) => $c->whereRaw('LOWER(category_name) LIKE ?', [$like]))
                            ->orWhereHas('brand', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', [$like]));
                    });
                }
            })
            ->whereHas('productStocks', function ($stock) use ($allowedLocationIds, $isBrokenMode) {
                $stock->whereIn('location_id', $allowedLocationIds);
                if ($isBrokenMode) {
                    $stock->whereRaw('(COALESCE(broken_quantity_tax, 0) + COALESCE(broken_quantity_non_tax, 0)) > 0');
                } else {
                    $stock->where(function ($sub) {
                        $sub->whereRaw('(COALESCE(quantity_tax, 0) + COALESCE(quantity_non_tax, 0)) > 0')
                            ->orWhereRaw('(COALESCE(quantity, 0) - COALESCE(broken_quantity_tax, 0) - COALESCE(broken_quantity_non_tax, 0)) > 0');
                    });
                }
            })
            ->orderBy('product_name')
            ->limit($limit)
            ->get();

        return $products->map(fn (Product $product) => [
            'type'        => 'product',
            'description' => "{$product->product_name} | {$product->product_code}",
            'product'     => [
                'id'                     => (int) $product->id,
                'product_name'           => (string) $product->product_name,
                'product_code'           => (string) $product->product_code,
                'barcode'                => $product->barcode,
                'base_unit'              => $product->baseUnit?->unit_name ?? $product->baseUnit?->name ?? 'Unit',
                'serial_number_required' => (bool) $product->serial_number_required,
                'category_name'          => $product->category?->category_name,
                'brand_name'             => $product->brand?->name,
            ],
        ])->values()->all();
    }

    private function resolveWithin(array $allowedLocationIds, int $settingId, string $query, ?bool $isBrokenMode, bool $allowZeroStock, bool $projectLocations, bool $textFallback = true): array
    {
        $query = trim($query);
        $queryLower = strtolower($query);
        $canViewSystemStock = TransferStockVisibility::canView();

        $candidates = [];
        $rejections = [];

        // 1. Exact barcode match on active, stock-managed products with eligible stock at allowed origin
        $productMatches = Product::query()
            ->active()
            ->where('stock_managed', true)
            ->whereRaw('LOWER(barcode) = ?', [$queryLower])
            ->with(['baseUnit', 'conversions.unit'])
            ->get();

        foreach ($productMatches as $productByBarcode) {
            $hasStock = $this->hasStockInAllowedLocations($productByBarcode->id, $allowedLocationIds, $isBrokenMode);
            if ($hasStock || ($allowZeroStock && (int)$productByBarcode->setting_id === $settingId)) {
                $candidates[] = $this->formatProductExact($productByBarcode, $settingId);
            }
        }

        // 2. Exact barcode match on product_unit_conversions (active, stock-managed product with eligible stock at allowed origin)
        $conversionMatches = ProductUnitConversion::query()
            ->whereHas('product', fn ($q) => $q->active()->where('stock_managed', true))
            ->whereRaw('LOWER(barcode) = ?', [$queryLower])
            ->with(['product.baseUnit', 'unit'])
            ->get();

        foreach ($conversionMatches as $unitConversionBarcode) {
            if ($unitConversionBarcode->product) {
                $hasStock = $this->hasStockInAllowedLocations($unitConversionBarcode->product->id, $allowedLocationIds, $isBrokenMode);
                if ($hasStock || ($allowZeroStock && (int)$unitConversionBarcode->product->setting_id === $settingId)) {
                    $factor = (float) $unitConversionBarcode->conversion_factor;
                    // Only valid positive whole numbers are eligible conversion candidates
                    if ($factor > 0 && abs($factor - round($factor)) < 1e-6) {
                        $candidates[] = $this->formatProductExact($unitConversionBarcode->product, $settingId, $unitConversionBarcode);
                    }
                }
            }
        }

        // 3. Exact serial number match
        $normalizedSerial = ProductSerialNumber::normalize($query);
        $serialMatches = ProductSerialNumber::query()
            ->where('serial_number', $normalizedSerial)
            ->whereIn('location_id', $allowedLocationIds)
            ->whereHas('product', fn ($q) => $q->active()->where('stock_managed', true))
            ->with(['product.baseUnit', 'location'])
            ->get();

        foreach ($serialMatches as $serialRecord) {
            if (PendingDispatchSerialGuard::isReserved((string) $serialRecord->serial_number)) {
                $rejections[] = $canViewSystemStock
                    ? 'Nomor seri sedang dalam proses pengiriman.'
                    : 'Nomor seri tidak dapat digunakan.';
                continue;
            }

            if ($serialRecord->is_in_return_process) {
                $rejections[] = $canViewSystemStock
                    ? 'Nomor seri sedang dalam proses retur.'
                    : 'Nomor seri tidak dapat digunakan.';
                continue;
            }

            if ($isBrokenMode !== null) {
                if ($isBrokenMode) {
                    if (!$serialRecord->isAvailableBroken()) {
                        $rejections[] = $canViewSystemStock
                            ? 'Nomor Seri tidak berstatus rusak atau tidak tersedia untuk transfer barang rusak.'
                            : 'Nomor seri tidak dapat digunakan untuk transfer ini.';
                        continue;
                    }
                } else {
                    if (!$serialRecord->isSellable()) {
                        $rejections[] = $canViewSystemStock
                            ? 'Nomor Seri tidak siap jual atau berstatus rusak/hilang untuk transfer mode normal.'
                            : 'Nomor seri tidak dapat digunakan untuk transfer ini.';
                        continue;
                    }
                }
            } else {
                if (!$serialRecord->isSellable() && !$serialRecord->isAvailableBroken()) {
                    $rejections[] = $canViewSystemStock
                        ? 'Nomor Seri sudah tidak tersedia atau berstatus tidak valid.'
                        : 'Nomor seri tidak dapat digunakan.';
                    continue;
                }
            }

            $canonicalBroken = $serialRecord->isAvailableBroken();

            $serialProjection = [
                'id' => (int) $serialRecord->id,
                'serial_number' => (string) $serialRecord->serial_number,
                'product_id' => (int) $serialRecord->product_id,
                'tax_id' => $serialRecord->tax_id !== null ? (int) $serialRecord->tax_id : null,
                'location_id' => (int) $serialRecord->location_id,
                'location_name' => $serialRecord->location?->name ?? 'Lokasi Asal',
                'is_broken' => $canonicalBroken,
            ];

            if (! $projectLocations) {
                // Location-free projection: identity only, no provenance.
                $serialProjection = array_intersect_key($serialProjection, array_flip(['id', 'serial_number', 'product_id']));
            }

            $candidates[] = [
                'type' => 'serial',
                'description' => "Nomor Seri: {$serialRecord->serial_number} - {$serialRecord->product->product_name}",
                'serial' => $serialProjection,
                'product' => [
                    'id' => (int) $serialRecord->product->id,
                    'product_name' => (string) $serialRecord->product->product_name,
                    'product_code' => (string) ($serialRecord->product->product_code ?? ''),
                    'barcode' => $serialRecord->product->barcode !== null ? (string) $serialRecord->product->barcode : null,
                    'base_unit' => $serialRecord->product->baseUnit?->unit_name ?? $serialRecord->product->baseUnit?->name ?? 'Unit',
                    'serial_number_required' => (bool) $serialRecord->product->serial_number_required,
                ],
            ];
        }

        // 4. Fallback search on product code or name (tokenized) if no exact matches found
        if ($textFallback && count($candidates) === 0 && empty($rejections)) {
            $tokens = array_filter(explode(' ', $queryLower));
            if (!empty($tokens)) {
                $textMatchesQuery = Product::query()
                    ->active()
                    ->where('stock_managed', true)
                    ->where(function ($q) use ($tokens, $queryLower) {
                        $q->whereRaw('LOWER(product_code) = ?', [$queryLower])
                            ->orWhere(function ($sub) use ($tokens) {
                                foreach ($tokens as $token) {
                                    $sub->whereRaw('LOWER(product_name) LIKE ?', ['%' . $token . '%']);
                                }
                            });
                    })
                    ->with(['baseUnit', 'conversions.unit']);

                if (! $allowZeroStock) {
                    $textMatchesQuery->whereHas('productStocks', function ($stockQuery) use ($allowedLocationIds, $isBrokenMode) {
                        $stockQuery->whereIn('location_id', $allowedLocationIds);
                        if ($isBrokenMode === true) {
                            $stockQuery->whereRaw('(COALESCE(broken_quantity_tax, 0) + COALESCE(broken_quantity_non_tax, 0)) > 0');
                        } elseif ($isBrokenMode === false) {
                            $stockQuery->where(function ($sub) {
                                $sub->whereRaw('(COALESCE(quantity_tax, 0) + COALESCE(quantity_non_tax, 0)) > 0')
                                    ->orWhereRaw('(COALESCE(quantity, 0) - COALESCE(broken_quantity_tax, 0) - COALESCE(broken_quantity_non_tax, 0)) > 0');
                            });
                        } else {
                            $stockQuery->where('quantity', '>', 0);
                        }
                    });
                } else {
                    $textMatchesQuery->where(function ($q) use ($settingId, $allowedLocationIds, $isBrokenMode) {
                        $q->where('setting_id', $settingId)
                            ->orWhereHas('productStocks', function ($stockQuery) use ($allowedLocationIds, $isBrokenMode) {
                                $stockQuery->whereIn('location_id', $allowedLocationIds);
                                if ($isBrokenMode === true) {
                                    $stockQuery->whereRaw('(COALESCE(broken_quantity_tax, 0) + COALESCE(broken_quantity_non_tax, 0)) > 0');
                                } elseif ($isBrokenMode === false) {
                                    $stockQuery->where(function ($sub) {
                                        $sub->whereRaw('(COALESCE(quantity_tax, 0) + COALESCE(quantity_non_tax, 0)) > 0')
                                            ->orWhereRaw('(COALESCE(quantity, 0) - COALESCE(broken_quantity_tax, 0) - COALESCE(broken_quantity_non_tax, 0)) > 0');
                                    });
                                } else {
                                    $stockQuery->where('quantity', '>', 0);
                                }
                            });
                    });
                }

                $textMatches = $textMatchesQuery->limit(10)->get();

                foreach ($textMatches as $productByText) {
                    $candidates[] = $this->formatProductExact($productByText, $settingId);
                }
            }
        }

        if (count($candidates) === 0) {
            if (!empty($rejections)) {
                return [
                    'status' => 'rejected',
                    'type' => 'serial_rejected',
                    'message' => $rejections[0],
                ];
            }

            return [
                'status' => 'not_found',
                'type' => 'none',
                'message' => "Barcode atau nomor seri '{$query}' tidak ditemukan.",
            ];
        }

        if (count($candidates) === 1) {
            $match = $candidates[0];
            $matchType = $match['type'] === 'serial' ? 'serial_exact' : 'product_exact';

            return array_merge($match, [
                'status' => 'resolved',
                'type' => $matchType,
                'candidate' => $match,
            ]);
        }

        return [
            'status' => 'ambiguous',
            'type' => 'ambiguous',
            'candidates' => $candidates,
        ];
    }

    private function formatProductExact(Product $product, int $settingId, ?ProductUnitConversion $conversion = null): array
    {
        $conversionMetadata = null;
        $desc = "Barcode Produk: {$product->product_name} ({$product->product_code})";

        if ($conversion !== null) {
            $unitName = $conversion->unit ? (string) $conversion->unit->name : 'Unit';
            $factor = (float) $conversion->conversion_factor;

            $conversionMetadata = [
                'id' => (int) $conversion->id,
                'unit_id' => (int) $conversion->unit_id,
                'unit_name' => $unitName,
                'conversion_factor' => $factor,
            ];

            $desc = "Barcode Konversi ({$unitName} x{$factor}): {$product->product_name}";
        }

        return [
            'type' => $conversion !== null ? 'conversion' : 'product',
            'description' => $desc,
            'product' => [
                'id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) ($product->product_code ?? ''),
                'barcode' => $product->barcode !== null ? (string) $product->barcode : null,
                'base_unit' => $product->baseUnit?->unit_name ?? $product->baseUnit?->name ?? 'Unit',
                'serial_number_required' => (bool) $product->serial_number_required,
                'resolved_via' => $conversion !== null ? 'conversion_barcode' : 'product_barcode',
                'conversion' => $conversionMetadata,
            ],
            'conversion' => $conversionMetadata,
        ];
    }

    private function hasStockInAllowedLocations(int $productId, array $allowedLocationIds, ?bool $isBrokenMode = null): bool
    {
        if (empty($allowedLocationIds)) {
            return false;
        }

        $query = DB::table('product_stocks')
            ->where('product_id', $productId)
            ->whereIn('location_id', $allowedLocationIds);

        if ($isBrokenMode === true) {
            return $query->whereRaw('(COALESCE(broken_quantity_tax, 0) + COALESCE(broken_quantity_non_tax, 0)) > 0')->exists();
        }

        if ($isBrokenMode === false) {
            return $query->where(function ($sub) {
                $sub->whereRaw('(COALESCE(quantity_tax, 0) + COALESCE(quantity_non_tax, 0)) > 0')
                    ->orWhereRaw('(COALESCE(quantity, 0) - COALESCE(broken_quantity_tax, 0) - COALESCE(broken_quantity_non_tax, 0)) > 0');
            })->exists();
        }

        return $query->where('quantity', '>', 0)->exists();
    }
}

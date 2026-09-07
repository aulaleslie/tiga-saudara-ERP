<?php

namespace Modules\Adjustment\Services;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;

class AdjustmentProductResolver
{
    /**
     * Search stock-managed active products using tokenized globalSearch without is_sold filter.
     *
     * @param string $term
     * @param int $limit
     * @return array
     */
    public function searchProducts(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $products = Product::query()
            ->active()
            ->where('stock_managed', true)
            ->globalSearch($term)
            ->with(['baseUnit', 'conversions.unit', 'conversions.baseUnit'])
            ->limit($limit)
            ->get();

        return $products->map(function (Product $product) {
            return $this->normalizeProductData($product);
        })->toArray();
    }

    /**
     * Normalize product data authoritatively from DB.
     */
    public function normalizeProductData(Product $product): array
    {
        $baseUnit = $product->baseUnit;
        $unitName = $baseUnit->unit_name ?? $baseUnit->name ?? $baseUnit->short_name ?? '';

        $conversions = $product->conversions->map(function (ProductUnitConversion $c) {
            return [
                'id' => $c->id,
                'unit_id' => $c->unit_id,
                'unit_name' => $c->unit?->unit_name ?? $c->unit?->name ?? '',
                'conversion_factor' => (float) $c->conversion_factor,
                'barcode' => $c->barcode,
            ];
        })->toArray();

        return [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'barcode' => $product->barcode,
            'base_unit' => $unitName,
            'serial_number_required' => (bool) $product->serial_number_required,
            'conversions' => $conversions,
        ];
    }

    /**
     * Generate an HMAC signature for a baseline snapshot.
     */
    public function generateBaselineSignature(array $baseline, int $productId, int $locationId): string
    {
        $payload = implode('|', [
            $productId,
            $locationId,
            (int) ($baseline['existing_good_total'] ?? 0),
            (int) ($baseline['existing_good_tax'] ?? 0),
            (int) ($baseline['existing_good_non_tax'] ?? 0),
            (int) ($baseline['existing_bad_total'] ?? 0),
            (int) ($baseline['existing_bad_tax'] ?? 0),
            (int) ($baseline['existing_bad_non_tax'] ?? 0),
            (string) ($baseline['captured_at'] ?? ''),
        ]);

        $key = (string) config('app.key');
        return hash_hmac('sha256', $payload, $key);
    }

    /**
     * Verify whether a baseline snapshot's signature is valid.
     */
    public function verifyBaselineSignature(array $baseline, int $productId, int $locationId): bool
    {
        $signature = (string) ($baseline['signature'] ?? '');
        if ($signature === '') {
            return false;
        }

        $expected = $this->generateBaselineSignature($baseline, $productId, $locationId);
        return hash_equals($expected, $signature);
    }

    /**
     * Store a server-held baseline snapshot in cache and return an opaque token.
     */
    public function storeBaselineSnapshot(
        array $baseline,
        int $productId,
        int $locationId,
        ?int $userId = null,
        ?string $draftSessionId = null,
        ?int $adjustmentId = null
    ): string {
        $token = (string) \Illuminate\Support\Str::uuid();
        $key = "opname_baseline_{$token}";

        $snapshotData = [
            'token' => $token,
            'product_id' => $productId,
            'location_id' => $locationId,
            'user_id' => $userId ?? auth()->id(),
            'draft_session_id' => $draftSessionId,
            'adjustment_id' => $adjustmentId,
            'baseline' => $baseline,
            'captured_at' => $baseline['captured_at'] ?? now()->toIso8601String(),
        ];

        \Illuminate\Support\Facades\Cache::put($key, $snapshotData, now()->addDays(7));

        return $token;
    }

    /**
     * Retrieve a server-held baseline snapshot and validate product, location, user, and session/document association.
     */
    public function getBaselineSnapshot(
        string $token,
        int $productId,
        int $locationId,
        ?int $userId = null,
        ?string $draftSessionId = null,
        ?int $adjustmentId = null
    ): ?array {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $key = "opname_baseline_{$token}";
        $snapshot = \Illuminate\Support\Facades\Cache::get($key);

        if (!is_array($snapshot) || empty($snapshot['baseline'])) {
            return null;
        }

        // Validate product and location association
        if ((int) ($snapshot['product_id'] ?? 0) !== $productId || (int) ($snapshot['location_id'] ?? 0) !== $locationId) {
            return null;
        }

        // Validate user/session ownership if user ID is specified and user is not Super Admin
        $currentUserId = $userId ?? auth()->id();
        $snapshotUserId = $snapshot['user_id'] ?? null;
        $currentUser = auth()->user();
        $isSuperAdmin = $currentUser ? $currentUser->hasRole('Super Admin') : false;

        if (!$isSuperAdmin && $snapshotUserId !== null && $currentUserId !== null && (int) $snapshotUserId !== (int) $currentUserId) {
            return null;
        }

        // Validate document or counting session binding:
        $snapshotAdjustmentId = $snapshot['adjustment_id'] ?? null;

        // 1. If snapshot has already been bound to an adjustment:
        //    - If caller specifies an adjustmentId, it must match the snapshot's bound adjustment_id
        //    - If caller is creating a new adjustment ($adjustmentId is null), rejecting reuse of an already-saved snapshot!
        if ($snapshotAdjustmentId !== null) {
            if ($adjustmentId === null || (int) $snapshotAdjustmentId !== (int) $adjustmentId) {
                return null;
            }
        }

        // 2. If caller specifies an adjustmentId, snapshot cannot be bound to a different adjustment
        if ($adjustmentId !== null && $snapshotAdjustmentId !== null && (int) $snapshotAdjustmentId !== (int) $adjustmentId) {
            return null;
        }

        // 3. If snapshot is bound to a draft_session_id, incoming draftSessionId must match
        $snapshotDraftSessionId = $snapshot['draft_session_id'] ?? null;
        if ($snapshotDraftSessionId !== null && (string) $snapshotDraftSessionId !== (string) ($draftSessionId ?? '')) {
            return null;
        }

        if ($draftSessionId !== null && $snapshotDraftSessionId !== null && (string) $snapshotDraftSessionId !== (string) $draftSessionId) {
            return null;
        }

        return $snapshot['baseline'];
    }

    /**
     * Bind a server-held baseline snapshot permanently to a created or existing adjustment ID.
     * Atomically claims ownership and rejects if already claimed by a different adjustment.
     *
     * @param string $token
     * @param int $adjustmentId
     * @param array|null $persistedBaselineFallback Persisted baseline data from document to restore cache if expired/cleared
     * @return string|false 'claimed' if newly bound, 'already_owned' if already bound to this adjustment, or false if rejected
     */
    public function bindSnapshotToAdjustment(string $token, int $adjustmentId, ?array $persistedBaselineFallback = null): string|false
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $key = "opname_baseline_{$token}";
        $lockKey = "lock_opname_baseline_{$token}";

        // Use atomic cache lock to prevent race conditions during concurrent claims
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 5);

        return $lock->get(function () use ($key, $token, $adjustmentId, $persistedBaselineFallback) {
            $snapshot = \Illuminate\Support\Facades\Cache::get($key);

            // If snapshot is missing from cache (e.g. expired after 7 days or cache cleared)
            // but the baseline is authoritatively persisted in this adjustment's document, restore the cache entry
            if (!is_array($snapshot)) {
                if ($persistedBaselineFallback !== null && !empty($persistedBaselineFallback)) {
                    $snapshot = [
                        'token' => $token,
                        'product_id' => (int) ($persistedBaselineFallback['product_id'] ?? 0),
                        'location_id' => (int) ($persistedBaselineFallback['location_id'] ?? 0),
                        'user_id' => null,
                        'draft_session_id' => null,
                        'adjustment_id' => $adjustmentId,
                        'baseline' => $persistedBaselineFallback,
                        'captured_at' => $persistedBaselineFallback['captured_at'] ?? now()->toIso8601String(),
                    ];
                    \Illuminate\Support\Facades\Cache::put($key, $snapshot, now()->addDays(7));
                    return 'already_owned';
                }
                return false;
            }

            $currentAdjustmentId = $snapshot['adjustment_id'] ?? null;
            if ($currentAdjustmentId !== null && (int) $currentAdjustmentId !== $adjustmentId) {
                return false; // Owned by a different adjustment!
            }

            $wasAlreadyOwned = ($currentAdjustmentId !== null && (int) $currentAdjustmentId === $adjustmentId);

            $snapshot['adjustment_id'] = $adjustmentId;
            \Illuminate\Support\Facades\Cache::put($key, $snapshot, now()->addDays(7));

            return $wasAlreadyOwned ? 'already_owned' : 'claimed';
        });
    }

    /**
     * Release adjustment binding from a baseline snapshot (e.g. on transaction rollback).
     */
    public function releaseSnapshotBinding(string $token, int $adjustmentId): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }

        $key = "opname_baseline_{$token}";
        $lockKey = "lock_opname_baseline_{$token}";

        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 5);
        $lock->get(function () use ($key, $adjustmentId) {
            $snapshot = \Illuminate\Support\Facades\Cache::get($key);

            if (is_array($snapshot) && isset($snapshot['adjustment_id']) && (int) $snapshot['adjustment_id'] === $adjustmentId) {
                $snapshot['adjustment_id'] = null;
                \Illuminate\Support\Facades\Cache::put($key, $snapshot, now()->addDays(7));
            }
        });
    }

    /**
     * Capture baseline stock for a product at a given location and store server-held snapshot.
     */
    public function captureBaseline(
        int $productId,
        int $locationId,
        ?int $userId = null,
        ?string $draftSessionId = null,
        ?int $adjustmentId = null
    ): array {
        $stock = ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        $goodTax = (int) ($stock->quantity_tax ?? 0);
        $goodNonTax = (int) ($stock->quantity_non_tax ?? 0);
        $badTax = (int) ($stock->broken_quantity_tax ?? 0);
        $badNonTax = (int) ($stock->broken_quantity_non_tax ?? 0);

        $baseline = [
            'existing_good_total' => $goodTax + $goodNonTax,
            'existing_good_tax' => $goodTax,
            'existing_good_non_tax' => $goodNonTax,
            'existing_bad_total' => $badTax + $badNonTax,
            'existing_bad_tax' => $badTax,
            'existing_bad_non_tax' => $badNonTax,
            'captured_at' => now()->toIso8601String(),
        ];

        $baseline['signature'] = $this->generateBaselineSignature($baseline, $productId, $locationId);

        // Store authoritative server-side snapshot and issue opaque token
        $token = $this->storeBaselineSnapshot(
            $baseline,
            $productId,
            $locationId,
            $userId,
            $draftSessionId,
            $adjustmentId
        );
        $baseline['token'] = $token;

        return $baseline;
    }

    /**
     * Resolve scanned text across primary barcodes, conversion barcodes, and existing serials.
     *
     * Returns:
     * - ['status' => 'not_found']
     * - ['status' => 'resolved', 'match_type' => 'product'|'conversion'|'serial', 'candidate' => [...]]
     * - ['status' => 'ambiguous', 'candidates' => [...]]
     *
     * @param string $rawCode
     * @return array
     */
    public function resolveScan(string $rawCode): array
    {
        $code = trim($rawCode);
        if ($code === '') {
            return ['status' => 'not_found'];
        }

        $candidates = [];

        // 1. Primary barcode match (active, stock-managed)
        $productMatches = Product::query()
            ->active()
            ->where('stock_managed', true)
            ->where('barcode', $code)
            ->with(['baseUnit', 'conversions.unit', 'conversions.baseUnit'])
            ->get();

        foreach ($productMatches as $product) {
            $candidates[] = [
                'type' => 'product',
                'description' => "Barcode Produk: {$product->product_name} ({$product->product_code})",
                'product' => $this->normalizeProductData($product),
            ];
        }

        // 2. Conversion barcode match (active, stock-managed product)
        $conversionMatches = ProductUnitConversion::query()
            ->where('barcode', $code)
            ->whereHas('product', function ($q) {
                $q->active()->where('stock_managed', true);
            })
            ->with(['product.baseUnit', 'product.conversions.unit', 'unit'])
            ->get();

        foreach ($conversionMatches as $conversion) {
            $factor = (float) $conversion->conversion_factor;
            $unitName = $conversion->unit?->unit_name ?? $conversion->unit?->name ?? 'Satuan';
            $candidates[] = [
                'type' => 'conversion',
                'description' => "Barcode Konversi ({$unitName} x{$factor}): {$conversion->product->product_name}",
                'product' => $this->normalizeProductData($conversion->product),
                'conversion' => [
                    'id' => $conversion->id,
                    'conversion_factor' => $factor,
                    'unit_name' => $unitName,
                    'barcode' => $conversion->barcode,
                ],
            ];
        }

        // 3. Serial number match (active, stock-managed product; any location, status, or flag)
        $normalizedSerial = ProductSerialNumber::normalize($code);
        $serialMatches = ProductSerialNumber::query()
            ->where('serial_number', $normalizedSerial)
            ->whereHas('product', function ($q) {
                $q->active()->where('stock_managed', true);
            })
            ->with(['product.baseUnit', 'product.conversions.unit', 'location'])
            ->get();

        foreach ($serialMatches as $serial) {
            $locName = $serial->location?->name ?? 'Lokasi tidak diketahui';
            $candidates[] = [
                'type' => 'serial',
                'description' => "Nomor Seri [{$serial->serial_number}] ({$locName} - Status: {$serial->status}): {$serial->product->product_name}",
                'product' => $this->normalizeProductData($serial->product),
                'serial' => [
                    'id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'location_id' => $serial->location_id,
                    'location_name' => $locName,
                    'status' => $serial->status,
                    'tax_id' => $serial->tax_id,
                ],
            ];
        }

        if (count($candidates) === 0) {
            return ['status' => 'not_found'];
        }

        if (count($candidates) === 1) {
            $match = $candidates[0];
            return [
                'status' => 'resolved',
                'match_type' => $match['type'],
                'candidate' => $match,
            ];
        }

        return [
            'status' => 'ambiguous',
            'candidates' => $candidates,
        ];
    }
}

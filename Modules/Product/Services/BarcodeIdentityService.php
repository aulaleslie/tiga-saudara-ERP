<?php

namespace Modules\Product\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\BarcodeIdentity;
use Modules\Product\Utils\BarcodeUtils;

class BarcodeIdentityService
{
    /**
     * Atomically reserves a barcode identity in the registry.
     *
     * @param string $barcode
     * @param int|null $productId
     * @param int|null $conversionId
     * @return array ['success' => bool, 'conflict' => array|null, 'error' => string|null]
     */
    public function reserve(string $barcode, ?int $productId = null, ?int $conversionId = null): array
    {
        $key = BarcodeUtils::canonicalize($barcode);
        if (!$key) {
            return ['success' => false, 'error' => 'invalid_barcode'];
        }

        try {
            BarcodeIdentity::create([
                'canonical_key' => $key,
                'value' => $barcode,
                'product_id' => $productId,
                'product_unit_conversion_id' => $conversionId,
            ]);

            return ['success' => true];
        } catch (QueryException $e) {
            $errorCode = $e->errorInfo[1] ?? 0;
            // MySQL: 1062, SQLite: 19 (constraint violation)
            if ($errorCode == 1062 || $errorCode == 19) {
                return [
                    'success' => false,
                    'error' => 'duplicate',
                    'conflict' => $this->findConflict($barcode)
                ];
            }
            throw $e;
        }
    }

    /**
     * Releases a barcode identity from the registry.
     *
     * @param string $barcode
     * @param int|null $productId
     * @param int|null $conversionId
     * @return bool
     */
    public function release(string $barcode, ?int $productId = null, ?int $conversionId = null): bool
    {
        $key = BarcodeUtils::canonicalize($barcode);
        if (!$key) {
            return false;
        }

        $query = BarcodeIdentity::where('canonical_key', $key);
        
        if ($productId) {
            $query->where('product_id', $productId);
        } elseif ($conversionId) {
            $query->where('product_unit_conversion_id', $conversionId);
        }

        return $query->delete() > 0;
    }

    /**
     * Replaces an existing barcode identity with a new one.
     */
    public function replace(string $oldBarcode, string $newBarcode, ?int $productId = null, ?int $conversionId = null): array
    {
        $oldKey = BarcodeUtils::canonicalize($oldBarcode);
        $newKey = BarcodeUtils::canonicalize($newBarcode);

        if (!$newKey) {
            return ['success' => false, 'error' => 'invalid_barcode'];
        }

        if ($oldKey === $newKey) {
            return ['success' => true];
        }

        return DB::transaction(function () use ($oldKey, $newKey, $oldBarcode, $newBarcode, $productId, $conversionId) {
            if ($oldKey) {
                $this->release($oldBarcode, $productId, $conversionId);
            }

            return $this->reserve($newBarcode, $productId, $conversionId);
        });
    }

    /**
     * Resolves a duplicate identity to the owning product or product conversion
     * and returns context for user-facing errors.
     *
     * @param string $barcode
     * @return array|null
     */
    public function findConflict(string $barcode): ?array
    {
        $key = BarcodeUtils::canonicalize($barcode);
        if (!$key) {
            return null;
        }

        $identity = BarcodeIdentity::with(['product', 'conversion.product', 'conversion.unit'])
            ->where('canonical_key', $key)
            ->first();

        if (!$identity) {
            return null; // The conflict was transient or deleted
        }

        if ($identity->product_unit_conversion_id && $identity->conversion) {
            return [
                'type' => 'conversion',
                'product_id' => $identity->conversion->product_id,
                'product_name' => $identity->conversion->product->product_name ?? 'Unknown',
                'product_code' => $identity->conversion->product->product_code ?? 'Unknown',
                'conversion_id' => $identity->product_unit_conversion_id,
                'unit_name' => $identity->conversion->unit->name ?? 'Unknown',
                'unit_short_name' => $identity->conversion->unit->short_name ?? 'Unknown',
            ];
        }

        if ($identity->product_id && $identity->product) {
            return [
                'type' => 'product',
                'product_id' => $identity->product_id,
                'product_name' => $identity->product->product_name,
                'product_code' => $identity->product->product_code,
            ];
        }

        return ['type' => 'unknown'];
    }
}

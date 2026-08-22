<?php

namespace App\Services;

use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;

class DocumentReferenceService
{
    /**
     * Determine if the sequence allocator is enabled and schema is ready for a given document type.
     * Evaluates the family rollout flag and confirms document_sequences table readiness.
     */
    public static function isSequenceEnabled(DocumentType $documentType): bool
    {
        $enabled = match ($documentType) {
            DocumentType::PURCHASE => (bool) config('app.sequence_purchase_enabled', env('SEQUENCE_PURCHASE_ENABLED', false)),
            DocumentType::SALE => (bool) config('app.sequence_sale_enabled', env('SEQUENCE_SALE_ENABLED', false)),
        };

        if (!$enabled) {
            return false;
        }

        // Verify schema readiness (document_sequences table exists)
        if (!\Illuminate\Support\Facades\Schema::hasTable('document_sequences')) {
            return false;
        }

        return true;
    }

    /**
     * Atomically allocate a reference and create a Purchase document.
     *
     * @param array $data Purchase attributes (must include 'date' and 'setting_id')
     * @return Purchase The newly created purchase with allocated reference
     */
    public static function createPurchaseWithReference(array $data): Purchase
    {
        if (!self::isSequenceEnabled(DocumentType::PURCHASE)) {
            throw new \RuntimeException("Sequence allocation for Purchase is disabled or schema unavailable.");
        }

        $allocator = app(DocumentSequenceAllocator::class);
        $settingId = (int) $data['setting_id'];
        $purchaseDate = isset($data['date']) ? Carbon::parse($data['date']) : now();

        $namespace = $allocator->buildNamespace(DocumentType::PURCHASE, $settingId, $purchaseDate);

        return $allocator->executeWithConflictRetry($namespace, function () use ($allocator, $namespace, &$data) {
            $allocation = $allocator->allocate($namespace);
            $data['reference'] = $allocation->reference;

            return Purchase::create($data);
        });
    }

    /**
     * Atomically allocate a reference and create a Sale document.
     *
     * @param array $data Sale attributes (must include 'date' and 'setting_id')
     * @return Sale The newly created sale with allocated reference
     */
    public static function createSaleWithReference(array $data): Sale
    {
        if (!self::isSequenceEnabled(DocumentType::SALE)) {
            throw new \RuntimeException("Sequence allocation for Sale is disabled or schema unavailable.");
        }

        $allocator = app(DocumentSequenceAllocator::class);
        $settingId = (int) $data['setting_id'];
        $saleDate = isset($data['date']) ? Carbon::parse($data['date']) : now();

        $namespace = $allocator->buildNamespace(DocumentType::SALE, $settingId, $saleDate);

        return $allocator->executeWithConflictRetry($namespace, function () use ($allocator, $namespace, &$data) {
            $allocation = $allocator->allocate($namespace);
            $data['reference'] = $allocation->reference;

            return Sale::create($data);
        });
    }

    /**
     * Atomically move a Purchase to a new Setting and assign it a new reference.
     *
     * @param Purchase $purchase The purchase to move (must be in DRAFTED status)
     * @param int $targetSettingId The target setting ID
     * @param Carbon $purchaseDate The purchase date for reference calculation
     * @return void (modifies purchase in-place)
     */
    public static function movePurchaseToSetting(Purchase $purchase, int $targetSettingId, Carbon $purchaseDate): void
    {
        if ($purchase->status !== Purchase::STATUS_DRAFTED) {
            throw new \InvalidArgumentException("Only drafted purchases can be moved to another setting.");
        }

        $allocator = app(DocumentSequenceAllocator::class);
        $namespace = $allocator->buildNamespace(DocumentType::PURCHASE, $targetSettingId, $purchaseDate);

        $allocator->executeWithConflictRetry($namespace, function () use ($allocator, $namespace, $purchase, $targetSettingId) {
            $allocation = $allocator->allocate($namespace);

            $purchase->update([
                'setting_id' => $targetSettingId,
                'reference' => $allocation->reference,
            ]);
        });
    }

    /**
     * Atomically move a Sale to a new Setting and assign it a new reference.
     *
     * @param Sale $sale The sale to move (must be in DRAFTED status)
     * @param int $targetSettingId The target setting ID
     * @param Carbon $saleDate The sale date for reference calculation
     * @return void (modifies sale in-place)
     */
    public static function moveSaleToSetting(Sale $sale, int $targetSettingId, Carbon $saleDate): void
    {
        if ($sale->status !== Sale::STATUS_DRAFTED) {
            throw new \InvalidArgumentException("Only drafted sales can be moved to another setting.");
        }

        $allocator = app(DocumentSequenceAllocator::class);
        $namespace = $allocator->buildNamespace(DocumentType::SALE, $targetSettingId, $saleDate);

        $allocator->executeWithConflictRetry($namespace, function () use ($allocator, $namespace, $sale, $targetSettingId) {
            $allocation = $allocator->allocate($namespace);

            $sale->update([
                'setting_id' => $targetSettingId,
                'reference' => $allocation->reference,
            ]);
        });
    }
}

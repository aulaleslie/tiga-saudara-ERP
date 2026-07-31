<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;

class DocumentReferenceService
{
    /**
     * Atomically allocate a reference and create a Purchase document.
     *
     * The Setting row lock is held from allocation through INSERT, ensuring
     * sequential numbering even under concurrent load.
     *
     * @param array $data Purchase attributes (must include 'date' and 'setting_id')
     * @return Purchase The newly created purchase with allocated reference
     */
    public static function createPurchaseWithReference(array $data): Purchase
    {
        return DB::transaction(function () use ($data) {
            $purchaseDate = isset($data['date']) ? Carbon::parse($data['date']) : now();
            $year = $purchaseDate->year;
            $month = $purchaseDate->month;
            $settingId = (int) $data['setting_id'];

            // Lock the Setting row for the entire transaction
            $setting = Setting::whereKey($settingId)->lockForUpdate()->firstOrFail();

            // Fetch the latest reference for this setting, year, and month
            $latestReference = Purchase::withArchived()
                ->where('setting_id', $settingId)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->latest('id')
                ->value('reference');

            // Calculate next number
            $nextNumber = 1;
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            // Build prefix
            $prefix = (optional($setting)->document_prefix ?: '') . '-'
                . (optional($setting)->purchase_prefix_document ?: 'PR');

            // Generate reference
            $reference = make_reference_id($prefix, $year, $month, $nextNumber);

            // Set the reference in data and create the purchase
            // This INSERT happens while the Setting lock is still held
            $data['reference'] = $reference;
            return Purchase::create($data);
        });
    }

    /**
     * Atomically allocate a reference and create a Sale document.
     *
     * The Setting row lock is held from allocation through INSERT, ensuring
     * sequential numbering even under concurrent load.
     *
     * @param array $data Sale attributes (must include 'date' and 'setting_id')
     * @return Sale The newly created sale with allocated reference
     */
    public static function createSaleWithReference(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            $saleDate = isset($data['date']) ? Carbon::parse($data['date']) : now();
            $year = $saleDate->year;
            $month = $saleDate->month;
            $settingId = (int) $data['setting_id'];

            // Lock the Setting row for the entire transaction
            $setting = Setting::whereKey($settingId)->lockForUpdate()->firstOrFail();

            // Fetch the latest reference for this setting, year, and month
            $latestReference = Sale::withArchived()
                ->where('setting_id', $settingId)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->latest('id')
                ->value('reference');

            // Calculate next number
            $nextNumber = 1;
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            // Build prefix
            $prefix = (optional($setting)->document_prefix ?: '') . '-'
                . (optional($setting)->sale_prefix_document ?: 'SL');

            // Generate reference
            $reference = make_reference_id($prefix, $year, $month, $nextNumber);

            // Set the reference in data and create the sale
            // This INSERT happens while the Setting lock is still held
            $data['reference'] = $reference;
            return Sale::create($data);
        });
    }

    /**
     * Atomically move a Purchase to a new Setting and assign it a new reference.
     *
     * The target Setting row lock is held from allocation through UPDATE, ensuring
     * sequential numbering even under concurrent load.
     *
     * @param Purchase $purchase The purchase to move (must be in DRAFTED status)
     * @param int $targetSettingId The target setting ID
     * @param Carbon $purchaseDate The purchase date for reference calculation
     * @return void (modifies purchase in-place)
     */
    public static function movePurchaseToSetting(Purchase $purchase, int $targetSettingId, Carbon $purchaseDate): void
    {
        DB::transaction(function () use ($purchase, $targetSettingId, $purchaseDate) {
            $year = $purchaseDate->year;
            $month = $purchaseDate->month;

            // Lock the target Setting row for the entire transaction
            $setting = Setting::whereKey($targetSettingId)->lockForUpdate()->firstOrFail();

            // Fetch the latest reference for the target setting, year, and month
            $latestReference = Purchase::withArchived()
                ->where('setting_id', $targetSettingId)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->latest('id')
                ->value('reference');

            // Calculate next number
            $nextNumber = 1;
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            // Build prefix
            $prefix = (optional($setting)->document_prefix ?: '') . '-'
                . (optional($setting)->purchase_prefix_document ?: 'PR');

            // Generate reference
            $reference = make_reference_id($prefix, $year, $month, $nextNumber);

            // Update the purchase with new setting and reference while lock is held
            $purchase->update([
                'setting_id' => $targetSettingId,
                'reference' => $reference,
            ]);
        });
    }

    /**
     * Atomically move a Sale to a new Setting and assign it a new reference.
     *
     * The target Setting row lock is held from allocation through UPDATE, ensuring
     * sequential numbering even under concurrent load.
     *
     * @param Sale $sale The sale to move (must be in DRAFTED status)
     * @param int $targetSettingId The target setting ID
     * @param Carbon $saleDate The sale date for reference calculation
     * @return void (modifies sale in-place)
     */
    public static function moveSaleToSetting(Sale $sale, int $targetSettingId, Carbon $saleDate): void
    {
        DB::transaction(function () use ($sale, $targetSettingId, $saleDate) {
            $year = $saleDate->year;
            $month = $saleDate->month;

            // Lock the target Setting row for the entire transaction
            $setting = Setting::whereKey($targetSettingId)->lockForUpdate()->firstOrFail();

            // Fetch the latest reference for the target setting, year, and month
            $latestReference = Sale::withArchived()
                ->where('setting_id', $targetSettingId)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->latest('id')
                ->value('reference');

            // Calculate next number
            $nextNumber = 1;
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            // Build prefix
            $prefix = (optional($setting)->document_prefix ?: '') . '-'
                . (optional($setting)->sale_prefix_document ?: 'SL');

            // Generate reference
            $reference = make_reference_id($prefix, $year, $month, $nextNumber);

            // Update the sale with new setting and reference while lock is held
            $sale->update([
                'setting_id' => $targetSettingId,
                'reference' => $reference,
            ]);
        });
    }
}

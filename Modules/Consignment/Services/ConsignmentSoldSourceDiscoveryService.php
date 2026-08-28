<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;

class ConsignmentSoldSourceDiscoveryService
{
    /**
     * Optional hook for testing concurrency and state mutation between selection and persistence.
     */
    public ?\Closure $beforePersistHook = null;

    /**
     * Discover and process eligible consignment sold sources for a setting.
     *
     * @param int $settingId
     * @param bool $previewOnly If true, do not persist any changes.
     * @return array Summary of discovery results.
     */
    public function discoverForSetting(int $settingId, bool $previewOnly = false): array
    {
        $consignmentLocationIds = Location::where('setting_id', $settingId)
            ->where('is_consignment', true)
            ->pluck('id')
            ->toArray();

        if (empty($consignmentLocationIds)) {
            return [
                'created' => 0,
                'existing' => 0,
                'excluded' => 0,
                'blocked' => 0,
                'details' => [],
            ];
        }

        $dispatchDetails = DispatchDetail::whereHas('dispatch', function ($query) {
            $query->where('status', Dispatch::STATUS_APPROVED);
        })
            ->whereIn('location_id', $consignmentLocationIds)
            ->with(['dispatch', 'sale', 'product', 'location'])
            ->get();

        $created = 0;
        $existing = 0;
        $excluded = 0;
        $blocked = 0;
        $details = [];

        foreach ($dispatchDetails as $detail) {
            // Check if already captured
            $existingSource = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->first();
            if ($existingSource) {
                $existing++;
                $details[] = [
                    'dispatch_detail_id' => $detail->id,
                    'status' => 'EXISTING',
                    'source_id' => $existingSource->id,
                ];
                continue;
            }

            // Execute test hook prior to persistence if configured
            if ($this->beforePersistHook !== null) {
                ($this->beforePersistHook)($detail);
            }

            if (! $previewOnly) {
                $resultStatus = DB::transaction(function () use ($settingId, $detail) {
                    $candidateLocationId = (int) $detail->location_id;
                    $candidateDispatchId = (int) $detail->dispatch_id;
                    $candidateDetailId = (int) $detail->id;

                    // 1. Lock Location first (hierarchical lock order)
                    $lockedLocation = Location::whereKey($candidateLocationId)->lockForUpdate()->first();
                    if (! $lockedLocation || (int) $lockedLocation->setting_id !== $settingId || ! $lockedLocation->is_consignment || ! $lockedLocation->is_active) {
                        return false;
                    }

                    // 2. Lock Dispatch header second
                    $lockedDispatch = Dispatch::whereKey($candidateDispatchId)->lockForUpdate()->first();
                    if (! $lockedDispatch || $lockedDispatch->status !== Dispatch::STATUS_APPROVED) {
                        return false;
                    }

                    // 3. Lock DispatchDetail third
                    $lockedDispatchDetail = DispatchDetail::whereKey($candidateDetailId)
                        ->with(['product'])
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedDispatchDetail || (int) $lockedDispatchDetail->location_id !== (int) $lockedLocation->id || (int) $lockedDispatchDetail->dispatch_id !== (int) $lockedDispatch->id) {
                        return false;
                    }

                    // Re-check existing source under lock
                    if (ConsignmentSoldSource::where('dispatch_detail_id', $lockedDispatchDetail->id)->exists()) {
                        return false;
                    }

                    $lockedProduct = $lockedDispatchDetail->product;
                    $unsupportedBlocker = null;
                    if (! $lockedProduct || ! $lockedDispatchDetail->is_inventory_managed || ! empty($lockedDispatchDetail->bundle_id)) {
                        $unsupportedBlocker = 'Non-inventory, service, or bundle item';
                    }

                    // Re-resolve original quantity and blockers using locked detail
                    $reconstruction = $this->resolveOriginalQuantityAndBlocker($lockedDispatchDetail);
                    $originalBaseQty = $reconstruction['original_base_quantity'];
                    $hasBlocker = $reconstruction['has_blocker'] || $unsupportedBlocker !== null;
                    $blockerReason = $unsupportedBlocker ?? $reconstruction['blocker_reason'];
                    $reconstructionNotes = $reconstruction['notes'];

                    // Link POS checkout if exists
                    $posCheckoutId = null;
                    if (class_exists(PosCheckoutSale::class)) {
                        $checkoutSale = PosCheckoutSale::where('sale_id', $lockedDispatchDetail->sale_id)->first();
                        if ($checkoutSale) {
                            $posCheckoutId = $checkoutSale->pos_checkout_id;
                        }
                    }

                    // Decode serial identities from locked detail
                    $serialIdentities = $this->parseSerials($lockedDispatchDetail->serial_numbers);
                    sort($serialIdentities);

                    // Re-calculate source hash and snapshot using locked dispatch, detail & location
                    $dispatchedAt = $lockedDispatch->approved_at ?? $lockedDispatch->created_at ?? $lockedDispatchDetail->created_at;
                    $dispatchedAtCarbon = $dispatchedAt ? \Carbon\Carbon::parse($dispatchedAt) : null;
                    $dispatchedAtStr = $dispatchedAtCarbon ? $dispatchedAtCarbon->format('Y-m-d H:i:s') : null;
                    $hashPayload = [
                        'dispatch_detail_id' => $lockedDispatchDetail->id,
                        'setting_id' => $settingId,
                        'location_id' => $lockedDispatchDetail->location_id,
                        'product_id' => $lockedDispatchDetail->product_id,
                        'original_base_quantity' => (string) $originalBaseQty,
                        'dispatched_at' => $dispatchedAtStr,
                        'serials' => $serialIdentities,
                        'dispatch_status' => $lockedDispatch->status,
                        'is_consignment_location' => (bool) $lockedLocation->is_consignment,
                        'pos_checkout_id' => $posCheckoutId,
                        'tax_id' => $lockedDispatchDetail->tax_id,
                    ];
                    $sourceHash = hash('sha256', json_encode($hashPayload));

                    $snapshot = [
                        'dispatch_detail_id' => $lockedDispatchDetail->id,
                        'dispatch_id' => $lockedDispatchDetail->dispatch_id,
                        'sale_id' => $lockedDispatchDetail->sale_id,
                        'pos_checkout_id' => $posCheckoutId,
                        'product_id' => $lockedDispatchDetail->product_id,
                        'product_name' => $lockedProduct->product_name ?? null,
                        'location_id' => $lockedDispatchDetail->location_id,
                        'location_name' => $lockedLocation->name ?? null,
                        'original_base_quantity' => $originalBaseQty,
                        'current_dispatched_quantity' => $lockedDispatchDetail->dispatched_quantity,
                        'tax_id' => $lockedDispatchDetail->tax_id,
                        'serial_numbers' => $serialIdentities,
                        'dispatched_at' => $dispatchedAtCarbon ? $dispatchedAtCarbon->toIso8601String() : null,
                        'source_hash' => $sourceHash,
                    ];

                    $soldSource = ConsignmentSoldSource::create([
                        'setting_id' => $settingId,
                        'dispatch_detail_id' => $lockedDispatchDetail->id,
                        'sale_id' => $lockedDispatchDetail->sale_id,
                        'pos_checkout_id' => $posCheckoutId,
                        'product_id' => $lockedDispatchDetail->product_id,
                        'location_id' => $lockedDispatchDetail->location_id,
                        'original_base_quantity' => $originalBaseQty,
                        'dispatched_at' => $dispatchedAt,
                        'tax_context' => $lockedDispatchDetail->tax_id ? ['tax_id' => $lockedDispatchDetail->tax_id] : null,
                        'serial_identities' => $serialIdentities,
                        'source_hash' => $sourceHash,
                        'source_snapshot' => $snapshot,
                        'reconstruction_notes' => $reconstructionNotes,
                        'has_reconstruction_blocker' => $hasBlocker,
                        'blocker_reason' => $blockerReason,
                    ]);

                    // Link serial numbers if product is serialized
                    if (! empty($serialIdentities)) {
                        foreach ($serialIdentities as $snStr) {
                            $psn = ProductSerialNumber::where('product_id', $lockedProduct->id)
                                ->where('serial_number', $snStr)
                                ->first();

                            if ($psn) {
                                ConsignmentSoldSourceSerial::create([
                                    'consignment_sold_source_id' => $soldSource->id,
                                    'product_serial_number_id' => $psn->id,
                                ]);
                            }
                        }
                    }

                    return ['created' => true, 'has_blocker' => $hasBlocker];
                });

                if ($resultStatus && ! empty($resultStatus['created'])) {
                    $created++;
                    if ($resultStatus['has_blocker']) {
                        $blocked++;
                    }
                    $details[] = [
                        'dispatch_detail_id' => $detail->id,
                        'status' => 'CREATED',
                        'has_blocker' => $resultStatus['has_blocker'],
                    ];
                } else {
                    $excluded++;
                    $details[] = [
                        'dispatch_detail_id' => $detail->id,
                        'status' => 'SKIPPED_RECLASSIFIED_OR_DUPLICATE',
                        'has_blocker' => false,
                    ];
                }
            } else {
                $product = $detail->product;
                $unsupportedBlocker = null;
                if (! $product || ! $detail->is_inventory_managed || ! empty($detail->bundle_id)) {
                    $unsupportedBlocker = 'Non-inventory, service, or bundle item';
                }
                $reconstruction = $this->resolveOriginalQuantityAndBlocker($detail);
                $hasBlocker = $reconstruction['has_blocker'] || $unsupportedBlocker !== null;
                if ($hasBlocker) {
                    $blocked++;
                }

                $created++;
                $details[] = [
                    'dispatch_detail_id' => $detail->id,
                    'status' => 'PREVIEW_CREATED',
                    'has_blocker' => $hasBlocker,
                ];
            }
        }

        return [
            'created' => $created,
            'existing' => $existing,
            'excluded' => $excluded,
            'blocked' => $blocked,
            'details' => $details,
        ];
    }

    /**
     * Resolve original quantity and check for ambiguous reconstruction blockers.
     */
    protected function resolveOriginalQuantityAndBlocker(DispatchDetail $detail): array
    {
        $currentQty = (float) $detail->dispatched_quantity;

        // Ordinary Sales Returns do NOT reduce dispatch_details.dispatched_quantity in DB.
        // Only POS Cash Returns (with resolution cash_return) reduce dispatch_details.dispatched_quantity.
        $posCashReturnedQty = 0.0;
        $hasBlocker = false;
        $blockerReason = null;
        $notes = null;

        if (class_exists(\Modules\Pos\Entities\PosReturnLine::class)) {
            $returns = \Modules\Pos\Entities\PosReturnLine::where('dispatch_detail_id', $detail->id)
                ->with(['posReturn', 'saleReturn'])
                ->get();
            
            foreach ($returns as $ret) {
                if ($ret->resolution === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $isCompleted = false;
                    if ($ret->posReturn && $ret->posReturn->status === 'completed') {
                        $isCompleted = true;
                    } elseif ($ret->saleReturn && in_array($ret->saleReturn->status, ['Completed', 'AWAITING SETTLEMENT', 'Awaiting Settlement', 'COMPLETED'])) {
                        $isCompleted = true;
                    }
                    
                    if ($isCompleted) {
                        $posCashReturnedQty += (float) $ret->quantity;
                    }
                }
            }
        }

        $originalQty = $currentQty + $posCashReturnedQty;

        if (!$hasBlocker && $originalQty <= 0) {
            $hasBlocker = true;
            $blockerReason = "Original dispatched quantity is zero or negative ({$originalQty}).";
        }

        return [
            'original_base_quantity' => $originalQty,
            'has_blocker' => $hasBlocker,
            'blocker_reason' => $blockerReason,
            'notes' => $notes,
        ];
    }

    /**
     * Parse serial number string/JSON into clean string array.
     */
    protected function parseSerials($serials): array
    {
        if (empty($serials)) {
            return [];
        }

        if (is_array($serials)) {
            return array_map('trim', array_filter($serials));
        }

        if (is_string($serials)) {
            $decoded = json_decode($serials, true);
            if (is_array($decoded)) {
                return array_map('trim', array_filter($decoded));
            }
            return array_map('trim', array_filter(explode(',', $serials)));
        }

        return [];
    }
}

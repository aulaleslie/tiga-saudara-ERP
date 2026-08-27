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
            $product = $detail->product;
            $unsupportedBlocker = null;
            if (! $product || ! $detail->is_inventory_managed || ! empty($detail->bundle_id)) {
                $unsupportedBlocker = 'Non-inventory, service, or bundle item';
            }

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

            // Reconstruct original quantity if historical cash return modified dispatched_quantity
            $reconstruction = $this->resolveOriginalQuantityAndBlocker($detail);
            $originalBaseQty = $reconstruction['original_base_quantity'];
            $hasBlocker = $reconstruction['has_blocker'] || $unsupportedBlocker !== null;
            $blockerReason = $unsupportedBlocker ?? $reconstruction['blocker_reason'];
            $reconstructionNotes = $reconstruction['notes'];

            if ($hasBlocker) {
                $blocked++;
            }

            // Link POS checkout if exists
            $posCheckoutId = null;
            if (class_exists(PosCheckoutSale::class)) {
                $checkoutSale = PosCheckoutSale::where('sale_id', $detail->sale_id)->first();
                if ($checkoutSale) {
                    $posCheckoutId = $checkoutSale->pos_checkout_id;
                }
            }

            // Decode serial identities
            $serialIdentities = $this->parseSerials($detail->serial_numbers);
            sort($serialIdentities);

            // Calculate source hash
            $dispatchedAt = $detail->dispatch->approved_at ?? $detail->dispatch->created_at ?? $detail->created_at;
            $dispatchedAtStr = $dispatchedAt ? \Carbon\Carbon::parse($dispatchedAt)->format('Y-m-d H:i:s') : null;
            $hashPayload = [
                'dispatch_detail_id' => $detail->id,
                'setting_id' => $settingId,
                'location_id' => $detail->location_id,
                'product_id' => $detail->product_id,
                'original_base_quantity' => (string) $originalBaseQty,
                'dispatched_at' => $dispatchedAtStr,
                'serials' => $serialIdentities,
                'dispatch_status' => $detail->dispatch->status ?? null,
                'is_consignment_location' => $detail->location->is_consignment ?? true,
                'pos_checkout_id' => $posCheckoutId,
                'tax_id' => $detail->tax_id,
            ];
            $sourceHash = hash('sha256', json_encode($hashPayload));

            $snapshot = [
                'dispatch_detail_id' => $detail->id,
                'dispatch_id' => $detail->dispatch_id,
                'sale_id' => $detail->sale_id,
                'pos_checkout_id' => $posCheckoutId,
                'product_id' => $detail->product_id,
                'product_name' => $product->product_name ?? null,
                'location_id' => $detail->location_id,
                'location_name' => $detail->location->name ?? null,
                'original_base_quantity' => $originalBaseQty,
                'current_dispatched_quantity' => $detail->dispatched_quantity,
                'tax_id' => $detail->tax_id,
                'serial_numbers' => $serialIdentities,
                'dispatched_at' => $dispatchedAt ? $dispatchedAt->toIso8601String() : null,
                'source_hash' => $sourceHash,
            ];

            $snapshot['source_hash'] = $sourceHash;
            if (! $previewOnly) {
                DB::transaction(function () use (
                    $settingId,
                    $detail,
                    $posCheckoutId,
                    $originalBaseQty,
                    $dispatchedAt,
                    $serialIdentities,
                    $sourceHash,
                    $snapshot,
                    $reconstructionNotes,
                    $hasBlocker,
                    $blockerReason,
                    $product
                ) {
                    $soldSource = ConsignmentSoldSource::create([
                        'setting_id' => $settingId,
                        'dispatch_detail_id' => $detail->id,
                        'sale_id' => $detail->sale_id,
                        'pos_checkout_id' => $posCheckoutId,
                        'product_id' => $detail->product_id,
                        'location_id' => $detail->location_id,
                        'original_base_quantity' => $originalBaseQty,
                        'dispatched_at' => $dispatchedAt,
                        'tax_context' => $detail->tax_id ? ['tax_id' => $detail->tax_id] : null,
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
                            $psn = ProductSerialNumber::where('product_id', $product->id)
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
                });

                $created++;
                $details[] = [
                    'dispatch_detail_id' => $detail->id,
                    'status' => 'CREATED',
                    'has_blocker' => $hasBlocker,
                ];
            } else {
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

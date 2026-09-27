<?php

namespace Modules\Adjustment\Services;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementHistory;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferReturnObligationReservation;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Product\Entities\SerialNumberHistory;
use App\Services\SerialNumberHistoryService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use RuntimeException;

class ReturnReceiptApprovalExecutor
{
    public function __construct(
        private ReturnReceiptComparatorService $comparator,
    ) {
    }

    /**
     * Atomically approve a PENDING return-receipt movement:
     * - Verify exact manifest equality with approved source return dispatch
     * - Resolve processing-time origin tax classification (PKP -> default or lowest-id fallback, non-PKP -> non-tax)
     * - Snapshot tax identity on the receipt movement
     * - Add origin inventory in origin-classified buckets
     * - Move live serials to origin location and apply origin tax identity
     * - Close transit custody and delete active serial claims
     * - Close source batch's active reservations
     * - Increment return obligation returned_quantity by exact reserved quantity (using BCMath)
     * - Project transfer header status: RETURN_DISPATCHED if active reservations remain, AWAITING_RETURN if obligations remain open, else COMPLETED
     * - Idempotent execution
     */
    public function approve(
        Transfer $transfer,
        TransferMovement $movement,
        int $userId,
        ?string $idempotencyKey = null
    ): TransferMovement {
        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($transfer, $movement, $userId, $idempotencyKey) {
            // Lock Transfer
            $lockedTransfer = Transfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            // Lock Movement
            $lockedMovement = TransferMovement::where('id', $movement->id)->lockForUpdate()->firstOrFail();

            // Lock Source Dispatch Movement
            $sourceMovement = TransferMovement::where('id', $lockedMovement->source_movement_id)->lockForUpdate()->firstOrFail();

            // Aggregate Invariants
            if ((int) $lockedTransfer->workflow_version !== 2) {
                throw new RuntimeException("Version 2 approval executor only applies to workflow version 2 transfers.");
            }

            app(TransferWorkflowEligibilityService::class)->validateV2Eligibility($lockedTransfer);

            if ((int) $lockedMovement->transfer_id !== (int) $lockedTransfer->id) {
                throw new RuntimeException("Movement transfer ID mismatch.");
            }

            if ($lockedMovement->type !== TransferMovement::TYPE_RETURN_RECEIPT) {
                throw new RuntimeException("Movement type is not RETURN_RECEIPT.");
            }

            if ($sourceMovement->type !== TransferMovement::TYPE_RETURN_DISPATCH || $sourceMovement->status !== TransferMovement::STATUS_APPROVED) {
                throw new RuntimeException("Source movement must be an APPROVED RETURN_DISPATCH.");
            }

            if ((int) $sourceMovement->transfer_id !== (int) $lockedTransfer->id) {
                throw new RuntimeException("Source movement transfer mismatch.");
            }

            if ($lockedMovement->return_batch_id !== $sourceMovement->return_batch_id) {
                throw new RuntimeException("Return receipt batch ID does not match source return dispatch batch ID.");
            }

            if ((int) $lockedMovement->origin_location_id !== (int) $lockedTransfer->destination_location_id ||
                (int) $lockedMovement->destination_location_id !== (int) $lockedTransfer->origin_location_id ||
                $lockedMovement->stock_condition !== $lockedTransfer->stock_condition) {
                throw new RuntimeException("Movement origin, destination, or condition does not match transfer header reversed leg.");
            }

            // Idempotency check: if already approved with idempotency key, return committed state
            if ($idempotencyKey) {
                $existingHistory = TransferMovementHistory::where('transfer_movement_id', $lockedMovement->id)
                    ->where('revision', $lockedMovement->revision)
                    ->where('action', TransferMovementHistory::ACTION_APPROVED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory && $lockedMovement->status === TransferMovement::STATUS_APPROVED) {
                    return $lockedMovement->fresh(['lines.serials', 'histories', 'tax']);
                }
            }

            // Lifecycle Invariants
            if (!in_array($lockedTransfer->status, [Transfer::STATUS_AWAITING_RETURN, Transfer::STATUS_RETURN_DISPATCHED], true)) {
                throw new RuntimeException("Transfer must be in AWAITING_RETURN or RETURN_DISPATCHED status for return receipt approval. Current status: [{$lockedTransfer->status}].");
            }

            if ($lockedMovement->status !== TransferMovement::STATUS_PENDING) {
                throw new RuntimeException("Only PENDING movements can be approved. Current status: [{$lockedMovement->status}].");
            }

            if ((int) $lockedMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
            }

            // Require the exact approved route-policy snapshot before any mutation
            $policy = $this->comparator->requirePolicy($lockedTransfer, $lockedMovement);
            if (!$policy->mandatory_return) {
                throw new RuntimeException("This transfer route policy does not require return fulfillment.");
            }

            // Canonical Comparison with exact source return dispatch manifest
            $comparison = $this->comparator->compare($lockedMovement, $sourceMovement);
            if (!$comparison['matches']) {
                throw new RuntimeException("Return receipt cannot be approved: physical count does not match the approved return dispatch manifest.");
            }

            // Lock and reload origin location and business setting in stable ID order
            $locationIds = [
                (int) $lockedTransfer->origin_location_id,
                (int) $lockedTransfer->destination_location_id,
            ];
            sort($locationIds);

            $lockedLocations = Location::whereIn('id', $locationIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedOriginLocation = $lockedLocations->get($lockedTransfer->origin_location_id);
            if (!$lockedOriginLocation) {
                throw new RuntimeException("Origin location [{$lockedTransfer->origin_location_id}] not found.");
            }

            $originSetting = Setting::where('id', $lockedOriginLocation->setting_id)->lockForUpdate()->firstOrFail();
            $originIsPkp = (bool) ($originSetting->is_pkp ?? false);

            $resolvedTaxId = null;
            $resolvedTaxName = null;
            $resolvedTaxRate = null;
            $taxProvenance = null;

            if ($originIsPkp) {
                // PKP origin: resolve tax at processing time
                // Check default active tax first
                $defaultTax = Tax::where('is_active', true)
                    ->where('is_default', true)
                    ->lockForUpdate()
                    ->first();

                if ($defaultTax) {
                    $resolvedTaxId = $defaultTax->id;
                    $resolvedTaxName = $defaultTax->name;
                    $resolvedTaxRate = $defaultTax->value;
                    $taxProvenance = TransferRoutePolicy::PROVENANCE_DEFAULT;
                } else {
                    // Fallback to lowest-id active tax
                    $fallbackTax = Tax::where('is_active', true)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->first();

                    if ($fallbackTax) {
                        $resolvedTaxId = $fallbackTax->id;
                        $resolvedTaxName = $fallbackTax->name;
                        $resolvedTaxRate = $fallbackTax->value;
                        $taxProvenance = TransferRoutePolicy::PROVENANCE_FALLBACK;
                    } else {
                        throw new RuntimeException("PKP origin has no active tax configured for return receipt classification.");
                    }
                }
            }

            $now = Carbon::now();

            // Load lines and serials
            $lockedMovement->load(['lines.serials', 'lines.product']);
            $sourceMovement->load(['lines.serials', 'lines.product']);

            // Lock all affected ProductStocks at origin location (which is destination_location_id of the return leg)
            $productIds = $sourceMovement->lines->pluck('product_id')->unique()->sort()->values()->all();
            $originLocationId = (int) $lockedMovement->destination_location_id;

            $stocks = ProductStock::whereIn('product_id', $productIds)
                ->where('location_id', $originLocationId)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock all affected Products for global quantity consistency
            $products = Product::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Lock all source and receipt movement serials
            $allSourceMovementSerials = $sourceMovement->serials()->lockForUpdate()->get();
            $allReceiptMovementSerials = $lockedMovement->serials()->lockForUpdate()->get();

            $sourceSerialIds = $allSourceMovementSerials->pluck('product_serial_number_id')->filter()->values()->all();
            $receiptSerialIds = $allReceiptMovementSerials->pluck('product_serial_number_id')->filter()->values()->all();
            $allSerialIds = array_unique(array_merge($sourceSerialIds, $receiptSerialIds));

            $liveSerials = [];
            if (!empty($allSerialIds)) {
                $liveSerials = ProductSerialNumber::whereIn('id', $allSerialIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
            }

            // Lock all active transfer claims
            $activeClaims = [];
            if (!empty($allSerialIds)) {
                $activeClaims = TransferActiveSerialClaim::whereIn('product_serial_number_id', $allSerialIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_serial_number_id');
            }

            // Lock obligations for these products in stable order
            $obligations = TransferMovementReturnObligation::where('transfer_id', $lockedTransfer->id)
                ->where('stock_condition', $lockedMovement->stock_condition)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock this source batch's active reservations
            $sourceReservations = TransferReturnObligationReservation::where('transfer_movement_id', $sourceMovement->id)
                ->where('status', TransferReturnObligationReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->keyBy('transfer_movement_line_id');

            // Persist the tax snapshot on receipt movement before inventory effects
            $lockedMovement->update([
                'tax_setting_id'          => $originSetting->id,
                'tax_id'                  => $resolvedTaxId,
                'tax_name'                => $resolvedTaxName,
                'tax_rate'                => $resolvedTaxRate,
                'tax_resolver_provenance' => $taxProvenance,
                'tax_resolved_at'         => $now,
            ]);

            $isGoodCondition = $lockedMovement->stock_condition === TransferMovement::CONDITION_GOOD;

            // Process each line
            foreach ($sourceMovement->lines as $sourceLine) {
                $productId = (int) $sourceLine->product_id;
                $product = $products->get($productId) ?? Product::where('id', $productId)->lockForUpdate()->firstOrFail();
                $receiptLine = $lockedMovement->lines->firstWhere('product_id', $productId);

                $rawQty = bcadd((string) $sourceLine->quantity, '0', 4);

                // Integer-authoritative check
                if (str_contains($rawQty, '.')) {
                    $decimals = rtrim(explode('.', $rawQty, 2)[1], '0');
                    if (strlen($decimals) > 0) {
                        throw new RuntimeException("Kuantitas penerimaan retur untuk produk ID {$productId} harus berupa bilangan bulat.");
                    }
                }

                $totalToAdd = (int) $rawQty;

                // Validate source line reservation
                $reservation = $sourceReservations->get($sourceLine->id);
                if (!$reservation) {
                    throw new RuntimeException("Reservasi aktif tidak ditemukan untuk baris pengiriman retur produk ID {$productId}.");
                }

                if (bccomp((string) $reservation->quantity, $rawQty, 4) !== 0) {
                    throw new RuntimeException("Kuantitas reservasi [{$reservation->quantity}] tidak sesuai dengan kuantitas baris [{$rawQty}].");
                }

                $obligation = $obligations->get($productId);
                if (!$obligation) {
                    throw new RuntimeException("Kewajiban retur tidak ditemukan untuk produk ID {$productId}.");
                }

                // Verify fulfillment capacity
                $newReturnedQty = bcadd((string) $obligation->returned_quantity, (string) $reservation->quantity, 4);
                if (bccomp($newReturnedQty, (string) $obligation->required_quantity, 4) > 0) {
                    throw new RuntimeException("Penerimaan retur produk ID {$productId} menyebabkan kuantitas kembali [{$newReturnedQty}] melebihi kewajiban [{$obligation->required_quantity}].");
                }

                $sourceAllocNonTax = (int) ($sourceLine->applied_quantity_non_tax ?? 0);
                $sourceAllocTax = (int) ($sourceLine->applied_quantity_tax ?? 0);
                $sourceAllocBrokenNonTax = (int) ($sourceLine->applied_quantity_broken_non_tax ?? 0);
                $sourceAllocBrokenTax = (int) ($sourceLine->applied_quantity_broken_tax ?? 0);

                $sourceAllocationSnapshot = [
                    'non_tax'        => $sourceAllocNonTax,
                    'tax'            => $sourceAllocTax,
                    'broken_non_tax' => $sourceAllocBrokenNonTax,
                    'broken_tax'     => $sourceAllocBrokenTax,
                ];

                // Origin classification allocation
                if ($originIsPkp) {
                    $allocNonTax = 0;
                    $allocBrokenNonTax = 0;
                    $allocTax = $isGoodCondition ? $totalToAdd : 0;
                    $allocBrokenTax = $isGoodCondition ? 0 : $totalToAdd;
                } else {
                    $allocTax = 0;
                    $allocBrokenTax = 0;
                    $allocNonTax = $isGoodCondition ? $totalToAdd : 0;
                    $allocBrokenNonTax = $isGoodCondition ? 0 : $totalToAdd;
                }

                $stock = $stocks->get($productId);
                if (!$stock) {
                    $stock = ProductStock::create([
                        'product_id'              => $productId,
                        'location_id'             => $originLocationId,
                        'quantity'                => 0,
                        'quantity_tax'            => 0,
                        'quantity_non_tax'        => 0,
                        'broken_quantity'         => 0,
                        'broken_quantity_tax'     => 0,
                        'broken_quantity_non_tax' => 0,
                    ]);
                    $stocks->put($productId, $stock);
                }

                // Serialized handling
                if ($product->serial_number_required) {
                    $lineSourceSerials = $sourceLine->serials;
                    $lineReceiptSerials = $receiptLine ? $receiptLine->serials : collect();

                    if ($lineReceiptSerials->count() !== $totalToAdd) {
                        throw new RuntimeException("Jumlah serial pada penerimaan retur tidak sesuai dengan kuantitas barang.");
                    }

                    $receiptSerialsByText = [];
                    foreach ($lineReceiptSerials as $rSerial) {
                        if ($rSerial->product_serial_number_id === null) {
                            throw new RuntimeException("Draf penerimaan retur memiliki nomor seri yang tidak valid di database.");
                        }
                        $norm = TransferMovementSerial::normalize((string) $rSerial->serial_number);
                        $receiptSerialsByText[$norm] = (int) $rSerial->product_serial_number_id;
                    }

                    foreach ($lineSourceSerials as $movSerial) {
                        $normSource = TransferMovementSerial::normalize((string) $movSerial->serial_number);
                        $matchingReceiptId = $receiptSerialsByText[$normSource] ?? null;

                        if ($matchingReceiptId === null || (int) $movSerial->product_serial_number_id !== $matchingReceiptId) {
                            throw new RuntimeException("Nomor seri fisik yang diterima tidak sesuai dengan identitas pengiriman retur.");
                        }

                        $liveSerial = $liveSerials->get($movSerial->product_serial_number_id);
                        if (!$liveSerial) {
                            throw new RuntimeException("Nomor seri {$movSerial->serial_number} tidak ditemukan.");
                        }

                        $normLive = ProductSerialNumber::normalize((string) $liveSerial->serial_number);
                        if ($normLive !== $normSource) {
                            throw new RuntimeException("Live serial text [{$normLive}] does not match source serial [{$normSource}].");
                        }

                        if ((int) $liveSerial->product_id !== $productId) {
                            throw new RuntimeException("Live serial [{$normLive}] belongs to product ID {$liveSerial->product_id}, not {$productId}.");
                        }

                        // Revalidate live serial condition matches transfer and source movement condition snapshot
                        $isBroken = (bool) ($liveSerial->is_broken ?? false);
                        if ($lockedMovement->stock_condition === TransferMovement::CONDITION_GOOD && $isBroken) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} berstatus barang rusak padahal transfer berstatus GOOD.");
                        }
                        if ($lockedMovement->stock_condition === TransferMovement::CONDITION_BREAKAGE && !$isBroken) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} berstatus barang baik padahal transfer berstatus BREAKAGE.");
                        }

                        // Revalidate live serial tax identity has not drifted from source movement serial snapshot
                        if ((int) ($liveSerial->tax_id ?? 0) !== (int) ($movSerial->tax_id ?? 0)) {
                            throw new RuntimeException("Identitas pajak nomor seri {$liveSerial->serial_number} telah berubah sejak pengiriman retur.");
                        }

                        // Revalidate live serial location is return dispatch origin (which is destinationLocation of transfer)
                        if ($liveSerial->location_id === null || (int) $liveSerial->location_id !== (int) $lockedMovement->origin_location_id) {
                            throw new RuntimeException("Live serial [{$normLive}] location [{$liveSerial->location_id}] does not match dispatch location {$lockedMovement->origin_location_id}.");
                        }

                        // Revalidate exact claim ownership
                        $claim = $activeClaims->get($liveSerial->id);
                        if (!$claim) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} tidak memiliki klaim transit aktif.");
                        }

                        if ((int) $claim->transfer_movement_id !== (int) $sourceMovement->id) {
                            throw new RuntimeException("Klaim transit nomor seri {$liveSerial->serial_number} bukan milik pengiriman retur ini.");
                        }

                        if ((int) $claim->transfer_movement_serial_id !== (int) $movSerial->id) {
                            throw new RuntimeException("Klaim transit nomor seri {$liveSerial->serial_number} does not match source movement serial record.");
                        }

                        if ($movSerial->transit_custody_status !== TransferMovementSerial::CUSTODY_IN_TRANSIT) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} tidak dalam status IN_TRANSIT.");
                        }

                        // Close custody on source movement serial
                        $movSerial->update([
                            'transit_custody_status' => TransferMovementSerial::CUSTODY_CLOSED,
                            'custody_closed_at'      => $now,
                        ]);

                        // Move live serial location to origin and apply origin tax identity
                        $liveSerial->update([
                            'location_id' => $originLocationId,
                            'tax_id'      => $resolvedTaxId,
                        ]);

                        // Remove active claim
                        $claim->delete();

                        // Record serial history
                        SerialNumberHistoryService::record(
                            $liveSerial->id,
                            SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                            $originLocationId,
                            $lockedMovement,
                            sprintf(
                                'Return receipt V2 (Selesai Diterima Retur) di %s dari %s',
                                $lockedTransfer->originLocation->name ?? '-',
                                $lockedTransfer->destinationLocation->name ?? '-'
                            ),
                            $userId
                        );
                    }
                }

                $allocation = [
                    'total'          => $totalToAdd,
                    'non_tax'        => $allocNonTax,
                    'tax'            => $allocTax,
                    'broken_non_tax' => $allocBrokenNonTax,
                    'broken_tax'     => $allocBrokenTax,
                ];

                // Apply origin inventory addition
                $snapshot = $this->applyInventoryAddition($product, $stock, $allocation);

                $reason = sprintf(
                    'Return receipt V2 at %s - %s from %s (Transfer #%d, Movement #%d, Batch %s)',
                    $originSetting->company_name ?? '-',
                    $lockedTransfer->originLocation->name ?? '-',
                    $lockedTransfer->destinationLocation->name ?? '-',
                    $lockedTransfer->id,
                    $lockedMovement->id,
                    $lockedMovement->return_batch_id
                );

                $trx = Transaction::create([
                    'product_id'                    => $productId,
                    'setting_id'                    => $originSetting->id,
                    'type'                          => 'TRF',
                    'quantity'                      => $snapshot['total'],
                    'current_quantity'              => $snapshot['current_stock']['quantity'],
                    'broken_quantity'               => $snapshot['current_stock']['broken'],
                    'previous_quantity'             => $snapshot['previous_product']['quantity'],
                    'previous_quantity_at_location' => $snapshot['previous_stock']['quantity'],
                    'after_quantity'                => $snapshot['current_product']['quantity'],
                    'after_quantity_at_location'    => $snapshot['current_stock']['quantity'],
                    'quantity_tax'                  => $snapshot['current_stock']['quantity_tax'],
                    'quantity_non_tax'              => $snapshot['current_stock']['quantity_non_tax'],
                    'broken_quantity_tax'           => $snapshot['current_stock']['broken_tax'],
                    'broken_quantity_non_tax'       => $snapshot['current_stock']['broken_non_tax'],
                    'location_id'                   => $originLocationId,
                    'user_id'                       => $userId,
                    'reason'                        => $reason,
                ]);

                if ($receiptLine) {
                    $receiptLine->update([
                        'applied_quantity_non_tax'        => bcadd((string) $allocation['non_tax'], '0', 4),
                        'applied_quantity_tax'            => bcadd((string) $allocation['tax'], '0', 4),
                        'applied_quantity_broken_non_tax' => bcadd((string) $allocation['broken_non_tax'], '0', 4),
                        'applied_quantity_broken_tax'     => bcadd((string) $allocation['broken_tax'], '0', 4),
                        'stock_snapshot_before'           => $snapshot['previous_stock'],
                        'stock_snapshot_after'            => $snapshot['current_stock'],
                        'inventory_transaction_reference' => (string) $trx->id,
                        'source_allocation'               => $sourceAllocationSnapshot,
                        'destination_classification'      => $originIsPkp ? TransferRoutePolicy::CLASSIFICATION_TAX : TransferRoutePolicy::CLASSIFICATION_NON_TAX,
                    ]);
                }

                // Reclassify serialized receipt tax identity per origin tax snapshot
                if ($product->serial_number_required && $receiptLine) {
                    foreach ($receiptLine->serials as $rSerial) {
                        $rSerial->update([
                            'previous_tax_id'   => $rSerial->tax_id,
                            'previous_tax_name' => $rSerial->tax_name,
                            'previous_tax_rate' => $rSerial->tax_rate,
                            'tax_id'            => $resolvedTaxId,
                            'tax_name'          => $resolvedTaxName,
                            'tax_rate'          => $resolvedTaxRate,
                        ]);
                    }
                }

                // Close the source batch's active reservation
                $reservation->update([
                    'status'    => TransferReturnObligationReservation::STATUS_CLOSED,
                    'closed_at' => $now,
                ]);

                // Increment obligation returned quantity
                $obligationStatus = bccomp($newReturnedQty, (string) $obligation->required_quantity, 4) === 0
                    ? TransferMovementReturnObligation::STATUS_FULFILLED
                    : TransferMovementReturnObligation::STATUS_OUTSTANDING;

                $obligation->update([
                    'returned_quantity' => $newReturnedQty,
                    'status'            => $obligationStatus,
                ]);
            }

            // Update movement status to APPROVED with processing-time origin tax snapshot
            $lockedMovement->update([
                'status'                  => TransferMovement::STATUS_APPROVED,
                'tax_setting_id'          => $originSetting->id,
                'tax_id'                  => $resolvedTaxId,
                'tax_name'                => $resolvedTaxName,
                'tax_rate'                => $resolvedTaxRate,
                'tax_resolver_provenance' => $taxProvenance,
                'tax_resolved_at'         => $now,
                'reviewed_by'             => $userId,
                'reviewed_at'             => $now,
                'updated_by'              => $userId,
            ]);

            TransferMovementHistory::create([
                'transfer_movement_id' => $lockedMovement->id,
                'revision'             => $lockedMovement->revision,
                'action'               => TransferMovementHistory::ACTION_APPROVED,
                'from_status'          => TransferMovement::STATUS_PENDING,
                'to_status'            => TransferMovement::STATUS_APPROVED,
                'actor_id'             => $userId,
                'reason'               => 'Return receipt movement approved and origin inventory restored.',
                'idempotency_key'      => $idempotencyKey,
            ]);

            // Derive transfer header status
            $hasAnyActiveReservations = TransferReturnObligationReservation::whereHas('movement', function ($q) use ($lockedTransfer) {
                $q->where('transfer_id', $lockedTransfer->id);
            })->where('status', TransferReturnObligationReservation::STATUS_ACTIVE)->exists();

            $hasAnyOutstandingObligation = TransferMovementReturnObligation::where('transfer_id', $lockedTransfer->id)
                ->where('status', TransferMovementReturnObligation::STATUS_OUTSTANDING)
                ->exists();

            $newTransferStatus = Transfer::STATUS_COMPLETED;
            if ($hasAnyActiveReservations) {
                $newTransferStatus = Transfer::STATUS_RETURN_DISPATCHED;
            } elseif ($hasAnyOutstandingObligation) {
                $newTransferStatus = Transfer::STATUS_AWAITING_RETURN;
            }

            $lockedTransfer->update([
                'status' => $newTransferStatus,
            ]);

            return $lockedMovement->fresh(['lines.serials', 'histories', 'tax', 'taxSetting']);
        });
    }

    private function applyInventoryAddition(Product $product, ProductStock $stock, array $allocation): array
    {
        $total = $allocation['total'];
        $brokenTotal = $allocation['broken_tax'] + $allocation['broken_non_tax'];

        $previousStock = [
            'quantity_tax'       => (int) ($stock->quantity_tax ?? 0),
            'quantity_non_tax'   => (int) ($stock->quantity_non_tax ?? 0),
            'broken_tax'         => (int) ($stock->broken_quantity_tax ?? 0),
            'broken_non_tax'     => (int) ($stock->broken_quantity_non_tax ?? 0),
        ];

        $previousStockQuantity = (int) ($stock->quantity ?? array_sum($previousStock));
        $previousBrokenQuantity = (int) ($stock->broken_quantity ?? ($previousStock['broken_tax'] + $previousStock['broken_non_tax']));
        $previousProductQuantity = (int) ($product->product_quantity ?? 0);
        $previousProductBroken   = (int) ($product->broken_quantity ?? 0);

        $stock->quantity_tax            = $previousStock['quantity_tax'] + $allocation['tax'];
        $stock->quantity_non_tax        = $previousStock['quantity_non_tax'] + $allocation['non_tax'];
        $stock->broken_quantity_tax     = $previousStock['broken_tax'] + $allocation['broken_tax'];
        $stock->broken_quantity_non_tax = $previousStock['broken_non_tax'] + $allocation['broken_non_tax'];

        $product->product_quantity = $previousProductQuantity + $total;
        $product->broken_quantity  = $previousProductBroken + $brokenTotal;

        $stock->quantity        = max(0, $stock->quantity_tax + $stock->quantity_non_tax + $stock->broken_quantity_tax + $stock->broken_quantity_non_tax);
        $stock->broken_quantity = max(0, $stock->broken_quantity_tax + $stock->broken_quantity_non_tax);

        $stock->save();
        $product->save();

        return [
            'total'            => $total,
            'quantities'       => [
                'tax'            => $allocation['tax'],
                'non_tax'        => $allocation['non_tax'],
                'broken_tax'     => $allocation['broken_tax'],
                'broken_non_tax' => $allocation['broken_non_tax'],
            ],
            'previous_stock'   => [
                'quantity'        => $previousStockQuantity,
                'broken'          => $previousBrokenQuantity,
                'quantity_tax'    => $previousStock['quantity_tax'],
                'quantity_non_tax'=> $previousStock['quantity_non_tax'],
                'broken_tax'      => $previousStock['broken_tax'],
                'broken_non_tax'  => $previousStock['broken_non_tax'],
            ],
            'current_stock'    => [
                'quantity'        => (int) $stock->quantity,
                'broken'          => (int) $stock->broken_quantity,
                'quantity_tax'    => (int) $stock->quantity_tax,
                'quantity_non_tax'=> (int) $stock->quantity_non_tax,
                'broken_tax'      => (int) $stock->broken_quantity_tax,
                'broken_non_tax'  => (int) $stock->broken_quantity_non_tax,
            ],
            'previous_product' => [
                'quantity' => $previousProductQuantity,
                'broken'   => $previousProductBroken,
            ],
            'current_product'  => [
                'quantity' => (int) $product->product_quantity,
                'broken'   => (int) $product->broken_quantity,
            ],
        ];
    }
}

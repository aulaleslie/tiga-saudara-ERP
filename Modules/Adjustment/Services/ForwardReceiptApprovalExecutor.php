<?php

namespace Modules\Adjustment\Services;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use App\Services\SerialNumberHistoryService;
use Modules\Product\Entities\SerialNumberHistory;
use RuntimeException;

class ForwardReceiptApprovalExecutor
{
    public function __construct(
        private ForwardReceiptComparatorService $comparator,
    ) {
    }

    /**
     * Atomically approve a PENDING forward-receipt movement, add destination inventory
     * using the approved source forward dispatch's applied bucket provenance,
     * record before/after snapshots and inventory transactions, move live serials to destination,
     * close transit custody, remove active serial claims, record movement history,
     * and project eligible no-return transfers to COMPLETED.
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
            $lockedTransfer = Transfer::where('id', $transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Lock Movement
            $lockedMovement = TransferMovement::where('id', $movement->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Lock Source Dispatch Movement
            $sourceMovement = TransferMovement::where('id', $lockedMovement->source_movement_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Aggregate Invariants
            if ((int) $lockedTransfer->workflow_version !== 2) {
                throw new RuntimeException("Version 2 approval executor only applies to workflow version 2 transfers.");
            }

            app(TransferWorkflowEligibilityService::class)->validateV2Eligibility($lockedTransfer);

            if ((int) $lockedMovement->transfer_id !== (int) $lockedTransfer->id) {
                throw new RuntimeException("Movement transfer ID mismatch.");
            }

            if ($lockedMovement->type !== TransferMovement::TYPE_FORWARD_RECEIPT) {
                throw new RuntimeException("Movement type is not FORWARD_RECEIPT.");
            }

            if ($sourceMovement->type !== TransferMovement::TYPE_FORWARD_DISPATCH || $sourceMovement->status !== TransferMovement::STATUS_APPROVED) {
                throw new RuntimeException("Source movement must be an APPROVED FORWARD_DISPATCH.");
            }

            if ((int) $sourceMovement->transfer_id !== (int) $lockedTransfer->id) {
                throw new RuntimeException("Source movement transfer mismatch.");
            }

            if ((int) $lockedMovement->origin_location_id !== (int) $lockedTransfer->origin_location_id ||
                (int) $lockedMovement->destination_location_id !== (int) $lockedTransfer->destination_location_id ||
                $lockedMovement->stock_condition !== $lockedTransfer->stock_condition) {
                throw new RuntimeException("Movement origin, destination, or condition does not match transfer header.");
            }

            // Idempotency check: if this movement is already approved and has matching history with idempotency_key, return it
            if ($idempotencyKey) {
                $existingHistory = \Modules\Adjustment\Entities\TransferMovementHistory::where('transfer_movement_id', $lockedMovement->id)
                    ->where('revision', $lockedMovement->revision)
                    ->where('action', \Modules\Adjustment\Entities\TransferMovementHistory::ACTION_APPROVED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory && $lockedMovement->status === TransferMovement::STATUS_APPROVED) {
                    return $lockedMovement->fresh(['lines.serials', 'histories']);
                }
            }

            // Lifecycle Invariants
            if ($lockedTransfer->status !== Transfer::STATUS_DISPATCHED) {
                throw new RuntimeException("Transfer must be in DISPATCHED status for receipt approval. Current status: [{$lockedTransfer->status}].");
            }

            if ($lockedMovement->status !== TransferMovement::STATUS_PENDING) {
                throw new RuntimeException("Only PENDING movements can be approved. Current status: [{$lockedMovement->status}].");
            }

            if ((int) $lockedMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
            }

            // Require the exact approved route-policy snapshot before any mutation
            $policy = $this->comparator->requirePolicy($lockedTransfer, $lockedMovement);

            // Canonical Comparison with exact source dispatch manifest
            $comparison = $this->comparator->compare($lockedMovement, $sourceMovement);
            if (!$comparison['matches']) {
                throw new RuntimeException("Forward receipt cannot be approved: physical receipt count does not match the approved forward dispatch manifest.");
            }

            // Load lines and serials
            $lockedMovement->load(['lines.serials', 'lines.product']);
            $sourceMovement->load(['lines.serials', 'lines.product']);

            // Lock all affected ProductStocks at destination location
            $productIds = $sourceMovement->lines->pluck('product_id')->unique()->sort()->values()->all();
            $destinationLocationId = (int) $lockedMovement->destination_location_id;

            $stocks = ProductStock::whereIn('product_id', $productIds)
                ->where('location_id', $destinationLocationId)
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

            $now = Carbon::now();

            // Destination setting ID
            $lockedTransfer->loadMissing(['originLocation.setting', 'destinationLocation.setting']);
            $destinationSettingId = (int) $lockedTransfer->destinationLocation->setting_id;

            // Process each line from source dispatch provenance
            foreach ($sourceMovement->lines as $sourceLine) {
                $productId = (int) $sourceLine->product_id;
                $product = $products->get($productId) ?? Product::where('id', $productId)->lockForUpdate()->firstOrFail();

                // Find corresponding receipt line
                $receiptLine = $lockedMovement->lines->firstWhere('product_id', $productId);

                $nonTaxStr = (string) ($sourceLine->applied_quantity_non_tax ?? '0.0000');
                $taxStr = (string) ($sourceLine->applied_quantity_tax ?? '0.0000');
                $brokenNonTaxStr = (string) ($sourceLine->applied_quantity_broken_non_tax ?? '0.0000');
                $brokenTaxStr = (string) ($sourceLine->applied_quantity_broken_tax ?? '0.0000');

                if (bccomp($nonTaxStr, '0.0000', 4) < 0 || bccomp($taxStr, '0.0000', 4) < 0 || bccomp($brokenNonTaxStr, '0.0000', 4) < 0 || bccomp($brokenTaxStr, '0.0000', 4) < 0) {
                    throw new RuntimeException("Dispatched quantity buckets cannot be negative.");
                }


                $isGoodCondition = $lockedMovement->stock_condition === TransferMovement::CONDITION_GOOD;
                if ($isGoodCondition) {
                    if (bccomp($brokenNonTaxStr, '0.0000', 4) !== 0 || bccomp($brokenTaxStr, '0.0000', 4) !== 0) {
                        throw new RuntimeException("Good condition dispatch cannot contain broken stock buckets.");
                    }
                } else {
                    if (bccomp($nonTaxStr, '0.0000', 4) !== 0 || bccomp($taxStr, '0.0000', 4) !== 0) {
                        throw new RuntimeException("Broken condition dispatch cannot contain good stock buckets.");
                    }
                }

                $sourceBucketTotal = bcadd(bcadd($nonTaxStr, $taxStr, 4), bcadd($brokenNonTaxStr, $brokenTaxStr, 4), 4);
                if (bccomp($sourceBucketTotal, (string) $sourceLine->quantity, 4) !== 0) {
                    throw new RuntimeException("Dispatched bucket sum does not match source dispatch line quantity.");
                }

                if ($receiptLine && bccomp($sourceBucketTotal, (string) $receiptLine->quantity, 4) !== 0) {
                    throw new RuntimeException("Dispatched bucket sum does not match received line quantity.");
                }

                foreach ([$nonTaxStr, $taxStr, $brokenNonTaxStr, $brokenTaxStr] as $bucketVal) {
                    if (bccomp($bucketVal, bcadd($bucketVal, '0', 0), 4) !== 0) {
                        throw new RuntimeException("Dispatched quantity buckets must be whole numbers.");
                    }
                }

                $sourceAllocNonTax = (int) $nonTaxStr;
                $sourceAllocTax = (int) $taxStr;
                $sourceAllocBrokenNonTax = (int) $brokenNonTaxStr;
                $sourceAllocBrokenTax = (int) $brokenTaxStr;
                $totalToAdd = $sourceAllocNonTax + $sourceAllocTax + $sourceAllocBrokenNonTax + $sourceAllocBrokenTax;

                $sourceAllocationSnapshot = [
                    'non_tax'        => $sourceAllocNonTax,
                    'tax'            => $sourceAllocTax,
                    'broken_non_tax' => $sourceAllocBrokenNonTax,
                    'broken_tax'     => $sourceAllocBrokenTax,
                ];

                if ($totalToAdd <= 0) {
                    if ($receiptLine) {
                        $receiptLine->update([
                            'applied_quantity_non_tax'        => '0.0000',
                            'applied_quantity_tax'            => '0.0000',
                            'applied_quantity_broken_non_tax' => '0.0000',
                            'applied_quantity_broken_tax'     => '0.0000',
                            'stock_snapshot_before'           => null,
                            'stock_snapshot_after'            => null,
                            'inventory_transaction_reference' => null,
                            'source_allocation'               => $sourceAllocationSnapshot,
                            'destination_classification'      => $policy->destination_classification,
                        ]);
                    }
                    continue;
                }

                // Reclassify the exact received total into destination buckets per policy
                if ($policy->destination_classification === TransferRoutePolicy::CLASSIFICATION_PRESERVE) {
                    $allocNonTax = $sourceAllocNonTax;
                    $allocTax = $sourceAllocTax;
                    $allocBrokenNonTax = $sourceAllocBrokenNonTax;
                    $allocBrokenTax = $sourceAllocBrokenTax;
                } elseif ($policy->destination_classification === TransferRoutePolicy::CLASSIFICATION_TAX) {
                    $allocNonTax = 0;
                    $allocBrokenNonTax = 0;
                    $allocTax = $isGoodCondition ? $totalToAdd : 0;
                    $allocBrokenTax = $isGoodCondition ? 0 : $totalToAdd;
                } else { // NON_TAX
                    $allocTax = 0;
                    $allocBrokenTax = 0;
                    $allocNonTax = $isGoodCondition ? $totalToAdd : 0;
                    $allocBrokenNonTax = $isGoodCondition ? 0 : $totalToAdd;
                }

                // Verify source dispatch inventory transaction reference exists and is valid
                $sourceTrxRef = (string) ($sourceLine->inventory_transaction_reference ?? '');
                if ($sourceTrxRef === '' || !ctype_digit($sourceTrxRef) || (int) $sourceTrxRef <= 0) {
                    throw new RuntimeException("Source dispatch line for product ID {$productId} lacks a valid positive integer inventory transaction reference.");
                }

                $sourceSettingId = (int) $lockedTransfer->originLocation->setting_id;
                $sourceTrx = Transaction::where('id', (int) $sourceTrxRef)
                    ->lockForUpdate()
                    ->first();

                if (!$sourceTrx) {
                    throw new RuntimeException("Source dispatch inventory transaction [{$sourceTrxRef}] for product ID {$productId} was not found.");
                }

                if ($sourceTrx->type !== 'TRF' ||
                    (int) $sourceTrx->setting_id !== $sourceSettingId ||
                    (int) $sourceTrx->location_id !== (int) $lockedMovement->origin_location_id ||
                    (int) $sourceTrx->product_id !== $productId) {
                    throw new RuntimeException("Source dispatch inventory transaction [{$sourceTrxRef}] identity (type, setting, location, or product) does not match expected dispatch provenance.");
                }

                // Verify transaction quantity is negative deduction matching source line total
                if ((int) $sourceTrx->quantity !== -$totalToAdd) {
                    throw new RuntimeException("Source dispatch inventory transaction [{$sourceTrxRef}] quantity [{$sourceTrx->quantity}] does not match dispatched deduction [-{$totalToAdd}].");
                }

                $stock = $stocks->get($productId);
                if (!$stock) {
                    // Create destination product stock row if not existing
                    $stock = ProductStock::create([
                        'product_id'              => $productId,
                        'location_id'             => $destinationLocationId,
                        'quantity'                => 0,
                        'quantity_tax'            => 0,
                        'quantity_non_tax'        => 0,
                        'broken_quantity'         => 0,
                        'broken_quantity_tax'     => 0,
                        'broken_quantity_non_tax' => 0,
                    ]);
                    $stocks->put($productId, $stock);
                }

                // If serialized, validate live serials, claims, update location to destination, record history, close custody, delete claim
                if ($product->serial_number_required) {
                    $lineSourceSerials = $sourceLine->serials;
                    $lineReceiptSerials = $receiptLine ? $receiptLine->serials : collect();

                    // Verify receipt serial count equals quantity
                    if ($lineReceiptSerials->count() !== $totalToAdd) {
                        throw new RuntimeException("Jumlah nomor seri pada draf penerimaan tidak sesuai dengan kuantitas barang.");
                    }

                    // Map receipt serials by normalized text and ensure non-null ID
                    $receiptSerialsByText = [];
                    foreach ($lineReceiptSerials as $rSerial) {
                        if ($rSerial->product_serial_number_id === null) {
                            throw new RuntimeException("Draf penerimaan memiliki nomor seri yang tidak valid di database.");
                        }
                        $norm = TransferMovementSerial::normalize((string) $rSerial->serial_number);
                        $receiptSerialsByText[$norm] = (int) $rSerial->product_serial_number_id;
                    }

                    foreach ($lineSourceSerials as $movSerial) {
                        $normSource = TransferMovementSerial::normalize((string) $movSerial->serial_number);
                        $matchingReceiptId = $receiptSerialsByText[$normSource] ?? null;

                        if ($matchingReceiptId === null || (int) $movSerial->product_serial_number_id !== $matchingReceiptId) {
                            throw new RuntimeException("Nomor seri fisik yang diterima tidak sesuai dengan identitas pengiriman.");
                        }

                        $liveSerial = $liveSerials->get($movSerial->product_serial_number_id);
                        if (!$liveSerial) {
                            throw new RuntimeException("Nomor seri {$movSerial->serial_number} tidak ditemukan.");
                        }

                        // Revalidate live serial normalized text against source and receipt
                        $normLive = ProductSerialNumber::normalize((string) $liveSerial->serial_number);
                        if ($normLive !== $normSource) {
                            throw new RuntimeException("Live serial text [{$normLive}] does not match source serial [{$normSource}].");
                        }

                        // Revalidate live serial product_id
                        if ((int) $liveSerial->product_id !== $productId) {
                            throw new RuntimeException("Live serial [{$normLive}] belongs to product ID {$liveSerial->product_id}, not expected product {$productId}.");
                        }

                        // Revalidate live serial location is strictly transfer origin location
                        if ($liveSerial->location_id === null || (int) $liveSerial->location_id !== (int) $lockedMovement->origin_location_id) {
                            throw new RuntimeException("Live serial [{$normLive}] location [{$liveSerial->location_id}] does not match origin location {$lockedMovement->origin_location_id}.");
                        }

                        // Revalidate live serial stock condition matches transfer
                        $isBroken = (bool) ($liveSerial->is_broken ?? false);
                        if ($lockedMovement->stock_condition === TransferMovement::CONDITION_GOOD && $isBroken) {
                            throw new RuntimeException("Live serial [{$normLive}] is broken but transfer condition is GOOD.");
                        }
                        if ($lockedMovement->stock_condition === TransferMovement::CONDITION_BREAKAGE && !$isBroken) {
                            throw new RuntimeException("Live serial [{$normLive}] is good but transfer condition is BREAKAGE.");
                        }

                        // Revalidate exact claim ownership
                        $claim = $activeClaims->get($liveSerial->id);
                        if (!$claim) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} tidak memiliki klaim transit aktif.");
                        }

                        if ((int) $claim->transfer_movement_id !== (int) $sourceMovement->id) {
                            throw new RuntimeException("Klaim transit nomor seri {$liveSerial->serial_number} bukan milik pengiriman ini.");
                        }

                        if ((int) $claim->transfer_movement_serial_id !== (int) $movSerial->id) {
                            throw new RuntimeException("Klaim transit nomor seri {$liveSerial->serial_number} does not match source movement serial record.");
                        }

                        // Revalidate custody status is IN_TRANSIT
                        if ($movSerial->transit_custody_status !== TransferMovementSerial::CUSTODY_IN_TRANSIT) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} tidak dalam status IN_TRANSIT.");
                        }

                        // Close custody on source movement serial
                        $movSerial->update([
                            'transit_custody_status' => TransferMovementSerial::CUSTODY_CLOSED,
                            'custody_closed_at'      => $now,
                        ]);

                        // Move live serial location to destination
                        $liveSerial->update([
                            'location_id' => $destinationLocationId,
                        ]);

                        // Remove active claim
                        $claim->delete();

                        // Record serial history
                        SerialNumberHistoryService::record(
                            $liveSerial->id,
                            SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                            $destinationLocationId,
                            $lockedMovement,
                            sprintf(
                                'Receipt transfer V2 (Selesai Diterima) di %s dari %s',
                                $lockedTransfer->destinationLocation->name ?? '-',
                                $lockedTransfer->originLocation->name ?? '-'
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

                // Apply destination inventory addition
                $snapshot = $this->applyInventoryAddition($product, $stock, $allocation);

                $reason = sprintf(
                    'Forward receipt V2 at %s - %s from %s (Transfer #%d, Movement #%d)',
                    $lockedTransfer->destinationLocation->setting->company_name ?? '-',
                    $lockedTransfer->destinationLocation->name ?? '-',
                    $lockedTransfer->originLocation->name ?? '-',
                    $lockedTransfer->id,
                    $lockedMovement->id
                );

                $trx = Transaction::create([
                    'product_id'                    => $productId,
                    'setting_id'                    => $destinationSettingId,
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
                    'location_id'                   => $destinationLocationId,
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
                        'destination_classification'      => $policy->destination_classification,
                    ]);
                }

                // Reclassify serialized receipt tax identity per snapshotted policy
                if ($product->serial_number_required && $receiptLine) {
                    $newTaxId = null;
                    $newTaxName = null;
                    $newTaxRate = null;

                    if ($policy->destination_classification === TransferRoutePolicy::CLASSIFICATION_TAX) {
                        $newTaxId = $policy->resolved_tax_id;
                        $newTaxName = $policy->resolved_tax_name;
                        $newTaxRate = $policy->resolved_tax_rate;
                    }

                    foreach ($receiptLine->serials as $rSerial) {
                        if ($policy->destination_classification === TransferRoutePolicy::CLASSIFICATION_PRESERVE) {
                            continue;
                        }

                        $rSerial->update([
                            'previous_tax_id'   => $rSerial->tax_id,
                            'previous_tax_name' => $rSerial->tax_name,
                            'previous_tax_rate' => $rSerial->tax_rate,
                            'tax_id'            => $newTaxId,
                            'tax_name'          => $newTaxName,
                            'tax_rate'          => $newTaxRate,
                        ]);

                        if ($rSerial->product_serial_number_id) {
                            $liveReceiptSerial = $liveSerials->get($rSerial->product_serial_number_id);
                            if ($liveReceiptSerial) {
                                $liveReceiptSerial->update([
                                    'tax_id' => $newTaxId,
                                ]);
                            }
                        }
                    }
                }

                // Create full-quantity return obligation when the route requires return
                if ($policy->mandatory_return) {
                    $conditionValue = $lockedMovement->stock_condition === TransferMovement::CONDITION_GOOD
                        ? TransferMovementReturnObligation::CONDITION_GOOD
                        : TransferMovementReturnObligation::CONDITION_BREAKAGE;

                    TransferMovementReturnObligation::firstOrCreate(
                        [
                            'transfer_id'         => $lockedTransfer->id,
                            'receipt_movement_id' => $lockedMovement->id,
                            'product_id'          => $productId,
                            'stock_condition'     => $conditionValue,
                        ],
                        [
                            'transfer_route_policy_id' => $policy->id,
                            'required_quantity'        => $totalToAdd,
                            'returned_quantity'        => 0,
                            'status'                   => TransferMovementReturnObligation::STATUS_OUTSTANDING,
                        ]
                    );
                }
            }

            // Update movement status to APPROVED
            $lockedMovement->update([
                'status'      => TransferMovement::STATUS_APPROVED,
                'reviewed_by' => $userId,
                'reviewed_at' => $now,
                'updated_by'  => $userId,
            ]);

            \Modules\Adjustment\Entities\TransferMovementHistory::create([
                'transfer_movement_id' => $lockedMovement->id,
                'revision'             => $lockedMovement->revision,
                'action'               => \Modules\Adjustment\Entities\TransferMovementHistory::ACTION_APPROVED,
                'from_status'          => TransferMovement::STATUS_PENDING,
                'to_status'            => TransferMovement::STATUS_APPROVED,
                'actor_id'             => $userId,
                'reason'               => 'Forward receipt movement approved and destination inventory added.',
                'idempotency_key'      => $idempotencyKey,
            ]);

            // Project Transfer header to AWAITING_RETURN or COMPLETED per the immutable policy
            $lockedTransfer->update([
                'status'      => $policy->mandatory_return ? Transfer::STATUS_AWAITING_RETURN : Transfer::STATUS_COMPLETED,
                'revision'    => $lockedTransfer->revision + 1,
                'received_by' => $userId,
                'received_at' => $now,
            ]);

            return $lockedMovement->fresh(['lines.serials', 'histories']);
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

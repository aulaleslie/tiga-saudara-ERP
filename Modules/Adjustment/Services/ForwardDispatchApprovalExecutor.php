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
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use App\Services\SerialNumberHistoryService;
use Modules\Product\Entities\SerialNumberHistory;
use RuntimeException;

class ForwardDispatchApprovalExecutor
{
    public function __construct(
        private ForwardDispatchComparatorService $comparator,
    ) {
    }

    /**
     * Atomically approve a PENDING forward-dispatch movement, deduct origin inventory,
     * record before/after snapshots and inventory transactions, activate in-transit serial custody,
     * record movement history, and project the transfer header to DISPATCHED.
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

            // Aggregate Invariants
            if ((int) $lockedTransfer->workflow_version !== 2) {
                throw new RuntimeException("Version 2 approval executor only applies to workflow version 2 transfers.");
            }

            if ((int) $lockedMovement->transfer_id !== (int) $lockedTransfer->id) {
                throw new RuntimeException("Movement transfer ID mismatch.");
            }

            if ($lockedMovement->type !== TransferMovement::TYPE_FORWARD_DISPATCH) {
                throw new RuntimeException("Movement type is not FORWARD_DISPATCH.");
            }

            if ((int) $lockedMovement->origin_location_id !== (int) $lockedTransfer->origin_location_id ||
                (int) $lockedMovement->destination_location_id !== (int) $lockedTransfer->destination_location_id ||
                $lockedMovement->stock_condition !== $lockedTransfer->stock_condition) {
                throw new RuntimeException("Movement origin, destination, or condition does not match transfer header.");
            }

            // Idempotency check: if this movement is already approved and has matching history with idempotency_key, return it
            if ($idempotencyKey) {
                $existingHistory = TransferMovementHistory::where('transfer_movement_id', $lockedMovement->id)
                    ->where('revision', $lockedMovement->revision)
                    ->where('action', TransferMovementHistory::ACTION_APPROVED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory && $lockedMovement->status === TransferMovement::STATUS_APPROVED) {
                    return $lockedMovement->fresh(['lines.serials', 'histories']);
                }
            }

            // Lifecycle Invariants for fresh execution
            if ($lockedTransfer->status !== Transfer::STATUS_APPROVED) {
                throw new RuntimeException("Transfer must be in APPROVED status for dispatch approval. Current status: [{$lockedTransfer->status}].");
            }

            if ($lockedMovement->status !== TransferMovement::STATUS_PENDING) {
                throw new RuntimeException("Only PENDING movements can be approved. Current status: [{$lockedMovement->status}].");
            }

            if ((int) $lockedMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
            }

            // Enforce canonical comparison matches
            $comparison = $this->comparator->compare($lockedTransfer, $lockedMovement);
            if (!$comparison['matches']) {
                throw new RuntimeException("Forward dispatch cannot be approved: physical count does not match the approved transfer request.");
            }

            // Load lines and serials
            $lockedMovement->load(['lines.serials', 'lines.product']);

            // Lock all affected ProductStocks at origin
            $productIds = $lockedMovement->lines->pluck('product_id')->unique()->sort()->values()->all();
            $stocks = ProductStock::whereIn('product_id', $productIds)
                ->where('location_id', $lockedMovement->origin_location_id)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock all affected Products for global quantity consistency
            $products = Product::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Lock all affected serial numbers
            $allMovementSerials = $lockedMovement->serials()->lockForUpdate()->get();
            $serialIds = $allMovementSerials->pluck('product_serial_number_id')->filter()->values()->all();

            $liveSerials = [];
            if (!empty($serialIds)) {
                $liveSerials = ProductSerialNumber::whereIn('id', $serialIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
            }

            $isBrokenMode = $lockedMovement->stock_condition === TransferMovement::CONDITION_BREAKAGE;
            $now = Carbon::now();

            foreach ($lockedMovement->lines as $line) {
                $productId = (int) $line->product_id;
                $product = $products->get($productId) ?? Product::where('id', $productId)->lockForUpdate()->firstOrFail();
                $rawQty = (string) $line->quantity;

                // Validate integer stock constraint (current stock columns are integer-authoritative)
                if (str_contains($rawQty, '.')) {
                    $decimals = rtrim(explode('.', $rawQty, 2)[1], '0');
                    if (strlen($decimals) > 0) {
                        throw new RuntimeException("Quantity for product ID {$productId} must be an integer for inventory deduction. Fractional quantity [{$rawQty}] is not supported.");
                    }
                }

                $qtyToDeduct = (int) $rawQty;

                if ($qtyToDeduct <= 0) {
                    // Confirmed zero line: no stock deduction, zero snapshots
                    $line->update([
                        'applied_quantity_non_tax'        => '0.0000',
                        'applied_quantity_tax'            => '0.0000',
                        'applied_quantity_broken_non_tax' => '0.0000',
                        'applied_quantity_broken_tax'     => '0.0000',
                        'stock_snapshot_before'           => null,
                        'stock_snapshot_after'            => null,
                        'inventory_transaction_reference' => null,
                    ]);
                    continue;
                }

                $stock = $stocks->get($productId);
                if (!$stock) {
                    throw new RuntimeException("Stok tidak ditemukan untuk produk ID {$productId} di lokasi asal.");
                }

                $allocation = [
                    'total'          => $qtyToDeduct,
                    'tax'            => 0,
                    'non_tax'        => 0,
                    'broken_tax'     => 0,
                    'broken_non_tax' => 0,
                ];

                if ($product->serial_number_required) {
                    // Serialized allocation from locked live serials
                    $lineSerials = $line->serials;
                    if ($lineSerials->count() !== $qtyToDeduct) {
                        throw new RuntimeException("Jumlah serial ({$lineSerials->count()}) tidak sesuai dengan kuantitas yang diajukan ({$qtyToDeduct}) untuk produk ID {$productId}.");
                    }
                    foreach ($lineSerials as $movSerial) {
                        $liveSerial = $liveSerials->get($movSerial->product_serial_number_id);
                        if (!$liveSerial) {
                            throw new RuntimeException("Nomor seri {$movSerial->serial_number} tidak ditemukan.");
                        }

                        // Recheck exact serial identity and product association
                        $normMov = TransferMovementSerial::normalize($movSerial->serial_number);
                        $normLive = ProductSerialNumber::normalize((string) $liveSerial->serial_number);
                        if ($normMov !== $normLive) {
                            throw new RuntimeException("Live serial text [{$normLive}] does not match movement serial [{$normMov}].");
                        }

                        if ((int) $liveSerial->product_id !== $productId) {
                            throw new RuntimeException("Serial [{$normMov}] belongs to product ID {$liveSerial->product_id}, not {$productId}.");
                        }

                        // Revalidate live serial conditions and locations
                        if ($liveSerial->location_id === null || (int) $liveSerial->location_id !== (int) $lockedMovement->origin_location_id) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} tidak berada di lokasi asal.");
                        }

                        if ($liveSerial->dispatch_detail_id !== null) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} sudah dikirim.");
                        }

                        if ($liveSerial->is_in_return_process) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} dalam proses retur.");
                        }

                        // Check status is available/active
                        $status = $liveSerial->status;
                        if ($status !== null && !in_array($status, [ProductSerialNumber::STATUS_ACTIVE, ProductSerialNumber::STATUS_BROKEN], true)) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} berstatus tidak tersedia [{$status}].");
                        }

                        // Check existing active custody claim
                        $hasActiveClaim = TransferActiveSerialClaim::where('product_serial_number_id', $liveSerial->id)
                            ->lockForUpdate()
                            ->exists();

                        if ($hasActiveClaim) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} sedang dalam status transfer transit.");
                        }

                        $isBroken = (bool) ($liveSerial->is_broken ?? false);
                        $isTax = (bool) $liveSerial->tax_id;

                        if ($isBrokenMode && !$isBroken) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} bukan barang rusak.");
                        }
                        if (!$isBrokenMode && $isBroken) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} berstatus barang rusak.");
                        }

                        if ($isBroken) {
                            if ($isTax) {
                                $allocation['broken_tax']++;
                            } else {
                                $allocation['broken_non_tax']++;
                            }
                        } else {
                            if ($isTax) {
                                $allocation['tax']++;
                            } else {
                                $allocation['non_tax']++;
                            }
                        }

                        // Activate exclusive IN_TRANSIT custody without changing location_id
                        $movSerial->update([
                            'transit_custody_status' => TransferMovementSerial::CUSTODY_IN_TRANSIT,
                            'custody_started_at'     => $now,
                            'origin_location_id'     => $lockedMovement->origin_location_id,
                            'destination_location_id' => $lockedMovement->destination_location_id,
                        ]);

                        TransferActiveSerialClaim::create([
                            'product_serial_number_id'   => $liveSerial->id,
                            'transfer_movement_id'       => $lockedMovement->id,
                            'transfer_movement_serial_id' => $movSerial->id,
                        ]);

                        SerialNumberHistoryService::record(
                            $liveSerial->id,
                            SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                            $lockedMovement->origin_location_id,
                            $lockedMovement,
                            sprintf(
                                'Dispatch transfer V2 (In Transit) dari %s ke %s',
                                $lockedTransfer->originLocation->name ?? '-',
                                $lockedTransfer->destinationLocation->name ?? '-'
                            ),
                            $userId
                        );
                    }

                    $allocatedSum = $allocation['tax'] + $allocation['non_tax'] + $allocation['broken_tax'] + $allocation['broken_non_tax'];
                    if ($allocatedSum !== $qtyToDeduct) {
                        throw new RuntimeException("Alokasi serial ({$allocatedSum}) tidak sesuai dengan kuantitas baris ({$qtyToDeduct}) untuk produk ID {$productId}.");
                    }
                } else {
                    // Non-serialized allocation using non-tax-first rule
                    if (!$isBrokenMode) {
                        $availableNonTax = (int) $stock->quantity_non_tax;
                        $availableTax = (int) $stock->quantity_tax;

                        $allocNonTax = min((int) $qtyToDeduct, $availableNonTax);
                        $rem = (int) $qtyToDeduct - $allocNonTax;
                        $allocTax = min($rem, $availableTax);

                        if ($allocNonTax + $allocTax < (int) $qtyToDeduct) {
                            throw new RuntimeException("Stok tidak mencukupi untuk dialokasikan ke produk ID {$productId}.");
                        }

                        $allocation['non_tax'] = $allocNonTax;
                        $allocation['tax'] = $allocTax;
                    } else {
                        $availableBrokenNonTax = (int) $stock->broken_quantity_non_tax;
                        $availableBrokenTax = (int) $stock->broken_quantity_tax;

                        $allocBrokenNonTax = min((int) $qtyToDeduct, $availableBrokenNonTax);
                        $rem = (int) $qtyToDeduct - $allocBrokenNonTax;
                        $allocBrokenTax = min($rem, $availableBrokenTax);

                        if ($allocBrokenNonTax + $allocBrokenTax < (int) $qtyToDeduct) {
                            throw new RuntimeException("Stok rusak tidak mencukupi untuk dialokasikan ke produk ID {$productId}.");
                        }

                        $allocation['broken_non_tax'] = $allocBrokenNonTax;
                        $allocation['broken_tax'] = $allocBrokenTax;
                    }
                }

                // Apply inventory change
                $snapshot = $this->applyInventoryDeduction($product, $stock, $allocation);

                $reason = sprintf(
                    'Forward dispatch V2 to %s - %s (Transfer #%d, Movement #%d)',
                    $lockedTransfer->destinationLocation->setting->company_name ?? '-',
                    $lockedTransfer->destinationLocation->name ?? '-',
                    $lockedTransfer->id,
                    $lockedMovement->id
                );

                $trx = Transaction::create([
                    'product_id'                    => $productId,
                    'setting_id'                    => $lockedTransfer->originLocation->setting_id,
                    'type'                          => 'TRF',
                    'quantity'                      => -$snapshot['total'],
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
                    'location_id'                   => $lockedMovement->origin_location_id,
                    'user_id'                       => $userId,
                    'reason'                        => $reason,
                ]);

                // Update movement line with immutable approval fields
                $line->update([
                    'applied_quantity_non_tax'        => bcadd((string) $allocation['non_tax'], '0', 4),
                    'applied_quantity_tax'            => bcadd((string) $allocation['tax'], '0', 4),
                    'applied_quantity_broken_non_tax' => bcadd((string) $allocation['broken_non_tax'], '0', 4),
                    'applied_quantity_broken_tax'     => bcadd((string) $allocation['broken_tax'], '0', 4),
                    'stock_snapshot_before'           => $snapshot['previous_stock'],
                    'stock_snapshot_after'            => $snapshot['current_stock'],
                    'inventory_transaction_reference' => (string) $trx->id,
                ]);
            }

            // Update movement status to APPROVED
            $lockedMovement->update([
                'status'      => TransferMovement::STATUS_APPROVED,
                'reviewed_by' => $userId,
                'reviewed_at' => $now,
                'updated_by'  => $userId,
            ]);

            TransferMovementHistory::create([
                'transfer_movement_id' => $lockedMovement->id,
                'revision'             => $lockedMovement->revision,
                'action'               => TransferMovementHistory::ACTION_APPROVED,
                'from_status'          => TransferMovement::STATUS_PENDING,
                'to_status'            => TransferMovement::STATUS_APPROVED,
                'actor_id'             => $userId,
                'reason'               => 'Forward dispatch movement approved and inventory deducted.',
                'idempotency_key'      => $idempotencyKey,
            ]);

            // Project Transfer header to DISPATCHED
            $lockedTransfer->update([
                'status'        => Transfer::STATUS_DISPATCHED,
                'revision'      => $lockedTransfer->revision + 1,
                'dispatched_by' => $userId,
                'dispatched_at' => $now,
            ]);

            return $lockedMovement->fresh(['lines.serials', 'histories']);
        });
    }

    private function applyInventoryDeduction(Product $product, ProductStock $stock, array $allocation): array
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

        if ($previousStock['quantity_tax'] < $allocation['tax']) {
            throw new RuntimeException("Insufficient tax quantity. Available: {$previousStock['quantity_tax']}, Required: {$allocation['tax']}.");
        }
        if ($previousStock['quantity_non_tax'] < $allocation['non_tax']) {
            throw new RuntimeException("Insufficient non-tax quantity. Available: {$previousStock['quantity_non_tax']}, Required: {$allocation['non_tax']}.");
        }
        if ($previousStock['broken_tax'] < $allocation['broken_tax']) {
            throw new RuntimeException("Insufficient broken tax quantity. Available: {$previousStock['broken_tax']}, Required: {$allocation['broken_tax']}.");
        }
        if ($previousStock['broken_non_tax'] < $allocation['broken_non_tax']) {
            throw new RuntimeException("Insufficient broken non-tax quantity. Available: {$previousStock['broken_non_tax']}, Required: {$allocation['broken_non_tax']}.");
        }

        if ($previousProductQuantity < $total) {
            throw new RuntimeException("Global product quantity underflow: Available: {$previousProductQuantity}, Required: {$total}.");
        }
        if ($previousProductBroken < $brokenTotal) {
            throw new RuntimeException("Global product broken quantity underflow: Available: {$previousProductBroken}, Required: {$brokenTotal}.");
        }

        $stock->quantity_tax            = $previousStock['quantity_tax'] - $allocation['tax'];
        $stock->quantity_non_tax        = $previousStock['quantity_non_tax'] - $allocation['non_tax'];
        $stock->broken_quantity_tax     = $previousStock['broken_tax'] - $allocation['broken_tax'];
        $stock->broken_quantity_non_tax = $previousStock['broken_non_tax'] - $allocation['broken_non_tax'];

        $product->product_quantity = $previousProductQuantity - $total;
        $product->broken_quantity  = $previousProductBroken - $brokenTotal;

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

<?php

namespace Modules\Adjustment\Services;

use App\Services\SerialNumberHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementHistory;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferReturnObligationReservation;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use RuntimeException;

class ReturnDispatchApprovalExecutor
{
    public function __construct(
        private ReturnDispatchComparatorService $comparator,
    ) {
    }

    /**
     * Atomically approve a PENDING return-dispatch batch: deduct destination-classified inventory,
     * activate return-leg serial custody, reserve exact obligation capacity, freeze the approved
     * manifest, and project the transfer header. Fully rolled back on any failure.
     */
    public function approve(
        Transfer $transfer,
        TransferMovement $movement,
        int $userId,
        ?string $idempotencyKey = null
    ): TransferMovement {
        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($transfer, $movement, $userId, $idempotencyKey) {
            $lockedTransfer = Transfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();
            $lockedMovement = TransferMovement::where('id', $movement->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedTransfer->workflow_version !== 2) {
                throw new RuntimeException("Version 2 approval executor only applies to workflow version 2 transfers.");
            }

            app(TransferWorkflowEligibilityService::class)->validateV2Eligibility($lockedTransfer);

            if ((int) $lockedMovement->transfer_id !== (int) $lockedTransfer->id) {
                throw new RuntimeException("Movement transfer ID mismatch.");
            }

            if ($lockedMovement->type !== TransferMovement::TYPE_RETURN_DISPATCH) {
                throw new RuntimeException("Movement type is not RETURN_DISPATCH.");
            }

            if ((int) $lockedMovement->origin_location_id !== (int) $lockedTransfer->destination_location_id ||
                (int) $lockedMovement->destination_location_id !== (int) $lockedTransfer->origin_location_id) {
                throw new RuntimeException("Return dispatch movement locations do not match the transfer's reversed leg.");
            }

            // Idempotency: replay if already approved under this exact key
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

            if (!in_array($lockedTransfer->status, [Transfer::STATUS_AWAITING_RETURN, Transfer::STATUS_RETURN_DISPATCHED], true)) {
                throw new RuntimeException("Transfer must be AWAITING_RETURN or RETURN_DISPATCHED for return dispatch approval. Current status: [{$lockedTransfer->status}].");
            }

            if ($lockedMovement->status !== TransferMovement::STATUS_PENDING) {
                throw new RuntimeException("Only PENDING return-dispatch batches can be approved. Current status: [{$lockedMovement->status}].");
            }

            if ((int) $lockedMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
            }

            $policy = TransferRoutePolicy::where('transfer_id', $lockedTransfer->id)
                ->orderByDesc('transfer_revision')
                ->first();

            if (!$policy || !$policy->mandatory_return) {
                throw new RuntimeException("This transfer's committed route policy does not require a return dispatch.");
            }

            // Canonical comparison against obligations (partial batches are valid)
            $comparison = $this->comparator->compare($lockedTransfer, $lockedMovement);
            if (!$comparison['matches']) {
                throw new RuntimeException("Return dispatch cannot be approved: physical count does not match obligated products.");
            }

            $lockedMovement->load(['lines.serials', 'lines.product']);
            $confirmedLines = $lockedMovement->lines->filter(
                fn ($line) => (bool) $line->count_confirmed && bccomp((string) $line->quantity, '0', 4) > 0
            );

            if ($confirmedLines->isEmpty()) {
                throw new RuntimeException("Batch retur kosong tidak dapat disetujui.");
            }

            $productIds = $confirmedLines->pluck('product_id')->unique()->sort()->values()->all();

            // Lock obligations (product/condition) in stable order
            $obligations = TransferMovementReturnObligation::where('transfer_id', $lockedTransfer->id)
                ->where('stock_condition', $lockedMovement->stock_condition)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock existing active reservations for these obligations
            $obligationIds = $obligations->pluck('id')->all();
            $activeReservations = TransferReturnObligationReservation::whereIn('transfer_movement_return_obligation_id', $obligationIds)
                ->where('status', TransferReturnObligationReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->groupBy('transfer_movement_return_obligation_id');

            // Lock destination-side ProductStock and Product rows
            $stocks = ProductStock::whereIn('product_id', $productIds)
                ->where('location_id', $lockedMovement->origin_location_id)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            // Lock movement serials and live serials
            $allMovementSerials = $lockedMovement->serials()->lockForUpdate()->get();
            $serialIds = $allMovementSerials->pluck('product_serial_number_id')->filter()->values()->all();

            $liveSerials = [];
            if (!empty($serialIds)) {
                $liveSerials = ProductSerialNumber::whereIn('id', $serialIds)->lockForUpdate()->get()->keyBy('id');
            }

            $isBrokenMode = $lockedMovement->stock_condition === TransferMovement::CONDITION_BREAKAGE;
            $isTaxClassification = $policy->destination_classification === TransferRoutePolicy::CLASSIFICATION_TAX;
            $isNonTaxClassification = $policy->destination_classification === TransferRoutePolicy::CLASSIFICATION_NON_TAX;
            $now = Carbon::now();

            foreach ($confirmedLines as $line) {
                $productId = (int) $line->product_id;
                $product = $products->get($productId) ?? Product::where('id', $productId)->lockForUpdate()->firstOrFail();
                $rawQty = bcadd((string) $line->quantity, '0', 4);

                // Inventory columns are integer-authoritative: reject any non-integral quantity
                // before mutation using exact decimal string inspection, never a float/round
                // conversion (which would silently truncate or misround, e.g. 1.4000 -> 1, 1.5000 -> 2,
                // desynchronizing the reservation, transaction, and inventory record from the
                // frozen approved manifest).
                if (str_contains($rawQty, '.')) {
                    $decimals = rtrim(explode('.', $rawQty, 2)[1], '0');
                    if (strlen($decimals) > 0) {
                        throw new RuntimeException("Kuantitas retur untuk produk ID {$productId} harus berupa bilangan bulat untuk pengurangan stok. Kuantitas pecahan [{$rawQty}] tidak didukung.");
                    }
                }

                $qtyToDeduct = (int) $rawQty;

                $obligation = $obligations->get($productId);
                if (!$obligation) {
                    throw new RuntimeException("Produk ID {$productId} bukan kewajiban retur untuk transfer ini.");
                }

                $existingActive = $activeReservations->get($obligation->id, collect());
                // Fold via bcadd() rather than Collection::sum(), which coerces each addend to a
                // PHP float and can reintroduce rounding error before BCMath ever sees the total.
                $activeInTransit = '0.0000';
                foreach ($existingActive as $reservation) {
                    $activeInTransit = bcadd($activeInTransit, (string) $reservation->quantity, 4);
                }
                $committed = bcadd((string) $obligation->returned_quantity, $activeInTransit, 4);
                $projected = bcadd($committed, (string) $line->quantity, 4);

                if (bccomp($projected, (string) $obligation->required_quantity, 4) > 0) {
                    throw new RuntimeException("Kuantitas retur untuk produk ID {$productId} melebihi kewajiban yang tersisa (kapasitas telah terpakai oleh batch lain).");
                }

                $stock = $stocks->get($productId);
                if (!$stock) {
                    throw new RuntimeException("Stok tidak ditemukan untuk produk ID {$productId} di lokasi tujuan retur.");
                }

                $allocation = [
                    'total'          => $qtyToDeduct,
                    'tax'            => 0,
                    'non_tax'        => 0,
                    'broken_tax'     => 0,
                    'broken_non_tax' => 0,
                ];

                if ($product->serial_number_required) {
                    $lineSerials = $line->serials;
                    if ($lineSerials->count() !== $qtyToDeduct) {
                        throw new RuntimeException("Jumlah serial ({$lineSerials->count()}) tidak sesuai dengan kuantitas ({$qtyToDeduct}) untuk produk ID {$productId}.");
                    }

                    foreach ($lineSerials as $movSerial) {
                        $liveSerial = $liveSerials->get($movSerial->product_serial_number_id);
                        if (!$liveSerial) {
                            throw new RuntimeException("Nomor seri {$movSerial->serial_number} tidak ditemukan.");
                        }

                        $normMov = TransferMovementSerial::normalize($movSerial->serial_number);
                        $normLive = ProductSerialNumber::normalize((string) $liveSerial->serial_number);
                        if ($normMov !== $normLive) {
                            throw new RuntimeException("Live serial text [{$normLive}] does not match movement serial [{$normMov}].");
                        }

                        if ((int) $liveSerial->product_id !== $productId) {
                            throw new RuntimeException("Serial [{$normMov}] belongs to product ID {$liveSerial->product_id}, not {$productId}.");
                        }

                        if ($liveSerial->location_id === null || (int) $liveSerial->location_id !== (int) $lockedMovement->origin_location_id) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} tidak berada di lokasi tujuan retur.");
                        }

                        if ($liveSerial->dispatch_detail_id !== null) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} sudah dikirim.");
                        }

                        if ($liveSerial->is_in_return_process) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} dalam proses retur.");
                        }

                        $status = $liveSerial->status;
                        if ($status !== null && !in_array($status, [ProductSerialNumber::STATUS_ACTIVE, ProductSerialNumber::STATUS_BROKEN], true)) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} berstatus tidak tersedia [{$status}].");
                        }

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

                        if ($policy->destination_classification !== TransferRoutePolicy::CLASSIFICATION_PRESERVE) {
                            if ($isTaxClassification !== $isTax) {
                                throw new RuntimeException("Nomor seri {$liveSerial->serial_number} tidak sesuai dengan klasifikasi pajak tujuan retur.");
                            }
                        }

                        if ($isBroken) {
                            $isTax ? $allocation['broken_tax']++ : $allocation['broken_non_tax']++;
                        } else {
                            $isTax ? $allocation['tax']++ : $allocation['non_tax']++;
                        }

                        $movSerial->update([
                            'transit_custody_status'  => TransferMovementSerial::CUSTODY_IN_TRANSIT,
                            'custody_started_at'      => $now,
                            'origin_location_id'      => $lockedMovement->origin_location_id,
                            'destination_location_id' => $lockedMovement->destination_location_id,
                        ]);

                        TransferActiveSerialClaim::create([
                            'product_serial_number_id'    => $liveSerial->id,
                            'transfer_movement_id'        => $lockedMovement->id,
                            'transfer_movement_serial_id' => $movSerial->id,
                        ]);

                        SerialNumberHistoryService::record(
                            $liveSerial->id,
                            SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                            $lockedMovement->origin_location_id,
                            $lockedMovement,
                            sprintf(
                                'Return dispatch V2 (In Transit) dari %s ke %s',
                                $lockedTransfer->destinationLocation->name ?? '-',
                                $lockedTransfer->originLocation->name ?? '-'
                            ),
                            $userId
                        );
                    }

                    $allocatedSum = $allocation['tax'] + $allocation['non_tax'] + $allocation['broken_tax'] + $allocation['broken_non_tax'];
                    if ($allocatedSum !== $qtyToDeduct) {
                        throw new RuntimeException("Alokasi serial ({$allocatedSum}) tidak sesuai dengan kuantitas baris ({$qtyToDeduct}) untuk produk ID {$productId}.");
                    }
                } else {
                    // Non-serialized: deduct from the destination-classified bucket recorded by the route policy
                    if (!$isBrokenMode) {
                        if ($policy->destination_classification === TransferRoutePolicy::CLASSIFICATION_PRESERVE) {
                            $availableNonTax = (int) $stock->quantity_non_tax;
                            $availableTax = (int) $stock->quantity_tax;
                            $allocNonTax = min($qtyToDeduct, $availableNonTax);
                            $rem = $qtyToDeduct - $allocNonTax;
                            $allocTax = min($rem, $availableTax);

                            if ($allocNonTax + $allocTax < $qtyToDeduct) {
                                throw new RuntimeException("Stok tidak mencukupi untuk produk ID {$productId}.");
                            }

                            $allocation['non_tax'] = $allocNonTax;
                            $allocation['tax'] = $allocTax;
                        } elseif ($isTaxClassification) {
                            if ((int) $stock->quantity_tax < $qtyToDeduct) {
                                throw new RuntimeException("Stok kena pajak tidak mencukupi untuk produk ID {$productId}.");
                            }
                            $allocation['tax'] = $qtyToDeduct;
                        } else {
                            if ((int) $stock->quantity_non_tax < $qtyToDeduct) {
                                throw new RuntimeException("Stok non-pajak tidak mencukupi untuk produk ID {$productId}.");
                            }
                            $allocation['non_tax'] = $qtyToDeduct;
                        }
                    } else {
                        if ($policy->destination_classification === TransferRoutePolicy::CLASSIFICATION_PRESERVE) {
                            $availableBrokenNonTax = (int) $stock->broken_quantity_non_tax;
                            $availableBrokenTax = (int) $stock->broken_quantity_tax;
                            $allocBrokenNonTax = min($qtyToDeduct, $availableBrokenNonTax);
                            $rem = $qtyToDeduct - $allocBrokenNonTax;
                            $allocBrokenTax = min($rem, $availableBrokenTax);

                            if ($allocBrokenNonTax + $allocBrokenTax < $qtyToDeduct) {
                                throw new RuntimeException("Stok rusak tidak mencukupi untuk produk ID {$productId}.");
                            }

                            $allocation['broken_non_tax'] = $allocBrokenNonTax;
                            $allocation['broken_tax'] = $allocBrokenTax;
                        } elseif ($isTaxClassification) {
                            if ((int) $stock->broken_quantity_tax < $qtyToDeduct) {
                                throw new RuntimeException("Stok rusak kena pajak tidak mencukupi untuk produk ID {$productId}.");
                            }
                            $allocation['broken_tax'] = $qtyToDeduct;
                        } else {
                            if ((int) $stock->broken_quantity_non_tax < $qtyToDeduct) {
                                throw new RuntimeException("Stok rusak non-pajak tidak mencukupi untuk produk ID {$productId}.");
                            }
                            $allocation['broken_non_tax'] = $qtyToDeduct;
                        }
                    }
                }

                $snapshot = $this->applyInventoryDeduction($product, $stock, $allocation);

                $reason = sprintf(
                    'Return dispatch V2 to %s - %s (Transfer #%d, Movement #%d)',
                    $lockedTransfer->originLocation->setting->company_name ?? '-',
                    $lockedTransfer->originLocation->name ?? '-',
                    $lockedTransfer->id,
                    $lockedMovement->id
                );

                $trx = Transaction::create([
                    'product_id'                    => $productId,
                    'setting_id'                    => $lockedTransfer->destinationLocation->setting_id ?? null,
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

                $line->update([
                    'applied_quantity_non_tax'        => bcadd((string) $allocation['non_tax'], '0', 4),
                    'applied_quantity_tax'            => bcadd((string) $allocation['tax'], '0', 4),
                    'applied_quantity_broken_non_tax' => bcadd((string) $allocation['broken_non_tax'], '0', 4),
                    'applied_quantity_broken_tax'     => bcadd((string) $allocation['broken_tax'], '0', 4),
                    'stock_snapshot_before'           => $snapshot['previous_stock'],
                    'stock_snapshot_after'            => $snapshot['current_stock'],
                    'inventory_transaction_reference' => (string) $trx->id,
                    'destination_classification'      => $policy->destination_classification,
                ]);

                // Persist the immutable active reservation for this approved line
                TransferReturnObligationReservation::create([
                    'transfer_movement_return_obligation_id' => $obligation->id,
                    'transfer_movement_id'                    => $lockedMovement->id,
                    'transfer_movement_line_id'                => $line->id,
                    'quantity'                                 => (string) $line->quantity,
                    'status'                                   => TransferReturnObligationReservation::STATUS_ACTIVE,
                    'created_by'                                => $userId,
                ]);
            }

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
                'reason'               => 'Return dispatch batch approved and inventory deducted.',
                'idempotency_key'      => $idempotencyKey,
            ]);

            // Project transfer header status only: at least one active reservation => RETURN_DISPATCHED.
            // Unlike the forward leg, this projection deliberately does NOT bump transfer.revision:
            // that field is the concurrency guard every open/pending return-dispatch batch pins itself
            // to at creation, and several independent batches may be prepared, submitted, and approved
            // concurrently. Bumping it here would invalidate every other batch's stale-draft checks
            // the moment the first batch approves, contradicting the concurrent-approval design.
            // Completion and reverting to AWAITING_RETURN once batches are received belong to Delivery 9.
            if ($lockedTransfer->status !== Transfer::STATUS_RETURN_DISPATCHED) {
                $lockedTransfer->update([
                    'status' => Transfer::STATUS_RETURN_DISPATCHED,
                ]);
            }

            return $lockedMovement->fresh(['lines.serials', 'histories']);
        });
    }

    private function applyInventoryDeduction(Product $product, ProductStock $stock, array $allocation): array
    {
        $total = $allocation['total'];
        $brokenTotal = $allocation['broken_tax'] + $allocation['broken_non_tax'];

        $previousStock = [
            'quantity_tax'   => (int) ($stock->quantity_tax ?? 0),
            'quantity_non_tax' => (int) ($stock->quantity_non_tax ?? 0),
            'broken_tax'     => (int) ($stock->broken_quantity_tax ?? 0),
            'broken_non_tax' => (int) ($stock->broken_quantity_non_tax ?? 0),
        ];

        $previousStockQuantity = (int) ($stock->quantity ?? array_sum($previousStock));
        $previousBrokenQuantity = (int) ($stock->broken_quantity ?? ($previousStock['broken_tax'] + $previousStock['broken_non_tax']));
        $previousProductQuantity = (int) ($product->product_quantity ?? 0);
        $previousProductBroken = (int) ($product->broken_quantity ?? 0);

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
            'previous_stock'   => [
                'quantity'         => $previousStockQuantity,
                'broken'           => $previousBrokenQuantity,
                'quantity_tax'     => $previousStock['quantity_tax'],
                'quantity_non_tax' => $previousStock['quantity_non_tax'],
                'broken_tax'       => $previousStock['broken_tax'],
                'broken_non_tax'   => $previousStock['broken_non_tax'],
            ],
            'current_stock'    => [
                'quantity'         => (int) $stock->quantity,
                'broken'           => (int) $stock->broken_quantity,
                'quantity_tax'     => (int) $stock->quantity_tax,
                'quantity_non_tax' => (int) $stock->quantity_non_tax,
                'broken_tax'       => (int) $stock->broken_quantity_tax,
                'broken_non_tax'   => (int) $stock->broken_quantity_non_tax,
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

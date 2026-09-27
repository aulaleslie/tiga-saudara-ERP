<?php

namespace Modules\Adjustment\Services;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferReturnObligationReservation;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Tax;
use RuntimeException;

class ReturnReceiptPreparationService
{
    public function __construct(
        private TransferMovementDocumentService $documentService,
        private TransferScanResolverService $scanResolverService,
        private ReturnReceiptProjectionService $projectionService,
    ) {
    }

    /**
     * Get or create the single open return receipt draft for an approved source return-dispatch batch.
     * Starts completely empty with NO lines copied (blind start).
     */
    public function getOrCreateDraft(Transfer $transfer, TransferMovement $sourceDispatch, int $userId): TransferMovement
    {
        return DB::transaction(function () use ($transfer, $sourceDispatch, $userId) {
            $lockedTransfer = Transfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();
            $lockedSource = TransferMovement::where('id', $sourceDispatch->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedTransfer->workflow_version !== 2) {
                throw new RuntimeException("Return receipt preparation is only available for workflow version 2 transfers.");
            }

            app(TransferWorkflowEligibilityService::class)->validateV2Eligibility($lockedTransfer);

            if (!in_array($lockedTransfer->status, [Transfer::STATUS_AWAITING_RETURN, Transfer::STATUS_RETURN_DISPATCHED], true)) {
                throw new RuntimeException("Transfer must be in AWAITING_RETURN or RETURN_DISPATCHED status for return receipt preparation.");
            }

            if ((int) $lockedSource->transfer_id !== (int) $lockedTransfer->id) {
                throw new RuntimeException("Source return dispatch does not belong to this transfer.");
            }

            if ($lockedSource->type !== TransferMovement::TYPE_RETURN_DISPATCH || $lockedSource->status !== TransferMovement::STATUS_APPROVED) {
                throw new RuntimeException("Source movement must be an APPROVED RETURN_DISPATCH.");
            }

            $policy = TransferRoutePolicy::where('transfer_id', $lockedTransfer->id)
                ->orderByDesc('transfer_revision')
                ->first();

            if (!$policy || !$policy->mandatory_return) {
                throw new RuntimeException("This transfer route policy does not require return fulfillment.");
            }

            // Check if this source batch has active reservations
            $hasActiveReservations = TransferReturnObligationReservation::where('transfer_movement_id', $lockedSource->id)
                ->where('status', TransferReturnObligationReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->exists();

            if (!$hasActiveReservations) {
                throw new RuntimeException("Source return dispatch batch has no active reservations or has already been completed.");
            }

            $batchId = $lockedSource->return_batch_id;

            // Check if there is an existing open attempt (DRAFT or PENDING) for this batch lineage
            $existingMovement = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', TransferMovement::TYPE_RETURN_RECEIPT)
                ->where('return_batch_id', $batchId)
                ->whereIn('status', [TransferMovement::STATUS_DRAFT, TransferMovement::STATUS_PENDING])
                ->lockForUpdate()
                ->first();

            if ($existingMovement) {
                if ((int) $existingMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                    throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
                }

                return $existingMovement->load(['lines.serials', 'lines.product', 'histories']);
            }

            // Check if an approved movement already exists for this return batch
            $approvedReceipt = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', TransferMovement::TYPE_RETURN_RECEIPT)
                ->where('return_batch_id', $batchId)
                ->where('status', TransferMovement::STATUS_APPROVED)
                ->lockForUpdate()
                ->first();

            if ($approvedReceipt) {
                throw new RuntimeException("Return receipt has already been APPROVED for this return batch.");
            }

            // Create draft with NO seeded lines (completely blind)
            return $this->documentService->createDraft(
                $lockedTransfer,
                TransferMovement::TYPE_RETURN_RECEIPT,
                $userId,
                [],
                $lockedSource->id,
                null,
                $batchId
            );
        });
    }

    /**
     * Set explicit document-level empty count confirmation.
     * Requires expected lock version and disallows confirming empty when line observations exist.
     */
    public function confirmEmpty(TransferMovement $movement, int $expectedLockVersion, int $userId): TransferMovement
    {
        return DB::transaction(function () use ($movement, $expectedLockVersion, $userId) {
            $lockedMovement = TransferMovement::where('id', $movement->id)
                ->with(['lines.serials'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMovement->status !== TransferMovement::STATUS_DRAFT) {
                throw new RuntimeException("Only DRAFT movements can be modified.");
            }

            if ($lockedMovement->type !== TransferMovement::TYPE_RETURN_RECEIPT) {
                throw new RuntimeException("Empty confirmation only applies to RETURN_RECEIPT movements.");
            }

            if ((int) $lockedMovement->lock_version !== (int) $expectedLockVersion) {
                throw new RuntimeException("Data penerimaan telah diperbarui oleh pengguna lain. Silakan muat ulang halaman.");
            }

            $hasObservations = $lockedMovement->lines->some(fn ($l) => (float) $l->quantity > 0 || $l->serials->count() > 0);
            if ($hasObservations) {
                throw new RuntimeException("Tidak dapat mengonfirmasi penerimaan kosong karena sudah terdapat barang yang di-scan atau dicatat. Hapus barang terlebih dahulu.");
            }

            $now = Carbon::now();
            $lockedMovement->update([
                'empty_count_confirmed'    => true,
                'empty_count_confirmed_by' => $userId,
                'empty_count_confirmed_at' => $now,
                'lock_version'             => $lockedMovement->lock_version + 1,
                'updated_by'               => $userId,
            ]);

            return $lockedMovement->fresh(['lines.serials', 'histories']);
        });
    }

    /**
     * Clear document-level empty count confirmation.
     */
    public function clearEmptyConfirmation(TransferMovement $movement): void
    {
        if ($movement->empty_count_confirmed) {
            $movement->update([
                'empty_count_confirmed'    => false,
                'empty_count_confirmed_by' => null,
                'empty_count_confirmed_at' => null,
            ]);
        }
    }

    /**
     * Set explicit quantity and confirmation state on a movement line.
     */
    public function setLineQuantity(
        TransferMovement $movement,
        int $productId,
        $quantity,
        bool $confirmed,
        int $expectedLockVersion,
        int $userId
    ): TransferMovement {
        return DB::transaction(function () use ($movement, $productId, $quantity, $confirmed, $expectedLockVersion, $userId) {
            $lockedMovement = TransferMovement::where('id', $movement->id)
                ->with(['lines.serials', 'sourceMovement.lines'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMovement->status !== TransferMovement::STATUS_DRAFT) {
                throw new RuntimeException("Only DRAFT movements can be edited.");
            }

            $currentLines = $this->extractLinesDataFromMovement($lockedMovement);

            $qtyStr = bcadd((string) $quantity, '0', 4);
            $qtyFloat = (float) $qtyStr;
            if ($qtyFloat < 0) {
                throw new RuntimeException("Line quantity cannot be negative.");
            }

            $lockedTransfer = Transfer::where('id', $lockedMovement->transfer_id)
                ->with(['originLocation', 'destinationLocation'])
                ->firstOrFail();

            $originSettingId = (int) ($lockedTransfer->originLocation?->setting_id ?? $lockedMovement->destinationLocation?->setting_id);
            $destSettingId = (int) ($lockedTransfer->destinationLocation?->setting_id ?? $lockedMovement->originLocation?->setting_id);
            $sourceProductIds = $lockedMovement->sourceMovement ? $lockedMovement->sourceMovement->lines->pluck('product_id') : collect();

            $product = Product::query()
                ->active()
                ->where('stock_managed', true)
                ->where('id', $productId)
                ->where(function ($q) use ($destSettingId, $originSettingId, $sourceProductIds) {
                    $q->where('setting_id', $originSettingId)
                        ->orWhere('setting_id', $destSettingId)
                        ->orWhereIn('id', $sourceProductIds);
                })
                ->first();

            if (!$product) {
                throw new RuntimeException("Produk tidak valid atau tidak terdaftar pada unit bisnis asal.");
            }

            if ($product->serial_number_required && $qtyFloat > 0) {
                throw new RuntimeException("Kuantitas produk dengan nomor seri tidak dapat diubah secara manual. Silakan scan nomor seri produk.");
            }

            $lineConfirmed = $qtyFloat > 0 ? true : $confirmed;

            $found = false;
            foreach ($currentLines as &$row) {
                if ((int) $row['product_id'] === $productId) {
                    $row['quantity'] = $qtyStr;
                    $row['count_confirmed'] = $lineConfirmed;
                    if ($qtyFloat == 0 && !empty($row['serials'])) {
                        $row['serials'] = [];
                    }
                    $found = true;
                    break;
                }
            }
            unset($row);

            if (!$found) {
                if ($qtyFloat > 0 || $lineConfirmed) {
                    $currentLines[] = [
                        'product_id'      => $productId,
                        'quantity'        => $qtyStr,
                        'count_confirmed' => $lineConfirmed,
                        'serials'         => [],
                    ];
                }
            }

            // Clear empty count confirmation as part of mutation
            $this->clearEmptyConfirmation($lockedMovement);

            return $this->documentService->updateDraft(
                $lockedMovement,
                $expectedLockVersion,
                $currentLines,
                $userId
            );
        });
    }

    /**
     * Scan a barcode, conversion barcode, or serial number and apply to the return receipt draft.
     */
    public function applyScan(
        TransferMovement $movement,
        string $query,
        int $settingId,
        int $expectedLockVersion,
        int $userId,
        bool $canViewSystemStock = false
    ): array {
        return DB::transaction(function () use ($movement, $query, $settingId, $expectedLockVersion, $userId, $canViewSystemStock) {
            $lockedTransfer = Transfer::where('id', $movement->transfer_id)
                ->with(['originLocation', 'destinationLocation'])
                ->lockForUpdate()
                ->firstOrFail();

            $lockedMovement = TransferMovement::where('id', $movement->id)
                ->with(['lines.serials'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMovement->status !== TransferMovement::STATUS_DRAFT) {
                throw new RuntimeException("Only DRAFT movements can be edited.");
            }

            if ((int) $lockedMovement->lock_version !== (int) $expectedLockVersion) {
                throw new RuntimeException("Optimistic lock error: expected lock_version {$expectedLockVersion} but movement is at lock_version {$lockedMovement->lock_version}.");
            }

            if ((int) $lockedMovement->transfer_id !== (int) $lockedTransfer->id) {
                throw new RuntimeException("Movement transfer mismatch.");
            }

            $isBrokenMode = $lockedMovement->stock_condition === TransferMovement::CONDITION_BREAKAGE;

            // Return receipt physical receiving is at the original transfer location (which is destination_location_id of the return leg)
            $receivingLocationId = (int) $lockedMovement->destination_location_id;

            $scanResult = $this->scanResolverService->resolve(
                $settingId,
                $query,
                $receivingLocationId,
                $isBrokenMode,
                true // allowZeroStock
            );

            // Cross-business fallback: if not found in origin setting, check destination setting catalog
            $destSettingId = (int) ($lockedTransfer->destinationLocation?->setting_id ?? $lockedMovement->originLocation?->setting_id);
            $destLocationId = (int) ($lockedTransfer->destination_location_id ?? $lockedMovement->origin_location_id);
            if (($scanResult['status'] === 'not_found' || $scanResult['status'] === 'none') && $destSettingId !== $settingId) {
                $destScan = $this->scanResolverService->resolve(
                    $destSettingId,
                    $query,
                    $destLocationId,
                    $isBrokenMode,
                    true
                );
                if ($destScan['status'] === 'resolved') {
                    $scanResult = $destScan;
                }
            }

            $currentLines = $this->extractLinesDataFromMovement($lockedMovement);

            if ($scanResult['status'] === 'rejected' || $scanResult['status'] === 'not_found' || $scanResult['status'] === 'none') {
                $normalizedSerial = ProductSerialNumber::normalize($query);

                // Search live serial across origin or destination setting
                $liveSerial = ProductSerialNumber::where('serial_number', $normalizedSerial)
                    ->whereHas('product', fn ($q) => $q->active()->whereIn('setting_id', [$settingId, $destSettingId])->where('stock_managed', true))
                    ->with('product')
                    ->first();

                // If not found in setting directly, check if it was part of source return dispatch
                if (!$liveSerial) {
                    $sourceSerial = TransferMovementSerial::where('transfer_movement_id', $lockedMovement->source_movement_id)
                        ->where('serial_number', $normalizedSerial)
                        ->with('product')
                        ->first();

                    if ($sourceSerial && $sourceSerial->product) {
                        $liveSerial = ProductSerialNumber::find($sourceSerial->product_serial_number_id);
                    }
                }

                if ($liveSerial && $liveSerial->product) {
                    // Valid serial observation without revealing expectation
                    $scanResult = [
                        'status'    => 'resolved',
                        'type'      => 'serial_exact',
                        'candidate' => [
                            'type'        => 'serial',
                            'description' => "Nomor Seri: {$normalizedSerial} - {$liveSerial->product->product_name}",
                            'serial'      => [
                                'id'                       => (int) $liveSerial->id,
                                'product_serial_number_id' => (int) $liveSerial->id,
                                'serial_number'            => $normalizedSerial,
                                'product_id'               => (int) $liveSerial->product_id,
                                'tax_id'                   => $liveSerial->tax_id,
                            ],
                            'product'     => [
                                'id'                     => (int) $liveSerial->product->id,
                                'product_name'           => (string) $liveSerial->product->product_name,
                                'product_code'           => (string) ($liveSerial->product->product_code ?? ''),
                                'serial_number_required' => true,
                            ],
                        ],
                    ];
                } else {
                    return [
                        'status'     => 'not_found',
                        'message'    => "Barcode atau nomor seri '{$query}' tidak ditemukan.",
                        'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $lockedMovement, $canViewSystemStock),
                    ];
                }
            }

            if ($scanResult['status'] === 'ambiguous') {
                $candidates = [];
                foreach (($scanResult['candidates'] ?? []) as $cand) {
                    $prod = $cand['product'] ?? [];
                    $candidates[] = [
                        'type'                   => $cand['type'] ?? 'product',
                        'description'            => $cand['description'] ?? '',
                        'product_id'             => (int) ($prod['id'] ?? 0),
                        'product_name'           => (string) ($prod['product_name'] ?? ''),
                        'product_code'           => (string) ($prod['product_code'] ?? ''),
                        'serial_number_required' => (bool) ($prod['serial_number_required'] ?? false),
                    ];
                }

                return [
                    'status'     => 'ambiguous',
                    'candidates' => $candidates,
                    'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $lockedMovement, $canViewSystemStock),
                ];
            }

            $candidate = $scanResult['candidate'] ?? $scanResult;
            $type = $candidate['type'] ?? '';

            if ($type === 'serial' || $type === 'serial_exact') {
                $serialData = $candidate['serial'];
                $productData = $candidate['product'];
                $productId = (int) $productData['id'];
                $normalizedSerial = TransferMovementSerial::normalize($serialData['serial_number']);

                // Duplicate serial check
                foreach ($currentLines as $lineRow) {
                    foreach ($lineRow['serials'] as $s) {
                        $sNum = is_array($s) ? ($s['serial_number'] ?? '') : (string) $s;
                        if (TransferMovementSerial::normalize($sNum) === $normalizedSerial) {
                            return [
                                'status'     => 'rejected',
                                'message'    => "Nomor seri [{$normalizedSerial}] sudah dipilih.",
                                'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $lockedMovement, $canViewSystemStock),
                            ];
                        }
                    }
                }

                // Add serial to matching product line or create new line
                $lineIndex = null;
                foreach ($currentLines as $idx => $lineRow) {
                    if ((int) $lineRow['product_id'] === $productId) {
                        $lineIndex = $idx;
                        break;
                    }
                }

                if ($lineIndex === null) {
                    $currentLines[] = [
                        'product_id'      => $productId,
                        'quantity'        => '1.0000',
                        'count_confirmed' => true,
                        'serials'         => [$serialData],
                    ];
                } else {
                    $currentLines[$lineIndex]['serials'][] = $serialData;
                    $currentLines[$lineIndex]['quantity'] = bcadd((string) count($currentLines[$lineIndex]['serials']), '0', 4);
                    $currentLines[$lineIndex]['count_confirmed'] = true;
                }
            } elseif ($type === 'conversion') {
                $conversion = $candidate['conversion'];
                $productId = (int) $candidate['product']['id'];
                $isSerialRequired = (bool) ($candidate['product']['serial_number_required'] ?? false);

                if ($isSerialRequired) {
                    return [
                        'status'     => 'rejected',
                        'message'    => "Produk [{$candidate['product']['product_name']}] memerlukan nomor seri. Silakan scan nomor seri satuan.",
                        'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $lockedMovement, $canViewSystemStock),
                    ];
                }

                $factor = (string) ($conversion['conversion_factor'] ?? 1);

                $lineIndex = null;
                foreach ($currentLines as $idx => $lineRow) {
                    if ((int) $lineRow['product_id'] === $productId) {
                        $lineIndex = $idx;
                        break;
                    }
                }

                if ($lineIndex === null) {
                    $currentLines[] = [
                        'product_id'      => $productId,
                        'quantity'        => bcadd($factor, '0', 4),
                        'count_confirmed' => true,
                        'serials'         => [],
                    ];
                } else {
                    $newQty = bcadd((string) $currentLines[$lineIndex]['quantity'], $factor, 4);
                    $currentLines[$lineIndex]['quantity'] = $newQty;
                    $currentLines[$lineIndex]['count_confirmed'] = true;
                }
            } else { // product or product_exact
                $productId = (int) $candidate['product']['id'];
                $isSerialRequired = (bool) ($candidate['product']['serial_number_required'] ?? false);

                if ($isSerialRequired) {
                    return [
                        'status'     => 'rejected',
                        'message'    => "Produk [{$candidate['product']['product_name']}] memerlukan nomor seri. Silakan scan nomor seri produk secara langsung.",
                        'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $lockedMovement, $canViewSystemStock),
                    ];
                }

                $lineIndex = null;
                foreach ($currentLines as $idx => $lineRow) {
                    if ((int) $lineRow['product_id'] === $productId) {
                        $lineIndex = $idx;
                        break;
                    }
                }

                if ($lineIndex === null) {
                    $currentLines[] = [
                        'product_id'      => $productId,
                        'quantity'        => '1.0000',
                        'count_confirmed' => true,
                        'serials'         => [],
                    ];
                } else {
                    $newQty = bcadd((string) $currentLines[$lineIndex]['quantity'], '1', 4);
                    $currentLines[$lineIndex]['quantity'] = $newQty;
                    $currentLines[$lineIndex]['count_confirmed'] = true;
                }
            }

            $updatedMovement = $this->documentService->updateDraft(
                $lockedMovement,
                $expectedLockVersion,
                $currentLines,
                $userId
            );

            return [
                'status'     => 'resolved',
                'message'    => 'Scan applied successfully.',
                'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $updatedMovement, $canViewSystemStock),
            ];
        });
    }

    /**
     * Submit a return receipt draft for approval review.
     * Allows empty count if empty_count_confirmed is true.
     * Allows partial, excess, shortage, or unexpected product/serial observations.
     */
    public function submit(
        TransferMovement $movement,
        int $expectedLockVersion,
        int $userId
    ): TransferMovement {
        return DB::transaction(function () use ($movement, $expectedLockVersion, $userId) {
            $lockedTransfer = Transfer::where('id', $movement->transfer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedMovement = TransferMovement::where('id', $movement->id)
                ->with(['lines.serials', 'lines.product'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMovement->status !== TransferMovement::STATUS_DRAFT) {
                throw new RuntimeException("Only DRAFT movements can be submitted. Current status: [{$lockedMovement->status}].");
            }

            if ((int) $lockedMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
            }

            // 1. Revalidate exact source movement and return-batch lineage
            if (!$lockedMovement->source_movement_id || !$lockedMovement->return_batch_id) {
                throw new RuntimeException("Return receipt must specify source return dispatch movement and return batch ID.");
            }

            $sourceMovement = TransferMovement::where('id', $lockedMovement->source_movement_id)
                ->where('transfer_id', $lockedTransfer->id)
                ->where('type', TransferMovement::TYPE_RETURN_DISPATCH)
                ->where('return_batch_id', $lockedMovement->return_batch_id)
                ->lockForUpdate()
                ->first();

            if (!$sourceMovement || $sourceMovement->status !== TransferMovement::STATUS_APPROVED) {
                throw new RuntimeException("Source return dispatch is missing or is not in APPROVED status.");
            }

            // 2. Revalidate active reservations still exist for this source dispatch
            $hasActiveReservations = TransferReturnObligationReservation::where('transfer_movement_id', $sourceMovement->id)
                ->where('status', TransferReturnObligationReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->exists();

            if (!$hasActiveReservations) {
                throw new RuntimeException("Source return dispatch does not have active return obligation reservations.");
            }

            // 3. Revalidate live serial observations against custody claims
            $sourceMovementSerials = TransferMovementSerial::where('transfer_movement_id', $sourceMovement->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_serial_number_id');

            $allObservedSerialIds = [];
            foreach ($lockedMovement->lines as $line) {
                foreach ($line->serials as $movSerial) {
                    if ($movSerial->product_serial_number_id === null || (int) $movSerial->product_serial_number_id <= 0) {
                        throw new RuntimeException("Draf penerimaan retur memiliki nomor seri '{$movSerial->serial_number}' yang tidak terdaftar di database.");
                    }
                    $allObservedSerialIds[] = (int) $movSerial->product_serial_number_id;
                }
            }

            if (!empty($allObservedSerialIds)) {
                $liveSerials = ProductSerialNumber::whereIn('id', $allObservedSerialIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $activeClaims = TransferActiveSerialClaim::whereIn('product_serial_number_id', $allObservedSerialIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_serial_number_id');

                foreach ($lockedMovement->lines as $line) {
                    foreach ($line->serials as $movSerial) {
                        $liveSerial = $liveSerials->get($movSerial->product_serial_number_id);
                        if (!$liveSerial) {
                            throw new RuntimeException("Nomor seri {$movSerial->serial_number} tidak ditemukan di database.");
                        }

                        // 3.1 Revalidate normalized receipt serial text equals live and source serial text
                        $normReceipt = TransferMovementSerial::normalize((string) $movSerial->serial_number);
                        $normLive = ProductSerialNumber::normalize((string) $liveSerial->serial_number);
                        if ($normReceipt !== $normLive) {
                            throw new RuntimeException("Teks nomor seri draf penerimaan [{$normReceipt}] tidak cocok dengan nomor seri sistem [{$normLive}].");
                        }

                        if ((int) $liveSerial->product_id !== (int) $line->product_id) {
                            throw new RuntimeException("Nomor seri {$movSerial->serial_number} tidak cocok dengan produk baris.");
                        }

                        $srcMovSerial = $sourceMovementSerials->get($liveSerial->id);
                        if (!$srcMovSerial) {
                            throw new RuntimeException("Nomor seri {$movSerial->serial_number} tidak ditemukan pada manifes pengiriman retur.");
                        }

                        $normSource = TransferMovementSerial::normalize((string) $srcMovSerial->serial_number);
                        if ($normReceipt !== $normSource) {
                            throw new RuntimeException("Teks nomor seri draf penerimaan [{$normReceipt}] tidak cocok dengan pengiriman retur [{$normSource}].");
                        }

                        if ($srcMovSerial->transit_custody_status !== TransferMovementSerial::CUSTODY_IN_TRANSIT) {
                            throw new RuntimeException("Nomor seri {$movSerial->serial_number} tidak dalam status transit aktif pada pengiriman retur.");
                        }

                        // 3.2 Revalidate claim ownership and exact source serial row identity
                        $claim = $activeClaims->get($liveSerial->id);
                        if (!$claim || (int) $claim->transfer_movement_id !== (int) $sourceMovement->id) {
                            throw new RuntimeException("Nomor seri {$movSerial->serial_number} tidak memiliki klaim transit aktif pada pengiriman retur ini.");
                        }

                        if ((int) $claim->transfer_movement_serial_id !== (int) $srcMovSerial->id) {
                            throw new RuntimeException("Klaim transit nomor seri {$movSerial->serial_number} tidak cocok dengan rekaman pengiriman retur.");
                        }

                        // 3.3 Revalidate live location is return dispatch origin
                        if ($liveSerial->location_id === null || (int) $liveSerial->location_id !== (int) $lockedMovement->origin_location_id) {
                            throw new RuntimeException("Lokasi nomor seri {$movSerial->serial_number} [{$liveSerial->location_id}] tidak cocok dengan lokasi pengiriman {$lockedMovement->origin_location_id}.");
                        }

                        // 3.4 Revalidate live condition matches transfer condition
                        $isBroken = (bool) ($liveSerial->is_broken ?? false);
                        if ($lockedMovement->stock_condition === TransferMovement::CONDITION_GOOD && $isBroken) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} berstatus barang rusak padahal transfer berstatus GOOD.");
                        }
                        if ($lockedMovement->stock_condition === TransferMovement::CONDITION_BREAKAGE && !$isBroken) {
                            throw new RuntimeException("Nomor seri {$liveSerial->serial_number} berstatus barang baik padahal transfer berstatus BREAKAGE.");
                        }

                        // 3.5 Revalidate live tax identity matches source movement serial snapshot
                        if ((int) ($liveSerial->tax_id ?? 0) !== (int) ($srcMovSerial->tax_id ?? 0)) {
                            throw new RuntimeException("Identitas pajak nomor seri {$liveSerial->serial_number} telah berubah sejak pengiriman retur.");
                        }
                    }
                }
            }

            // Check if draft is completely empty without confirmation
            $hasPositiveObservations = $lockedMovement->lines->some(fn ($l) => (float) $l->quantity > 0);
            if (!$hasPositiveObservations && !$lockedMovement->empty_count_confirmed) {
                throw new RuntimeException("Draf penerimaan kosong harus dikonfirmasi bahwa tidak ada barang yang diterima sebelum diajukan.");
            }

            return $this->documentService->submitDraft(
                $lockedMovement,
                $expectedLockVersion,
                $userId
            );
        });
    }

    /**
     * Start an empty correction draft superseding a REJECTED return receipt attempt in the same lineage.
     */
    public function startCorrection(
        TransferMovement $rejectedMovement,
        int $userId
    ): TransferMovement {
        return DB::transaction(function () use ($rejectedMovement, $userId) {
            $lockedMovement = TransferMovement::where('id', $rejectedMovement->id)->lockForUpdate()->firstOrFail();

            if ($lockedMovement->type !== TransferMovement::TYPE_RETURN_RECEIPT) {
                throw new RuntimeException("Only RETURN_RECEIPT movements can be corrected with this service.");
            }

            // Corrections start completely empty with no copied lines or serials
            return $this->documentService->startCorrection(
                $lockedMovement,
                $userId,
                []
            );
        });
    }

    /**
     * Helper to extract lines data for documentService calls.
     */
    private function extractLinesDataFromMovement(TransferMovement $movement): array
    {
        $linesData = [];
        foreach ($movement->lines as $line) {
            $serials = [];
            foreach ($line->serials as $ser) {
                $serials[] = [
                    'id'                       => $ser->product_serial_number_id,
                    'product_serial_number_id' => $ser->product_serial_number_id,
                    'serial_number'            => $ser->serial_number,
                    'stock_condition'          => $ser->stock_condition,
                    'tax_id'                   => $ser->tax_id,
                ];
            }

            $linesData[] = [
                'product_id'      => (int) $line->product_id,
                'quantity'        => (string) $line->quantity,
                'count_confirmed' => (bool) $line->count_confirmed,
                'serials'         => $serials,
            ];
        }

        return $linesData;
    }
}

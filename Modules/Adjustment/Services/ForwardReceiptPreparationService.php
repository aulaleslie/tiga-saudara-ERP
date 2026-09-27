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
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Tax;
use RuntimeException;

class ForwardReceiptPreparationService
{
    public function __construct(
        private TransferMovementDocumentService $documentService,
        private TransferScanResolverService $scanResolverService,
        private ForwardReceiptProjectionService $projectionService,
    ) {
    }

    /**
     * Get or create the single open forward receipt draft for a DISPATCHED workflow version 2 transfer.
     * Seeds NO lines (completely empty blind start).
     */
    public function getOrCreateDraft(Transfer $transfer, int $userId): TransferMovement
    {
        return DB::transaction(function () use ($transfer, $userId) {
            $lockedTransfer = Transfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedTransfer->workflow_version !== 2) {
                throw new RuntimeException("Forward receipt preparation is only available for workflow version 2 transfers.");
            }

            app(TransferWorkflowEligibilityService::class)->validateV2Eligibility($lockedTransfer);

            if ($lockedTransfer->status !== Transfer::STATUS_DISPATCHED) {
                throw new RuntimeException("Transfer must be in DISPATCHED status for receipt preparation.");
            }

            // Find the approved forward dispatch
            $approvedDispatch = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', TransferMovement::TYPE_FORWARD_DISPATCH)
                ->where('status', TransferMovement::STATUS_APPROVED)
                ->lockForUpdate()
                ->first();

            if (!$approvedDispatch) {
                throw new RuntimeException("An approved forward dispatch movement is required for receipt preparation.");
            }

            // Check if there is an existing open attempt (DRAFT or PENDING)
            $existingMovement = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', TransferMovement::TYPE_FORWARD_RECEIPT)
                ->whereIn('status', [TransferMovement::STATUS_DRAFT, TransferMovement::STATUS_PENDING])
                ->lockForUpdate()
                ->first();

            if ($existingMovement) {
                if ((int) $existingMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                    throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
                }

                return $existingMovement->load(['lines.serials', 'lines.product', 'histories']);
            }

            // Check if an approved movement already exists
            $approvedReceipt = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', TransferMovement::TYPE_FORWARD_RECEIPT)
                ->where('status', TransferMovement::STATUS_APPROVED)
                ->lockForUpdate()
                ->first();

            if ($approvedReceipt) {
                throw new RuntimeException("Forward receipt has already been APPROVED for this transfer.");
            }

            // Create draft with NO seeded lines (completely blind)
            return $this->documentService->createDraft(
                $lockedTransfer,
                TransferMovement::TYPE_FORWARD_RECEIPT,
                $userId,
                [],
                $approvedDispatch->id
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

            if ($lockedMovement->type !== TransferMovement::TYPE_FORWARD_RECEIPT) {
                throw new RuntimeException("Empty confirmation only applies to FORWARD_RECEIPT movements.");
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

            $destSettingId = (int) ($lockedTransfer->destinationLocation?->setting_id ?? $lockedMovement->destinationLocation?->setting_id);
            $originSettingId = (int) ($lockedTransfer->originLocation?->setting_id ?? $lockedMovement->originLocation?->setting_id);
            $sourceProductIds = $lockedMovement->sourceMovement ? $lockedMovement->sourceMovement->lines->pluck('product_id') : collect();

            $product = Product::query()
                ->active()
                ->where('stock_managed', true)
                ->where('id', $productId)
                ->where(function ($q) use ($destSettingId, $originSettingId, $sourceProductIds) {
                    $q->where('setting_id', $destSettingId)
                        ->orWhere('setting_id', $originSettingId)
                        ->orWhereIn('id', $sourceProductIds);
                })
                ->first();

            if (!$product) {
                throw new RuntimeException("Produk tidak valid atau tidak terdaftar pada unit bisnis tujuan.");
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
     * Scan a barcode, conversion barcode, or serial number and apply to the receipt draft.
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

            if ((int) $lockedMovement->transfer_id !== (int) $lockedTransfer->id) {
                throw new RuntimeException("Movement transfer mismatch.");
            }

            $isBrokenMode = $lockedMovement->stock_condition === TransferMovement::CONDITION_BREAKAGE;

            // Destination side scan resolution: location is destination_location_id, allowZeroStock is true (destination might have 0 stock)
            $destinationLocationId = (int) $lockedMovement->destination_location_id;

            $scanResult = $this->scanResolverService->resolve(
                $settingId,
                $query,
                $destinationLocationId,
                $isBrokenMode,
                true // allowZeroStock for destination physical observations
            );

            // Cross-business fallback: if not found in destination setting, check origin setting catalog
            $originSettingId = (int) ($lockedTransfer->originLocation?->setting_id ?? $lockedMovement->originLocation?->setting_id);
            $originLocationId = (int) ($lockedTransfer->origin_location_id ?? $lockedMovement->origin_location_id);
            if (($scanResult['status'] === 'not_found' || $scanResult['status'] === 'none') && $originSettingId !== $settingId) {
                $originScan = $this->scanResolverService->resolve(
                    $originSettingId,
                    $query,
                    $originLocationId,
                    $isBrokenMode,
                    true
                );
                if ($originScan['status'] === 'resolved') {
                    $scanResult = $originScan;
                }
            }

            $currentLines = $this->extractLinesDataFromMovement($lockedMovement);

            if ($scanResult['status'] === 'rejected' || $scanResult['status'] === 'not_found' || $scanResult['status'] === 'none') {
                // Check if query is a serial number for destination or origin setting product or dispatched serial
                $normalizedSerial = ProductSerialNumber::normalize($query);
                
                // Search live serial across destination or origin setting
                $liveSerial = ProductSerialNumber::where('serial_number', $normalizedSerial)
                    ->whereHas('product', fn ($q) => $q->active()->whereIn('setting_id', [$settingId, $originSettingId])->where('stock_managed', true))
                    ->with('product')
                    ->first();

                // If not found in setting directly, check if it was part of source dispatch
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
                    // Valid serial observation
                    $scanResult = [
                        'status' => 'resolved',
                        'type'   => 'serial_exact',
                        'candidate' => [
                            'type' => 'serial',
                            'description' => "Nomor Seri: {$normalizedSerial} - {$liveSerial->product->product_name}",
                            'serial' => [
                                'id'                       => (int) $liveSerial->id,
                                'product_serial_number_id' => (int) $liveSerial->id,
                                'serial_number'            => $normalizedSerial,
                                'product_id'               => (int) $liveSerial->product_id,
                                'tax_id'                   => $liveSerial->tax_id,
                            ],
                            'product' => [
                                'id'                     => (int) $liveSerial->product->id,
                                'product_name'           => (string) $liveSerial->product->product_name,
                                'product_code'           => (string) ($liveSerial->product->product_code ?? ''),
                                'serial_number_required' => true,
                            ],
                        ],
                    ];
                } else {
                    return [
                        'status'     => $scanResult['status'] ?? 'not_found',
                        'message'    => $scanResult['message'] ?? "Barcode atau nomor seri '{$query}' tidak ditemukan.",
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
     * Submit a forward receipt draft for approval review.
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

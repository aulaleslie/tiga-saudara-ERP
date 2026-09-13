<?php

namespace Modules\Adjustment\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Tax;
use RuntimeException;

class ForwardDispatchPreparationService
{
    public function __construct(
        private TransferMovementDocumentService $documentService,
        private TransferScanResolverService $scanResolverService,
        private ForwardDispatchProjectionService $projectionService,
    ) {
    }

    /**
     * Get or create the single open forward dispatch draft for an approved workflow version 2 transfer.
     */
    public function getOrCreateDraft(Transfer $transfer, int $userId): TransferMovement
    {
        return DB::transaction(function () use ($transfer, $userId) {
            $lockedTransfer = Transfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedTransfer->workflow_version !== 2) {
                throw new RuntimeException("Forward dispatch preparation is only available for workflow version 2 transfers.");
            }

            if ($lockedTransfer->status !== Transfer::STATUS_APPROVED) {
                throw new RuntimeException("Transfer must be in APPROVED status for dispatch preparation.");
            }

            // Check if there is an existing open attempt (DRAFT or PENDING)
            $existingMovement = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', TransferMovement::TYPE_FORWARD_DISPATCH)
                ->whereIn('status', [TransferMovement::STATUS_DRAFT, TransferMovement::STATUS_PENDING])
                ->lockForUpdate()
                ->first();

            if ($existingMovement) {
                // If transfer revision has advanced, reject
                if ((int) $existingMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                    throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
                }

                return $existingMovement->load(['lines.serials', 'lines.product', 'histories']);
            }

            // Check if an approved movement already exists
            $approvedMovement = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', TransferMovement::TYPE_FORWARD_DISPATCH)
                ->where('status', TransferMovement::STATUS_APPROVED)
                ->lockForUpdate()
                ->first();

            if ($approvedMovement) {
                throw new RuntimeException("Forward dispatch has already been APPROVED for this transfer.");
            }

            // Seed lines from approved transfer products, setting count_confirmed = false and quantity = 0.0000
            $lockedTransfer->loadMissing('products.product');
            $linesData = [];

            foreach ($lockedTransfer->products as $tp) {
                $linesData[] = [
                    'product_id'      => $tp->product_id,
                    'quantity'        => '0.0000',
                    'count_confirmed' => false,
                    'serials'         => [],
                ];
            }

            return $this->documentService->createDraft(
                $lockedTransfer,
                TransferMovement::TYPE_FORWARD_DISPATCH,
                $userId,
                $linesData
            );
        });
    }

    /**
     * Set explicit quantity and confirmation state on a movement line.
     * When quantity > 0, count_confirmed automatically becomes true.
     * When quantity == 0, count_confirmed must be explicitly specified (defaults to true if explicit zero action).
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
                ->with(['lines.serials'])
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
                ->with('originLocation')
                ->firstOrFail();

            $originSettingId = (int) ($lockedTransfer->originLocation?->setting_id ?? $lockedMovement->originLocation?->setting_id);

            $product = Product::query()
                ->active()
                ->where('setting_id', $originSettingId)
                ->where('stock_managed', true)
                ->where('id', $productId)
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
                // Unexpected product added
                if ($qtyFloat > 0 || $lineConfirmed) {
                    $currentLines[] = [
                        'product_id'      => $productId,
                        'quantity'        => $qtyStr,
                        'count_confirmed' => $lineConfirmed,
                        'serials'         => [],
                    ];
                }
            }

            return $this->documentService->updateDraft(
                $lockedMovement,
                $expectedLockVersion,
                $currentLines,
                $userId
            );
        });
    }

    /**
     * Scan a barcode, conversion barcode, or serial number and apply to the movement draft.
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
            $lockedTransfer = Transfer::where('id', $movement->transfer_id)->lockForUpdate()->firstOrFail();
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

            $scanResult = $this->scanResolverService->resolve(
                $settingId,
                $query,
                $lockedMovement->origin_location_id,
                $isBrokenMode,
                true // allowZeroStock for physical forward dispatch preparation observations
            );

            if ($scanResult['status'] === 'rejected') {
                return [
                    'status'     => 'rejected',
                    'message'    => $scanResult['message'] ?? 'Scan rejected.',
                    'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $lockedMovement, $canViewSystemStock),
                ];
            }

            if ($scanResult['status'] === 'not_found' || $scanResult['status'] === 'none') {
                return [
                    'status'     => 'not_found',
                    'message'    => $scanResult['message'] ?? 'Not found.',
                    'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $lockedMovement, $canViewSystemStock),
                ];
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

            $currentLines = $this->extractLinesDataFromMovement($lockedMovement);

            if ($type === 'serial' || $type === 'serial_exact') {
                $serialData = $candidate['serial'];
                $productData = $candidate['product'];
                $productId = (int) $productData['id'];
                $normalizedSerial = TransferMovementSerial::normalize($serialData['serial_number']);

                // Duplicate serial check across all lines
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
     * Submit a forward dispatch draft after validating all approved products are confirmed.
     */
    public function submit(
        TransferMovement $movement,
        int $expectedLockVersion,
        int $userId
    ): TransferMovement {
        return DB::transaction(function () use ($movement, $expectedLockVersion, $userId) {
            $lockedTransfer = Transfer::where('id', $movement->transfer_id)
                ->with('products')
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

            // Every approved product in the transfer MUST be confirmed in movement lines
            $confirmedProductIds = $lockedMovement->lines
                ->filter(fn ($line) => (bool) $line->count_confirmed)
                ->pluck('product_id')
                ->all();

            foreach ($lockedTransfer->products as $approvedProd) {
                if (!in_array($approvedProd->product_id, $confirmedProductIds, true)) {
                    throw new RuntimeException("Every approved product must have its count explicitly confirmed before submission.");
                }
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

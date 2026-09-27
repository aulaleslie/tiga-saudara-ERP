<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementReturnObligation;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use RuntimeException;

class ReturnDispatchPreparationService
{
    public function __construct(
        private TransferMovementDocumentService $documentService,
        private TransferScanResolverService $scanResolverService,
        private ReturnDispatchProjectionService $projectionService,
    ) {
    }

    /**
     * Create a brand-new independent return-dispatch batch lineage, or resume an existing
     * open (DRAFT/PENDING) batch identified by its return_batch_id.
     */
    public function getOrCreateBatch(Transfer $transfer, int $userId, ?string $returnBatchId = null): TransferMovement
    {
        return DB::transaction(function () use ($transfer, $userId, $returnBatchId) {
            $lockedTransfer = Transfer::where('id', $transfer->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedTransfer->workflow_version !== 2) {
                throw new RuntimeException("Return dispatch preparation is only available for workflow version 2 transfers.");
            }

            app(TransferWorkflowEligibilityService::class)->validateV2Eligibility($lockedTransfer);

            if (!in_array($lockedTransfer->status, [Transfer::STATUS_AWAITING_RETURN, Transfer::STATUS_RETURN_DISPATCHED], true)) {
                throw new RuntimeException("Transfer must be AWAITING_RETURN (or have an active return batch already) for return dispatch preparation.");
            }

            $policy = TransferRoutePolicy::where('transfer_id', $lockedTransfer->id)
                ->orderByDesc('transfer_revision')
                ->first();

            if (!$policy || !$policy->mandatory_return) {
                throw new RuntimeException("This transfer's committed route policy does not require a return dispatch.");
            }

            if ($returnBatchId) {
                $existingMovement = TransferMovement::where('transfer_id', $lockedTransfer->id)
                    ->where('type', TransferMovement::TYPE_RETURN_DISPATCH)
                    ->where('return_batch_id', $returnBatchId)
                    ->whereIn('status', [TransferMovement::STATUS_DRAFT, TransferMovement::STATUS_PENDING])
                    ->lockForUpdate()
                    ->first();

                if ($existingMovement) {
                    if ((int) $existingMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                        throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
                    }

                    return $existingMovement->load(['lines.serials', 'lines.product', 'histories']);
                }

                throw new RuntimeException("No open return-dispatch batch found for batch ID [{$returnBatchId}].");
            }

            // No batch ID supplied: start a fresh independent batch lineage with no seeded lines.
            // Product identities are added on demand via scan/setLineQuantity for any positive subset.
            return $this->documentService->createDraft(
                $lockedTransfer,
                TransferMovement::TYPE_RETURN_DISPATCH,
                $userId,
                [],
                $this->resolveApprovedReceiptMovementId($lockedTransfer),
                null,
                (string) Str::uuid()
            );
        });
    }

    /**
     * Set explicit quantity and confirmation state on a return-dispatch batch line.
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
                throw new RuntimeException("Only DRAFT batches can be edited.");
            }

            $currentLines = $this->extractLinesDataFromMovement($lockedMovement);

            $qtyStr = bcadd((string) $quantity, '0', 4);
            $qtyFloat = (float) $qtyStr;
            if ($qtyFloat < 0) {
                throw new RuntimeException("Line quantity cannot be negative.");
            }

            $product = Product::query()->active()->where('id', $productId)->first();
            if (!$product) {
                throw new RuntimeException("Produk tidak valid.");
            }

            if ($product->serial_number_required && $qtyFloat > 0) {
                throw new RuntimeException("Kuantitas produk dengan nomor seri tidak dapat diubah secara manual. Silakan scan nomor seri produk.");
            }

            $this->assertProductHasOutstandingObligation($lockedMovement, $productId);

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

            return $this->documentService->updateDraft(
                $lockedMovement,
                $expectedLockVersion,
                $currentLines,
                $userId
            );
        });
    }

    /**
     * Scan a barcode, conversion barcode, or serial number at the destination (return origin)
     * and apply it to the batch draft. Zero recorded stock may be observed pre-approval.
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
                ->with('originLocation')
                ->lockForUpdate()
                ->firstOrFail();
            $lockedMovement = TransferMovement::where('id', $movement->id)
                ->with(['lines.serials'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMovement->status !== TransferMovement::STATUS_DRAFT) {
                throw new RuntimeException("Only DRAFT batches can be edited.");
            }

            $isBrokenMode = $lockedMovement->stock_condition === TransferMovement::CONDITION_BREAKAGE;

            $scanResult = $this->scanResolverService->resolve(
                $settingId,
                $query,
                $lockedMovement->origin_location_id,
                $isBrokenMode,
                true
            );

            // Cross-business barcode/conversion fallback: the obligated canonical product may be
            // catalogued under the transfer's original origin business rather than the current
            // (destination/return-origin) tenant, so a destination-scoped miss is retried against
            // the origin setting's catalog before being reported as not found. Barcode/conversion
            // matching ignores the location argument when allowZeroStock is true (always the case
            // here), so passing the transfer's real origin location only satisfies the resolver's
            // tenant-ownership assertion and has no effect on which products can be found.
            $canonicalOriginSettingId = (int) ($lockedTransfer->originLocation?->setting_id ?? 0);
            $canonicalOriginLocationId = (int) ($lockedTransfer->origin_location_id ?? 0);
            if (
                in_array($scanResult['status'], ['not_found', 'none'], true)
                && $canonicalOriginSettingId > 0
                && $canonicalOriginSettingId !== $settingId
            ) {
                $originScan = $this->scanResolverService->resolve(
                    $canonicalOriginSettingId,
                    $query,
                    $canonicalOriginLocationId,
                    $isBrokenMode,
                    true
                );

                if ($originScan['status'] === 'resolved') {
                    $scanResult = $originScan;
                }
            }

            if (in_array($scanResult['status'], ['rejected', 'not_found', 'none'], true)) {
                // Cross-business serial fallback: the resolver's serial lookup filters by location,
                // but a substitute serial physically resides at the destination while its product may
                // be catalogued only under the origin business — a combination the resolver's single
                // origin-location-ownership assertion cannot express in one call. Look the serial up
                // directly by normalized text across both settings' catalogs; full destination-location,
                // condition, tax, availability, and custody eligibility is authoritatively revalidated
                // at submission and approval regardless of how the batch line was seeded here.
                $normalizedSerial = \Modules\Product\Entities\ProductSerialNumber::normalize($query);
                $liveSerial = \Modules\Product\Entities\ProductSerialNumber::where('serial_number', $normalizedSerial)
                    ->whereHas('product', fn ($q) => $q->active()
                        ->whereIn('setting_id', array_unique([$settingId, $canonicalOriginSettingId]))
                        ->where('stock_managed', true))
                    ->with('product')
                    ->first();

                if ($liveSerial && $liveSerial->product) {
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
                            'product' => [
                                'id'                     => (int) $liveSerial->product->id,
                                'product_name'           => (string) $liveSerial->product->product_name,
                                'product_code'           => (string) ($liveSerial->product->product_code ?? ''),
                                'serial_number_required' => true,
                            ],
                        ],
                    ];
                }
            }

            if (in_array($scanResult['status'], ['rejected', 'not_found', 'none'], true)) {
                return [
                    'status'     => $scanResult['status'] === 'none' ? 'not_found' : $scanResult['status'],
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

            try {
                if ($type === 'serial' || $type === 'serial_exact') {
                    $serialData = $candidate['serial'];
                    $productData = $candidate['product'];
                    $productId = (int) $productData['id'];

                    $this->assertProductHasOutstandingObligation($lockedMovement, $productId);

                    $normalizedSerial = TransferMovementSerial::normalize($serialData['serial_number']);

                    foreach ($currentLines as $lineRow) {
                        foreach ($lineRow['serials'] as $s) {
                            $sNum = is_array($s) ? ($s['serial_number'] ?? '') : (string) $s;
                            if (TransferMovementSerial::normalize($sNum) === $normalizedSerial) {
                                throw new RuntimeException("Nomor seri [{$normalizedSerial}] sudah dipilih.");
                            }
                        }
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
                            'serials'         => [$serialData],
                        ];
                    } else {
                        $currentLines[$lineIndex]['serials'][] = $serialData;
                        $currentLines[$lineIndex]['quantity'] = bcadd((string) count($currentLines[$lineIndex]['serials']), '0', 4);
                        $currentLines[$lineIndex]['count_confirmed'] = true;
                    }
                } elseif ($type === 'conversion') {
                    $productId = (int) $candidate['product']['id'];
                    $isSerialRequired = (bool) ($candidate['product']['serial_number_required'] ?? false);

                    if ($isSerialRequired) {
                        throw new RuntimeException("Produk [{$candidate['product']['product_name']}] memerlukan nomor seri. Silakan scan nomor seri satuan.");
                    }

                    $this->assertProductHasOutstandingObligation($lockedMovement, $productId);

                    $factor = (string) ($candidate['conversion']['conversion_factor'] ?? 1);

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
                        $currentLines[$lineIndex]['quantity'] = bcadd((string) $currentLines[$lineIndex]['quantity'], $factor, 4);
                        $currentLines[$lineIndex]['count_confirmed'] = true;
                    }
                } else { // product or product_exact
                    $productId = (int) $candidate['product']['id'];
                    $isSerialRequired = (bool) ($candidate['product']['serial_number_required'] ?? false);

                    if ($isSerialRequired) {
                        throw new RuntimeException("Produk [{$candidate['product']['product_name']}] memerlukan nomor seri. Silakan scan nomor seri produk secara langsung.");
                    }

                    $this->assertProductHasOutstandingObligation($lockedMovement, $productId);

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
                        $currentLines[$lineIndex]['quantity'] = bcadd((string) $currentLines[$lineIndex]['quantity'], '1', 4);
                        $currentLines[$lineIndex]['count_confirmed'] = true;
                    }
                }
            } catch (RuntimeException $e) {
                return [
                    'status'     => 'rejected',
                    'message'    => $this->neutralizeScanRejection($e, $canViewSystemStock),
                    'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $lockedMovement, $canViewSystemStock),
                ];
            }

            try {
                // The cross-business serial fallback above resolves purely by normalized serial
                // text with no location/eligibility filtering, so a claimed, relocated, or
                // otherwise ineligible serial is only caught here by updateDraft's authoritative
                // live-serial revalidation. That revalidation error is a raw domain message (exact
                // location, condition, tax classification, or custody conflict) and MUST be
                // neutralized for blind users exactly like every other protected comparison detail —
                // a blind operator must not be able to distinguish "wrong location" from "wrong
                // condition" from "already in transit" by reading scan responses.
                $updatedMovement = $this->documentService->updateDraft(
                    $lockedMovement,
                    $expectedLockVersion,
                    $currentLines,
                    $userId
                );
            } catch (RuntimeException $e) {
                return [
                    'status'     => 'rejected',
                    'message'    => $this->neutralizeScanRejection($e, $canViewSystemStock),
                    'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $lockedMovement, $canViewSystemStock),
                ];
            }

            return [
                'status'     => 'resolved',
                'message'    => 'Scan applied successfully.',
                'projection' => $this->projectionService->getPreparationProjection($lockedTransfer, $updatedMovement, $canViewSystemStock),
            ];
        });
    }

    /**
     * Submit a return-dispatch batch draft. Rejects empty/all-zero batches and revalidates
     * unreserved obligation capacity for early stale-draft feedback (advisory only; approval
     * is authoritative and revalidates capacity again under lock).
     */
    public function submit(
        TransferMovement $movement,
        int $expectedLockVersion,
        int $userId
    ): TransferMovement {
        return DB::transaction(function () use ($movement, $expectedLockVersion, $userId) {
            $lockedTransfer = Transfer::where('id', $movement->transfer_id)->lockForUpdate()->firstOrFail();
            $lockedMovement = TransferMovement::where('id', $movement->id)
                ->with(['lines.serials', 'lines.product'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMovement->status !== TransferMovement::STATUS_DRAFT) {
                throw new RuntimeException("Only DRAFT batches can be submitted. Current status: [{$lockedMovement->status}].");
            }

            $confirmedLines = $lockedMovement->lines->filter(
                fn ($line) => (bool) $line->count_confirmed && bccomp((string) $line->quantity, '0', 4) > 0
            );

            if ($confirmedLines->isEmpty()) {
                throw new RuntimeException("Batch retur tidak dapat diajukan dalam keadaan kosong. Pilih setidaknya satu produk dengan kuantitas positif.");
            }

            // Advisory capacity check for early stale-draft feedback (not authoritative)
            foreach ($confirmedLines as $line) {
                $obligation = $this->resolveObligation($lockedMovement, (int) $line->product_id);
                $obligation->loadMissing('activeReservations');

                if (bccomp((string) $line->quantity, $obligation->availableCapacity(), 4) > 0) {
                    throw new RuntimeException("Kuantitas retur untuk produk ID {$line->product_id} melebihi kapasitas kewajiban yang tersedia saat ini.");
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
     * Resolve the exact outstanding obligation for a product/condition on this batch's transfer,
     * throwing if the product is not an obligated product for this transfer.
     */
    public function resolveObligation(TransferMovement $movement, int $productId): TransferMovementReturnObligation
    {
        $obligation = TransferMovementReturnObligation::where('transfer_id', $movement->transfer_id)
            ->where('product_id', $productId)
            ->where('stock_condition', $movement->stock_condition)
            ->first();

        if (!$obligation) {
            throw new RuntimeException("Produk ID {$productId} bukan merupakan kewajiban retur untuk transfer ini.");
        }

        return $obligation;
    }

    private function assertProductHasOutstandingObligation(TransferMovement $movement, int $productId): void
    {
        $obligation = $this->resolveObligation($movement, $productId);

        if (bccomp($obligation->outstandingQuantity(), '0', 4) <= 0) {
            throw new RuntimeException("Produk ID {$productId} tidak memiliki kewajiban retur yang tersisa.");
        }
    }

    /**
     * Neutralize a scan-rejection exception message for blind users. Every failure surfaced from
     * this batch-building code path — line-building guards, obligation checks, and (critically)
     * updateDraft's authoritative live-serial revalidation — can reveal protected comparison
     * details (exact location, condition, tax classification, active custody, remaining capacity)
     * through its raw message text. A blind user without stockTransfers.view-system-stock must
     * receive one fixed, non-quantitative message regardless of which check failed; a privileged
     * user still receives the precise domain error for operational troubleshooting.
     */
    private function neutralizeScanRejection(RuntimeException $e, bool $canViewSystemStock): string
    {
        if ($canViewSystemStock) {
            return $e->getMessage();
        }

        return 'Item yang dipindai tidak dapat digunakan untuk batch retur ini.';
    }

    private function resolveApprovedReceiptMovementId(Transfer $transfer): int
    {
        $receipt = TransferMovement::where('transfer_id', $transfer->id)
            ->where('type', TransferMovement::TYPE_FORWARD_RECEIPT)
            ->where('status', TransferMovement::STATUS_APPROVED)
            ->first();

        if (!$receipt) {
            throw new RuntimeException("Cannot create return dispatch without an approved forward receipt.");
        }

        return $receipt->id;
    }

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

<?php

namespace Modules\Adjustment\Services;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementHistory;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Tax;
use RuntimeException;

/**
 * Stock Transfer Movement Foundation Domain Service.
 *
 * This service is an authorization-agnostic domain aggregate service responsible
 * for enforcing movement lifecycle transitions, optimistic concurrency, relational
 * integrity, authoritative live serial verification, and immutable revision snapshots.
 *
 * In accordance with Delivery 4 (foundation), production HTTP routes and UI entry points
 * remain intentionally unexposed. Future production controllers/Livewire actions MUST
 * wrap these domain methods with explicit route guards checking:
 *  - Preparation vs approval permissions (e.g. stockTransfers.dispatch.create / approval)
 *  - Active tenant/business setting ownership
 *  - Operational location user assignments (origin vs destination side)
 */
class TransferMovementDocumentService
{
    /**
     * Create a new draft movement attempt under locks.
     *
     * @param Transfer $transfer
     * @param string $type
     * @param int $userId
     * @param array $linesData Array of ['product_id' => int, 'quantity' => float|string|int, 'serials' => array]
     * @param int|null $sourceMovementId
     * @param string|null $idempotencyKey
     * @return TransferMovement
     */
    public function createDraft(
        Transfer $transfer,
        string $type,
        int $userId,
        array $linesData = [],
        ?int $sourceMovementId = null,
        ?string $idempotencyKey = null,
        ?string $returnBatchId = null
    ): TransferMovement {
        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : null;
        $isBatchLineage = in_array($type, [TransferMovement::TYPE_RETURN_DISPATCH, TransferMovement::TYPE_RETURN_RECEIPT], true);

        if ($isBatchLineage) {
            if (!$returnBatchId) {
                if ($type === TransferMovement::TYPE_RETURN_DISPATCH) {
                    $returnBatchId = (string) \Illuminate\Support\Str::uuid();
                } else {
                    throw new RuntimeException("return_batch_id is required for RETURN_RECEIPT movement creation.");
                }
            }
        } else {
            // Non-batch types keep exactly one lineage per (transfer_id, type); return_batch_id must
            // still be non-null so the (transfer_id, type, return_batch_id, revision) unique index
            // enforces that invariant at the database level (a nullable component would not).
            $returnBatchId = TransferMovement::SINGLETON_LINEAGE;
        }

        return DB::transaction(function () use ($transfer, $type, $userId, $linesData, $sourceMovementId, $idempotencyKey, $returnBatchId, $isBatchLineage) {
            // Lock parent transfer and validate eligible approved transfer state
            $lockedTransfer = $this->lockAndValidateTransferForMovement($transfer->id);

            if ($idempotencyKey) {
                $existingHistory = TransferMovementHistory::where('action', TransferMovementHistory::ACTION_CREATED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->whereHas('movement', function ($query) use ($lockedTransfer, $type, $returnBatchId, $isBatchLineage) {
                        $query->where('transfer_id', $lockedTransfer->id)
                            ->where('type', $type);
                        if ($isBatchLineage) {
                            $query->where('return_batch_id', $returnBatchId);
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory) {
                    return $existingHistory->movement()->with(['lines.serials', 'histories'])->first();
                }
            }

            $this->validateMovementTypeEligibility($lockedTransfer, $type);

            // Validate source movement reference if applicable
            $sourceMovement = null;
            if ($sourceMovementId !== null) {
                $sourceMovement = $this->validateAndResolveSourceMovement($lockedTransfer, $type, $sourceMovementId);
                if ($type === TransferMovement::TYPE_RETURN_RECEIPT) {
                    if ($sourceMovement->return_batch_id !== $returnBatchId) {
                        throw new RuntimeException("RETURN_RECEIPT return_batch_id [{$returnBatchId}] does not match source dispatch return_batch_id [{$sourceMovement->return_batch_id}].");
                    }
                }
            } elseif (in_array($type, [
                TransferMovement::TYPE_FORWARD_RECEIPT,
                TransferMovement::TYPE_RETURN_DISPATCH,
                TransferMovement::TYPE_RETURN_RECEIPT,
            ], true)) {
                // Receipts and return dispatches MUST reference an exact approved source movement
                throw new RuntimeException("Source movement reference is required for movement type [{$type}].");
            }

            // One open attempt rule, scoped per return-batch lineage for RETURN_DISPATCH and RETURN_RECEIPT
            $openAttemptQuery = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', $type)
                ->whereIn('status', [TransferMovement::STATUS_DRAFT, TransferMovement::STATUS_PENDING]);

            if ($isBatchLineage) {
                $openAttemptQuery->where('return_batch_id', $returnBatchId);
            }

            $hasOpenAttempt = $openAttemptQuery->lockForUpdate()->exists();

            if ($hasOpenAttempt) {
                throw new RuntimeException("An open attempt (DRAFT or PENDING) already exists for this transfer and type [{$type}].");
            }

            // One approved attempt rule, scoped per return-batch lineage for RETURN_DISPATCH and RETURN_RECEIPT
            $approvedAttemptQuery = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', $type)
                ->where('status', TransferMovement::STATUS_APPROVED);

            if ($isBatchLineage) {
                $approvedAttemptQuery->where('return_batch_id', $returnBatchId);
            }

            $hasApprovedAttempt = $approvedAttemptQuery->lockForUpdate()->exists();

            if ($hasApprovedAttempt) {
                throw new RuntimeException("An approved attempt already exists for this transfer and type [{$type}].");
            }

            // Determine operational locations
            [$originLocationId, $destinationLocationId] = $this->resolveOperationalLocations($lockedTransfer, $type);

            // Determine revision: max revision + 1, scoped per lineage for RETURN_DISPATCH and RETURN_RECEIPT
            $revisionQuery = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', $type);

            if ($isBatchLineage) {
                $revisionQuery->where('return_batch_id', $returnBatchId);
            }

            $latestRevision = $revisionQuery->max('revision') ?? 0;

            $nextRevision = $latestRevision + 1;

            $movement = TransferMovement::create([
                'transfer_id'             => $lockedTransfer->id,
                'type'                    => $type,
                'revision'                => $nextRevision,
                'lock_version'            => 1,
                'transfer_revision'       => $lockedTransfer->revision,
                'status'                  => TransferMovement::STATUS_DRAFT,
                'stock_condition'         => $lockedTransfer->stock_condition,
                'origin_location_id'      => $originLocationId,
                'destination_location_id' => $destinationLocationId,
                'source_movement_id'      => $sourceMovement?->id,
                'return_batch_id'         => $returnBatchId,
                'created_by'              => $userId,
                'updated_by'              => $userId,
            ]);

            if (!empty($linesData)) {
                $this->persistLinesAndSerials($movement, $linesData);
            }

            $this->recordHistory(
                $movement,
                $nextRevision,
                TransferMovementHistory::ACTION_CREATED,
                null,
                TransferMovement::STATUS_DRAFT,
                $userId,
                'Draft movement created',
                null,
                $idempotencyKey
            );

            return $movement->fresh(['lines.serials', 'histories']);
        });
    }

    /**
     * Start a superseding correction draft for a REJECTED attempt.
     */
    public function startCorrection(
        TransferMovement $rejectedMovement,
        int $userId,
        array $linesData = [],
        ?string $idempotencyKey = null
    ): TransferMovement {
        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($rejectedMovement, $userId, $linesData, $idempotencyKey) {
            $lockedTransfer = $this->lockAndValidateTransferForMovement($rejectedMovement->transfer_id);
            $lockedRejected = TransferMovement::where('id', $rejectedMovement->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingHistory = TransferMovementHistory::where('action', TransferMovementHistory::ACTION_CREATED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->whereHas('movement', function ($query) use ($lockedTransfer, $lockedRejected) {
                        $query->where('transfer_id', $lockedTransfer->id)
                            ->where('type', $lockedRejected->type);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory) {
                    return $existingHistory->movement()->with(['lines.serials', 'histories'])->first();
                }
            }

            if ($lockedRejected->status !== TransferMovement::STATUS_REJECTED) {
                throw new RuntimeException('A correction can only be started from a REJECTED movement attempt.');
            }

            $isBatchLineage = in_array($lockedRejected->type, [TransferMovement::TYPE_RETURN_DISPATCH, TransferMovement::TYPE_RETURN_RECEIPT], true);

            // Check no open attempt already exists within the same lineage
            $openAttemptQuery = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', $lockedRejected->type)
                ->whereIn('status', [TransferMovement::STATUS_DRAFT, TransferMovement::STATUS_PENDING]);

            if ($isBatchLineage) {
                $openAttemptQuery->where('return_batch_id', $lockedRejected->return_batch_id);
            }

            $hasOpenAttempt = $openAttemptQuery->lockForUpdate()->exists();

            if ($hasOpenAttempt) {
                throw new RuntimeException("An open attempt (DRAFT or PENDING) already exists for this transfer and type.");
            }

            // Determine next revision, scoped to the same lineage for RETURN_DISPATCH and RETURN_RECEIPT
            $revisionQuery = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', $lockedRejected->type);

            if ($isBatchLineage) {
                $revisionQuery->where('return_batch_id', $lockedRejected->return_batch_id);
            }

            $latestRevision = $revisionQuery->max('revision') ?? $lockedRejected->revision;

            $nextRevision = $latestRevision + 1;

            $newDraft = TransferMovement::create([
                'transfer_id'             => $lockedTransfer->id,
                'type'                    => $lockedRejected->type,
                'revision'                => $nextRevision,
                'lock_version'            => 1,
                'transfer_revision'       => $lockedTransfer->revision,
                'status'                  => TransferMovement::STATUS_DRAFT,
                'stock_condition'         => $lockedRejected->stock_condition,
                'origin_location_id'      => $lockedRejected->origin_location_id,
                'destination_location_id' => $lockedRejected->destination_location_id,
                'source_movement_id'      => $lockedRejected->source_movement_id,
                'supersedes_movement_id'  => $lockedRejected->id,
                'return_batch_id'         => $lockedRejected->return_batch_id,
                'created_by'              => $userId,
                'updated_by'              => $userId,
            ]);

            if (!empty($linesData)) {
                $this->persistLinesAndSerials($newDraft, $linesData);
            }

            $this->recordHistory(
                $newDraft,
                $nextRevision,
                TransferMovementHistory::ACTION_CREATED,
                null,
                TransferMovement::STATUS_DRAFT,
                $userId,
                "Correction draft created superseding revision {$lockedRejected->revision}",
                ['supersedes_movement_id' => $lockedRejected->id, 'supersedes_revision' => $lockedRejected->revision],
                $idempotencyKey
            );

            return $newDraft->fresh(['lines.serials', 'histories']);
        });
    }

    /**
     * Update an open shared draft in place with optimistic concurrency on lock_version.
     */
    public function updateDraft(
        TransferMovement $movement,
        int $expectedLockVersion,
        array $linesData,
        int $userId,
        ?string $idempotencyKey = null
    ): TransferMovement {
        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($movement, $expectedLockVersion, $linesData, $userId, $idempotencyKey) {
            $lockedTransfer = $this->lockAndValidateTransferForMovement($movement->transfer_id);
            $lockedMovement = TransferMovement::where('id', $movement->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingHistory = TransferMovementHistory::where('transfer_movement_id', $lockedMovement->id)
                    ->where('revision', $lockedMovement->revision)
                    ->where('action', TransferMovementHistory::ACTION_UPDATED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory) {
                    return $lockedMovement->fresh(['lines.serials', 'histories']);
                }
            }

            if ($lockedMovement->status !== TransferMovement::STATUS_DRAFT) {
                throw new RuntimeException("Cannot edit movement: current status is [{$lockedMovement->status}], only DRAFT can be modified.");
            }

            if ((int) $lockedMovement->lock_version !== $expectedLockVersion) {
                throw new RuntimeException("Optimistic lock error: expected lock_version {$expectedLockVersion} but movement is at lock_version {$lockedMovement->lock_version}.");
            }

            if ((int) $lockedMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
            }

            $this->persistLinesAndSerials($lockedMovement, $linesData);

            $lockedMovement->update([
                'lock_version'               => $lockedMovement->lock_version + 1,
                'empty_count_confirmed'      => false,
                'empty_count_confirmed_by'   => null,
                'empty_count_confirmed_at'   => null,
                'updated_by'                 => $userId,
            ]);

            $this->recordHistory(
                $lockedMovement,
                $lockedMovement->revision,
                TransferMovementHistory::ACTION_UPDATED,
                TransferMovement::STATUS_DRAFT,
                TransferMovement::STATUS_DRAFT,
                $userId,
                'Draft movement updated',
                null,
                $idempotencyKey
            );

            return $lockedMovement->fresh(['lines.serials', 'histories']);
        });
    }

    /**
     * Submit a DRAFT movement to PENDING status. Freezes content.
     */
    public function submitDraft(
        TransferMovement $movement,
        int $expectedLockVersion,
        int $userId,
        ?string $idempotencyKey = null
    ): TransferMovement {
        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($movement, $expectedLockVersion, $userId, $idempotencyKey) {
            $lockedTransfer = $this->lockAndValidateTransferForMovement($movement->transfer_id);
            $lockedMovement = TransferMovement::where('id', $movement->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingHistory = TransferMovementHistory::where('transfer_movement_id', $lockedMovement->id)
                    ->where('revision', $lockedMovement->revision)
                    ->where('action', TransferMovementHistory::ACTION_SUBMITTED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory) {
                    return $lockedMovement->fresh(['lines.serials', 'histories']);
                }
            }

            if ($lockedMovement->status !== TransferMovement::STATUS_DRAFT) {
                throw new RuntimeException("Only DRAFT movements can be submitted. Current status: [{$lockedMovement->status}].");
            }

            if ((int) $lockedMovement->lock_version !== $expectedLockVersion) {
                throw new RuntimeException("Optimistic lock error: expected lock_version {$expectedLockVersion} but movement is at lock_version {$lockedMovement->lock_version}.");
            }

            if ((int) $lockedMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
            }

            // Validate lines or document-level empty confirmation
            $lockedMovement->load(['lines.serials', 'lines.product']);
            if ($lockedMovement->lines->isEmpty()) {
                if (!($lockedMovement->type === TransferMovement::TYPE_FORWARD_RECEIPT && $lockedMovement->empty_count_confirmed)) {
                    throw new RuntimeException("Cannot submit movement with zero lines.");
                }
            } else {
                foreach ($lockedMovement->lines as $line) {
                    $qty = (float) $line->quantity;
                    if ($qty < 0) {
                        throw new RuntimeException("Movement line quantity for product ID {$line->product_id} cannot be negative.");
                    }

                    if ($qty === 0.0 && !$line->count_confirmed) {
                        throw new RuntimeException("Unconfirmed line for product ID {$line->product_id} cannot be submitted.");
                    }

                    $product = $line->product;
                    if ($product && (bool) ($product->serial_number_required ?? false)) {
                        $serialCount = $line->serials->count();
                        $lineQty = (float) $line->quantity;
                        if ((float) $serialCount !== $lineQty) {
                            throw new RuntimeException("Serialized product ID {$line->product_id} expects {$lineQty} serials, found {$serialCount}.");
                        }

                        // Re-query and lock every live serial during submission to ensure state has not drifted (for dispatch movements)
                        if (in_array($lockedMovement->type, [TransferMovement::TYPE_FORWARD_DISPATCH, TransferMovement::TYPE_RETURN_DISPATCH], true)) {
                            foreach ($line->serials as $movSerial) {
                                $liveSerial = ProductSerialNumber::where('id', $movSerial->product_serial_number_id)
                                    ->lockForUpdate()
                                    ->first();

                                $this->validateLiveSerialForMovement($liveSerial, $movSerial->serial_number, (int) $line->product_id, $lockedMovement);
                            }
                        }
                    }
                }
            }

            $now = Carbon::now();
            $lockedMovement->update([
                'status'       => TransferMovement::STATUS_PENDING,
                'submitted_by' => $userId,
                'submitted_at' => $now,
                'updated_by'   => $userId,
            ]);

            $this->recordHistory(
                $lockedMovement,
                $lockedMovement->revision,
                TransferMovementHistory::ACTION_SUBMITTED,
                TransferMovement::STATUS_DRAFT,
                TransferMovement::STATUS_PENDING,
                $userId,
                'Movement submitted for approval',
                null,
                $idempotencyKey
            );

            return $lockedMovement->fresh(['lines.serials', 'histories']);
        });
    }

    /**
     * Approve a PENDING movement attempt.
     */
    public function approve(
        TransferMovement $movement,
        int $userId,
        ?string $idempotencyKey = null
    ): TransferMovement {
        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($movement, $userId, $idempotencyKey) {
            $lockedTransfer = $this->lockAndValidateTransferForMovement($movement->transfer_id);
            $lockedMovement = TransferMovement::where('id', $movement->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingHistory = TransferMovementHistory::where('transfer_movement_id', $lockedMovement->id)
                    ->where('revision', $lockedMovement->revision)
                    ->where('action', TransferMovementHistory::ACTION_APPROVED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory) {
                    return $lockedMovement->fresh(['lines.serials', 'histories']);
                }
            }

            if ($lockedMovement->status !== TransferMovement::STATUS_PENDING) {
                throw new RuntimeException("Only PENDING movements can be approved. Current status: [{$lockedMovement->status}].");
            }

            if ((int) $lockedMovement->transfer_revision !== (int) $lockedTransfer->revision) {
                throw new RuntimeException("Transfer revision mismatch: transfer has advanced to revision {$lockedTransfer->revision}.");
            }

            // Enforce one approved attempt per (transfer_id, type), scoped per lineage for RETURN_DISPATCH and RETURN_RECEIPT
            $hasOtherApprovedQuery = TransferMovement::where('transfer_id', $lockedTransfer->id)
                ->where('type', $lockedMovement->type)
                ->where('status', TransferMovement::STATUS_APPROVED)
                ->where('id', '!=', $lockedMovement->id);

            if (in_array($lockedMovement->type, [TransferMovement::TYPE_RETURN_DISPATCH, TransferMovement::TYPE_RETURN_RECEIPT], true)) {
                $hasOtherApprovedQuery->where('return_batch_id', $lockedMovement->return_batch_id);
            }

            $hasOtherApproved = $hasOtherApprovedQuery->lockForUpdate()->exists();

            if ($hasOtherApproved) {
                throw new RuntimeException("Another attempt for transfer ID {$lockedTransfer->id} and type [{$lockedMovement->type}] is already APPROVED.");
            }

            $now = Carbon::now();
            $lockedMovement->update([
                'status'      => TransferMovement::STATUS_APPROVED,
                'reviewed_by' => $userId,
                'reviewed_at' => $now,
                'updated_by'  => $userId,
            ]);

            $this->recordHistory(
                $lockedMovement,
                $lockedMovement->revision,
                TransferMovementHistory::ACTION_APPROVED,
                TransferMovement::STATUS_PENDING,
                TransferMovement::STATUS_APPROVED,
                $userId,
                'Movement approved',
                null,
                $idempotencyKey
            );

            return $lockedMovement->fresh(['lines.serials', 'histories']);
        });
    }

    /**
     * Reject a PENDING movement attempt with a mandatory reason.
     */
    public function reject(
        TransferMovement $movement,
        string $reason,
        int $userId,
        ?string $idempotencyKey = null
    ): TransferMovement {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A rejection reason is required.');
        }

        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($movement, $reason, $userId, $idempotencyKey) {
            $lockedTransfer = $this->lockAndValidateTransferForMovement($movement->transfer_id);
            $lockedMovement = TransferMovement::where('id', $movement->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingHistory = TransferMovementHistory::where('transfer_movement_id', $lockedMovement->id)
                    ->where('revision', $lockedMovement->revision)
                    ->where('action', TransferMovementHistory::ACTION_REJECTED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory) {
                    return $lockedMovement->fresh(['lines.serials', 'histories']);
                }
            }

            if ($lockedMovement->status !== TransferMovement::STATUS_PENDING) {
                throw new RuntimeException("Only PENDING movements can be rejected. Current status: [{$lockedMovement->status}].");
            }

            $now = Carbon::now();
            $lockedMovement->update([
                'status'           => TransferMovement::STATUS_REJECTED,
                'reviewed_by'      => $userId,
                'reviewed_at'      => $now,
                'rejection_reason' => $reason,
                'updated_by'       => $userId,
            ]);

            $this->recordHistory(
                $lockedMovement,
                $lockedMovement->revision,
                TransferMovementHistory::ACTION_REJECTED,
                TransferMovement::STATUS_PENDING,
                TransferMovement::STATUS_REJECTED,
                $userId,
                $reason,
                null,
                $idempotencyKey
            );

            return $lockedMovement->fresh(['lines.serials', 'histories']);
        });
    }

    /**
     * Cancel a DRAFT movement attempt with a mandatory reason.
     */
    public function cancel(
        TransferMovement $movement,
        string $reason,
        int $userId,
        ?string $idempotencyKey = null
    ): TransferMovement {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A cancellation reason is required.');
        }

        $idempotencyKey = $idempotencyKey ? trim($idempotencyKey) : null;

        return DB::transaction(function () use ($movement, $reason, $userId, $idempotencyKey) {
            $lockedTransfer = $this->lockAndValidateTransferForMovement($movement->transfer_id);
            $lockedMovement = TransferMovement::where('id', $movement->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingHistory = TransferMovementHistory::where('transfer_movement_id', $lockedMovement->id)
                    ->where('revision', $lockedMovement->revision)
                    ->where('action', TransferMovementHistory::ACTION_CANCELLED)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingHistory) {
                    return $lockedMovement->fresh(['lines.serials', 'histories']);
                }
            }

            if ($lockedMovement->status !== TransferMovement::STATUS_DRAFT) {
                throw new RuntimeException("Only DRAFT movements can be cancelled. Current status: [{$lockedMovement->status}].");
            }

            $lockedMovement->update([
                'status'              => TransferMovement::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
                'updated_by'          => $userId,
            ]);

            $this->recordHistory(
                $lockedMovement,
                $lockedMovement->revision,
                TransferMovementHistory::ACTION_CANCELLED,
                TransferMovement::STATUS_DRAFT,
                TransferMovement::STATUS_CANCELLED,
                $userId,
                $reason,
                null,
                $idempotencyKey
            );

            return $lockedMovement->fresh(['lines.serials', 'histories']);
        });
    }

    /**
     * Lock transfer and validate eligible approved transfer state for movements.
     */
    protected function lockAndValidateTransferForMovement(int $transferId): Transfer
    {
        $transfer = Transfer::where('id', $transferId)->lockForUpdate()->firstOrFail();

        // For the movement foundation, APPROVED/DISPATCHED transfers are eligible for forward-leg
        // movement operations, and AWAITING_RETURN/RETURN_DISPATCHED transfers are eligible for
        // return-leg movement operations (return dispatch batches may be prepared and approved
        // independently and concurrently once the transfer is awaiting return).
        $eligibleStatuses = [
            Transfer::STATUS_APPROVED,
            Transfer::STATUS_DISPATCHED,
            Transfer::STATUS_AWAITING_RETURN,
            Transfer::STATUS_RETURN_DISPATCHED,
        ];

        if (!in_array($transfer->status, $eligibleStatuses, true)) {
            throw new RuntimeException("Transfer is in status [{$transfer->status}], but only APPROVED, DISPATCHED, AWAITING_RETURN, or RETURN_DISPATCHED transfers are eligible for movement operations.");
        }

        if (!$transfer->hasExplicitCondition()) {
            throw new RuntimeException('Transfer must have an explicit stock condition (GOOD or BREAKAGE) for movement operations.');
        }

        return $transfer;
    }

    /**
     * Canonicalize and persist movement lines and authoritative normalized serials.
     */
    protected function persistLinesAndSerials(TransferMovement $movement, array $linesData): void
    {
        // 1. Group and canonicalize quantities per product_id with exact decimal string arithmetic
        $canonicalLines = [];
        $serialsByProduct = [];
        $confirmedByProduct = [];

        foreach ($linesData as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('Invalid product ID in movement line.');
            }

            $rawQuantity = $row['quantity'] ?? 0;
            $qtyStr = $this->validateAndNormalizeQuantityString($rawQuantity, $productId);
            $confirmed = (bool) ($row['count_confirmed'] ?? false);

            if (!isset($canonicalLines[$productId])) {
                $canonicalLines[$productId] = '0.0000';
                $serialsByProduct[$productId] = [];
                $confirmedByProduct[$productId] = false;
            }

            $canonicalLines[$productId] = bcadd($canonicalLines[$productId], $qtyStr, 4);
            if ($confirmed) {
                $confirmedByProduct[$productId] = true;
            }

            if (!empty($row['serials']) && is_array($row['serials'])) {
                foreach ($row['serials'] as $s) {
                    $serialsByProduct[$productId][] = $s;
                }
            }
        }

        // Wipe old lines and serials for draft update
        TransferMovementSerial::where('transfer_movement_id', $movement->id)->delete();
        TransferMovementLine::where('transfer_movement_id', $movement->id)->delete();

        // 2. Track all seen serial numbers across this movement to prevent duplicates
        $seenMovementSerials = [];

        foreach ($canonicalLines as $productId => $totalQuantityStr) {
            $product = Product::find($productId);
            if (!$product) {
                throw new RuntimeException("Product ID {$productId} not found.");
            }

            $line = TransferMovementLine::create([
                'transfer_movement_id' => $movement->id,
                'product_id'           => $productId,
                'quantity'             => $totalQuantityStr,
                'count_confirmed'      => $confirmedByProduct[$productId] ?? false,
            ]);

            $rawSerials = $serialsByProduct[$productId] ?? [];
            if (!empty($rawSerials)) {
                foreach ($rawSerials as $serialItem) {
                    $serialStr = is_array($serialItem) ? ($serialItem['serial_number'] ?? '') : (string) $serialItem;
                    $normalized = TransferMovementSerial::normalize($serialStr);

                    if ($normalized === '') {
                        continue;
                    }

                    if (isset($seenMovementSerials[$normalized])) {
                        throw new RuntimeException("Duplicate serial number [{$normalized}] in movement attempt.");
                    }
                    $seenMovementSerials[$normalized] = true;

                    // Authoritatively resolve and verify live serial number
                    $liveSerial = null;
                    $suppliedSerialId = is_array($serialItem) ? ($serialItem['product_serial_number_id'] ?? null) : null;

                    if ($suppliedSerialId !== null) {
                        $foundSerial = ProductSerialNumber::find($suppliedSerialId);
                        if ($foundSerial && TransferMovementSerial::normalize((string) $foundSerial->serial_number) === $normalized) {
                            $liveSerial = $foundSerial;
                        }
                    }

                    if ($liveSerial === null) {
                        $liveSerial = ProductSerialNumber::where('serial_number', $normalized)
                            ->where('product_id', $productId)
                            ->first();

                        if ($liveSerial === null) {
                            $liveSerial = ProductSerialNumber::where('serial_number', $normalized)->first();
                        }
                    }

                    if (in_array($movement->type, [TransferMovement::TYPE_FORWARD_DISPATCH, TransferMovement::TYPE_RETURN_DISPATCH], true)) {
                        $this->validateLiveSerialForMovement($liveSerial, $normalized, $productId, $movement);
                    }

                    $taxId = $liveSerial?->tax_id;
                    $taxName = null;
                    $taxRate = null;

                    if ($taxId) {
                        $tax = Tax::find($taxId);
                        if ($tax) {
                            $taxName = $tax->name ?? null;
                            $taxRate = $tax->value ?? null;
                        }
                    }

                    TransferMovementSerial::create([
                        'transfer_movement_id'      => $movement->id,
                        'transfer_movement_line_id' => $line->id,
                        'product_id'                => $productId,
                        'product_serial_number_id'  => $liveSerial?->id,
                        'serial_number'             => $normalized,
                        'stock_condition'           => $movement->stock_condition,
                        'tax_id'                    => $taxId,
                        'tax_name'                  => $taxName,
                        'tax_rate'                  => $taxRate,
                        'transit_custody_status'    => TransferMovementSerial::CUSTODY_INACTIVE,
                    ]);
                }
            }
        }
    }

    /**
     * Validate and normalize a quantity string to at most 4 decimal places, rejecting unsupported precision.
     */
    protected function validateAndNormalizeQuantityString($rawQuantity, int $productId): string
    {
        if (!is_numeric($rawQuantity)) {
            throw new RuntimeException("Quantity for product ID {$productId} must be numeric.");
        }

        $str = (string) $rawQuantity;
        if (str_contains($str, '.')) {
            $parts = explode('.', $str, 2);
            $decimals = rtrim($parts[1], '0');
            if (strlen($decimals) > 4) {
                throw new RuntimeException("Quantity [{$rawQuantity}] for product ID {$productId} exceeds maximum supported precision of 4 decimal places.");
            }
        }

        $bval = bcadd((string) $rawQuantity, '0', 4);
        if (bccomp($bval, '0', 4) < 0) {
            throw new RuntimeException("Quantity for product ID {$productId} cannot be negative.");
        }

        return $bval;
    }

    /**
     * Validate movement type ordering against transfer and existing movements.
     */
    protected function validateMovementTypeEligibility(Transfer $transfer, string $type): void
    {
        if (!in_array($type, TransferMovement::TYPES, true)) {
            throw new RuntimeException("Invalid movement type [{$type}].");
        }

        if ($type === TransferMovement::TYPE_FORWARD_RECEIPT) {
            // Must have an approved forward dispatch
            $hasApprovedDispatch = TransferMovement::where('transfer_id', $transfer->id)
                ->where('type', TransferMovement::TYPE_FORWARD_DISPATCH)
                ->where('status', TransferMovement::STATUS_APPROVED)
                ->exists();

            if (!$hasApprovedDispatch) {
                throw new RuntimeException('Cannot create forward receipt without an approved forward dispatch.');
            }
        }

        if ($type === TransferMovement::TYPE_RETURN_DISPATCH) {
            // Must have an approved forward receipt
            $hasApprovedReceipt = TransferMovement::where('transfer_id', $transfer->id)
                ->where('type', TransferMovement::TYPE_FORWARD_RECEIPT)
                ->where('status', TransferMovement::STATUS_APPROVED)
                ->exists();

            if (!$hasApprovedReceipt) {
                throw new RuntimeException('Cannot create return dispatch without an approved forward receipt.');
            }
        }

        if ($type === TransferMovement::TYPE_RETURN_RECEIPT) {
            // Must have an approved return dispatch
            $hasApprovedReturnDispatch = TransferMovement::where('transfer_id', $transfer->id)
                ->where('type', TransferMovement::TYPE_RETURN_DISPATCH)
                ->where('status', TransferMovement::STATUS_APPROVED)
                ->exists();

            if (!$hasApprovedReturnDispatch) {
                throw new RuntimeException('Cannot create return receipt without an approved return dispatch.');
            }
        }
    }

    /**
     * Validate and resolve source movement reference.
     */
    protected function validateAndResolveSourceMovement(Transfer $transfer, string $type, int $sourceMovementId): TransferMovement
    {
        $source = TransferMovement::find($sourceMovementId);
        if (!$source) {
            throw new RuntimeException("Source movement ID {$sourceMovementId} not found.");
        }

        if ((int) $source->transfer_id !== (int) $transfer->id) {
            throw new RuntimeException("Source movement belongs to transfer ID {$source->transfer_id}, not {$transfer->id}.");
        }

        if ($source->status !== TransferMovement::STATUS_APPROVED) {
            throw new RuntimeException("Source movement must be APPROVED, found status [{$source->status}].");
        }

        if ($type === TransferMovement::TYPE_FORWARD_RECEIPT && $source->type !== TransferMovement::TYPE_FORWARD_DISPATCH) {
            throw new RuntimeException("Forward receipt must reference a FORWARD_DISPATCH source, found [{$source->type}].");
        }

        if ($type === TransferMovement::TYPE_RETURN_DISPATCH && $source->type !== TransferMovement::TYPE_FORWARD_RECEIPT) {
            throw new RuntimeException("Return dispatch must reference a FORWARD_RECEIPT source, found [{$source->type}].");
        }

        if ($type === TransferMovement::TYPE_RETURN_RECEIPT && $source->type !== TransferMovement::TYPE_RETURN_DISPATCH) {
            throw new RuntimeException("Return receipt must reference a RETURN_DISPATCH source, found [{$source->type}].");
        }

        return $source;
    }

    /**
     * Resolve operational source and destination locations based on movement type and transfer.
     */
    protected function resolveOperationalLocations(Transfer $transfer, string $type): array
    {
        if (in_array($type, [TransferMovement::TYPE_FORWARD_DISPATCH, TransferMovement::TYPE_FORWARD_RECEIPT], true)) {
            return [$transfer->origin_location_id, $transfer->destination_location_id];
        }

        // Return movements reverse the flow
        return [$transfer->destination_location_id, $transfer->origin_location_id];
    }

    /**
     * Record a movement action history entry.
     */
    protected function recordHistory(
        TransferMovement $movement,
        int $revision,
        string $action,
        ?string $fromStatus,
        string $toStatus,
        int $actorId,
        ?string $reason = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null
    ): TransferMovementHistory {
        return TransferMovementHistory::create([
            'transfer_movement_id' => $movement->id,
            'revision'             => $revision,
            'action'               => $action,
            'from_status'          => $fromStatus,
            'to_status'            => $toStatus,
            'actor_id'             => $actorId,
            'reason'               => $reason,
            'metadata'             => $metadata,
            'idempotency_key'      => $idempotencyKey,
        ]);
    }

    /**
     * Authoritatively validate a live ProductSerialNumber record against movement criteria.
     * Requires exact serial identity match, product match, exact origin location match, matching condition, and operational availability.
     */
    protected function validateLiveSerialForMovement(
        ?ProductSerialNumber $liveSerial,
        string $normalized,
        int $productId,
        TransferMovement $movement
    ): void {
        if (!$liveSerial) {
            throw new RuntimeException("Authoritative serial record for [{$normalized}] on product ID {$productId} was not found.");
        }

        // Recheck serial identity
        if (ProductSerialNumber::normalize((string) $liveSerial->serial_number) !== $normalized) {
            throw new RuntimeException("Live serial record ID {$liveSerial->id} text [{$liveSerial->serial_number}] does not match expected serial [{$normalized}].");
        }

        if ((int) $liveSerial->product_id !== $productId) {
            throw new RuntimeException("Serial [{$normalized}] belongs to product ID {$liveSerial->product_id}, not line product ID {$productId}.");
        }

        // Return dispatch may select any eligible substitute serial, but its live tax classification
        // must match the destination-side classification committed by the transfer's route policy,
        // since serial provenance must agree with the inventory bucket being deducted.
        if ($movement->type === TransferMovement::TYPE_RETURN_DISPATCH) {
            $policy = \Modules\Adjustment\Entities\TransferRoutePolicy::where('transfer_id', $movement->transfer_id)
                ->orderByDesc('transfer_revision')
                ->first();

            if ($policy && $policy->destination_classification !== \Modules\Adjustment\Entities\TransferRoutePolicy::CLASSIFICATION_PRESERVE) {
                $expectsTax = $policy->destination_classification === \Modules\Adjustment\Entities\TransferRoutePolicy::CLASSIFICATION_TAX;
                $isTax = (bool) $liveSerial->tax_id;

                if ($expectsTax !== $isTax) {
                    throw new RuntimeException("Serial [{$normalized}] tax classification does not match the committed destination-side classification.");
                }
            }
        }

        // Check live serial condition vs movement condition
        $isBroken = (bool) ($liveSerial->is_broken ?? false);
        if ($movement->stock_condition === Transfer::CONDITION_GOOD && $isBroken) {
            throw new RuntimeException("Serial [{$normalized}] is broken but movement condition is GOOD.");
        }
        if ($movement->stock_condition === Transfer::CONDITION_BREAKAGE && !$isBroken) {
            throw new RuntimeException("Serial [{$normalized}] is good but movement condition is BREAKAGE.");
        }

        // Check location eligibility: must strictly match movement origin location
        if ($liveSerial->location_id === null || (int) $liveSerial->location_id !== (int) $movement->origin_location_id) {
            $currLoc = $liveSerial->location_id ?? 'null';
            throw new RuntimeException("Serial [{$normalized}] is located at location ID {$currLoc}, not movement origin {$movement->origin_location_id}.");
        }

        // Check operational availability matching ProductSerialNumber::scopeAvailable rule
        if ($liveSerial->dispatch_detail_id !== null) {
            throw new RuntimeException("Serial [{$normalized}] has already been dispatched.");
        }
        if ($liveSerial->is_in_return_process) {
            throw new RuntimeException("Serial [{$normalized}] is currently in a return process.");
        }
        if ($liveSerial->hasActiveTransferCustody()) {
            throw new RuntimeException("Serial [{$normalized}] is currently in transit custody.");
        }
        
        $status = $liveSerial->status;
        if ($status !== null && !in_array($status, [ProductSerialNumber::STATUS_ACTIVE, ProductSerialNumber::STATUS_BROKEN], true)) {
            throw new RuntimeException("Serial [{$normalized}] status [{$status}] is not available.");
        }
    }
}

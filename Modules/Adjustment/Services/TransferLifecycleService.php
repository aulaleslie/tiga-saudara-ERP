<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferProduct;
use RuntimeException;
use Throwable;

class TransferLifecycleService
{
    /**
     * Create a new transfer in DRAFT status.
     * Uses DB transactions to handle idempotency safely.
     */
    public function createDraft(int $originLocationId, int $destinationLocationId, array $productsData, int $userId, ?string $idempotencyKey = null): Transfer
    {
        $idempotencyKey = $idempotencyKey ? strtoupper($idempotencyKey) : null;

        return DB::transaction(function () use ($originLocationId, $destinationLocationId, $productsData, $userId, $idempotencyKey) {
            // First check idempotency at the database level using a locked read or unique constraint if applicable.
            if ($idempotencyKey) {
                // If we already have an action history for this key and ACTION_CREATED, return the existing transfer.
                $existingAction = TransferActionHistory::where('idempotency_key', $idempotencyKey)
                    ->where('action', TransferActionHistory::ACTION_CREATED)
                    ->lockForUpdate()
                    ->first();

                if ($existingAction) {
                    return $existingAction->transfer;
                }
            }

            // Create transfer
            $transfer = Transfer::create([
                'origin_location_id'      => $originLocationId,
                'destination_location_id' => $destinationLocationId,
                'created_by'              => $userId,
                'status'                  => Transfer::STATUS_DRAFT,
                'revision'                => 1,
            ]);

            // Add products
            $this->syncProducts($transfer, $productsData);

            // Record action history
            $this->recordHistory($transfer, TransferActionHistory::ACTION_CREATED, null, Transfer::STATUS_DRAFT, $userId, 'Draft transfer created', null, $idempotencyKey);

            return $transfer;
        });
    }

    /**
     * Submit a draft transfer to PENDING status.
     */
    public function submitDraft(Transfer $transfer, int $userId): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            if ($transfer->status !== Transfer::STATUS_DRAFT) {
                throw new RuntimeException('Only DRAFT transfers can be submitted.');
            }

            $transfer->update([
                'status'   => Transfer::STATUS_PENDING,
                'revision' => $transfer->revision + 1,
            ]);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_SUBMITTED, Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING, $userId, 'Draft submitted for approval');

            return $transfer;
        });
    }

    /**
     * Create a transfer and immediately submit it to PENDING status in one atomic transaction.
     * Used for initial creation via HTTP or Livewire to ensure atomicity:
     * if submission fails, no draft remains.
     */
    public function createPending(int $originLocationId, int $destinationLocationId, array $productsData, int $userId, ?string $idempotencyKey = null): Transfer
    {
        $idempotencyKey = $idempotencyKey ? strtoupper($idempotencyKey) : null;

        return DB::transaction(function () use ($originLocationId, $destinationLocationId, $productsData, $userId, $idempotencyKey) {
            // Check idempotency at the database level
            if ($idempotencyKey) {
                $existingAction = TransferActionHistory::where('idempotency_key', $idempotencyKey)
                    ->where('action', TransferActionHistory::ACTION_SUBMITTED)
                    ->lockForUpdate()
                    ->first();

                if ($existingAction) {
                    return $existingAction->transfer;
                }
            }

            // Create transfer
            $transfer = Transfer::create([
                'origin_location_id'      => $originLocationId,
                'destination_location_id' => $destinationLocationId,
                'created_by'              => $userId,
                'status'                  => Transfer::STATUS_DRAFT,
                'revision'                => 1,
            ]);

            // Add products
            $this->syncProducts($transfer, $productsData);

            // Record creation
            $this->recordHistory($transfer, TransferActionHistory::ACTION_CREATED, null, Transfer::STATUS_DRAFT, $userId, 'Transfer created and submitted', null, $idempotencyKey);

            // Immediately submit to PENDING
            $transfer->update([
                'status'   => Transfer::STATUS_PENDING,
                'revision' => $transfer->revision + 1,
            ]);

            // Record submission
            $this->recordHistory($transfer, TransferActionHistory::ACTION_SUBMITTED, Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING, $userId, 'Atomically submitted on creation');

            return $transfer;
        });
    }

    /**
     * Update a DRAFT or PENDING transfer.
     */
    public function updateTransfer(Transfer $transfer, array $productsData, int $userId): Transfer
    {
        return DB::transaction(function () use ($transfer, $productsData, $userId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            if (! in_array($transfer->status, [Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING])) {
                throw new RuntimeException('Transfers can only be edited when in DRAFT or PENDING status.');
            }

            $this->syncProducts($transfer, $productsData);

            $transfer->update([
                'revision' => $transfer->revision + 1,
            ]);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_EDITED, $transfer->status, $transfer->status, $userId, 'Transfer products updated');

            return $transfer;
        });
    }

    /**
     * Approve a PENDING transfer.
     */
    public function approve(Transfer $transfer, int $userId, int $currentSettingId): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId, $currentSettingId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            $transfer->loadMissing('originLocation.setting');
            if ($transfer->originLocation?->setting_id !== $currentSettingId) {
                throw new RuntimeException('Transfer can only be approved by the origin tenant.');
            }

            if ($transfer->status !== Transfer::STATUS_PENDING) {
                throw new RuntimeException('Only PENDING transfers can be approved.');
            }

            $transfer->update([
                'status'      => Transfer::STATUS_APPROVED,
                'revision'    => $transfer->revision + 1,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_APPROVED, Transfer::STATUS_PENDING, Transfer::STATUS_APPROVED, $userId, 'Transfer approved');

            return $transfer;
        });
    }

    /**
     * Reject a PENDING transfer.
     */
    public function reject(Transfer $transfer, int $userId, int $currentSettingId, string $reason): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId, $currentSettingId, $reason) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            $transfer->loadMissing('originLocation.setting');
            if ($transfer->originLocation?->setting_id !== $currentSettingId) {
                throw new RuntimeException('Transfer can only be rejected by the origin tenant.');
            }

            if ($transfer->status !== Transfer::STATUS_PENDING) {
                throw new RuntimeException('Only PENDING transfers can be rejected.');
            }

            $transfer->update([
                'status'      => Transfer::STATUS_REJECTED,
                'revision'    => $transfer->revision + 1,
                'rejected_by' => $userId,
                'rejected_at' => now(),
            ]);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_REJECTED, Transfer::STATUS_PENDING, Transfer::STATUS_REJECTED, $userId, $reason);

            return $transfer;
        });
    }

    /**
     * Acknowledge a REJECTED transfer, returning it to DRAFT.
     */
    public function acknowledgeRejection(Transfer $transfer, int $userId, int $currentSettingId): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId, $currentSettingId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            $transfer->loadMissing('originLocation.setting');
            if ($transfer->originLocation?->setting_id !== $currentSettingId) {
                throw new RuntimeException('Rejection can only be acknowledged by the origin tenant.');
            }

            if ($transfer->status !== Transfer::STATUS_REJECTED) {
                throw new RuntimeException('Only REJECTED transfers can be acknowledged.');
            }

            $transfer->update([
                'status'   => Transfer::STATUS_DRAFT,
                'revision' => $transfer->revision + 1,
            ]);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_ACKNOWLEDGED, Transfer::STATUS_REJECTED, Transfer::STATUS_DRAFT, $userId, 'Rejection acknowledged');

            return $transfer;
        });
    }

    /**
     * Resubmit a DRAFT or REJECTED transfer to PENDING.
     */
    public function resubmit(Transfer $transfer, int $userId, int $currentSettingId, ?array $productsData = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $productsData, $userId, $currentSettingId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            $transfer->loadMissing('originLocation.setting');
            if ($transfer->originLocation?->setting_id !== $currentSettingId) {
                throw new RuntimeException('Transfer can only be resubmitted by the origin tenant.');
            }

            if (! in_array($transfer->status, [Transfer::STATUS_DRAFT, Transfer::STATUS_REJECTED])) {
                throw new RuntimeException('Only DRAFT or REJECTED transfers can be resubmitted.');
            }

            $fromStatus = $transfer->status;
            
            // Only sync products if new data provided
            if ($productsData !== null) {
                $this->syncProducts($transfer, $productsData);
            }

            $transfer->update([
                'status'   => Transfer::STATUS_PENDING,
                'revision' => $transfer->revision + 1,
            ]);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_RESUBMITTED, $fromStatus, Transfer::STATUS_PENDING, $userId, 'Transfer resubmitted');

            return $transfer;
        });
    }

    /**
     * Archive a transfer.
     */
    public function archive(Transfer $transfer, int $userId, int $currentSettingId, string $reason): Transfer
    {
        if (empty(trim($reason))) {
            throw new RuntimeException('Archive reason cannot be empty.');
        }

        return DB::transaction(function () use ($transfer, $userId, $currentSettingId, $reason) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            $transfer->loadMissing('originLocation.setting');
            if ($transfer->originLocation?->setting_id !== $currentSettingId) {
                throw new RuntimeException('Transfer can only be archived by the origin tenant.');
            }

            if ($transfer->status !== Transfer::STATUS_APPROVED) {
                throw new RuntimeException('Only APPROVED transfers can be archived.');
            }

            $fromStatus = $transfer->status;

            $transfer->update([
                'status'         => Transfer::STATUS_ARCHIVED,
                'revision'       => $transfer->revision + 1,
                'archived_by'    => $userId,
                'archive_reason' => $reason,
                'archived_at'    => now(),
            ]);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_ARCHIVED, $fromStatus, Transfer::STATUS_ARCHIVED, $userId, $reason);

            return $transfer;
        });
    }

    /**
     * Dispatch the transfer.
     */
    public function dispatch(Transfer $transfer, int $userId, int $currentSettingId, ?string $acknowledgedHash = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId, $currentSettingId, $acknowledgedHash) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            $transfer->loadMissing('originLocation.setting');
            if ($transfer->originLocation?->setting_id !== $currentSettingId) {
                throw new RuntimeException('Transfer can only be dispatched by the origin tenant.');
            }

            if ($transfer->status !== Transfer::STATUS_APPROVED) {
                throw new RuntimeException('Only APPROVED transfers can be dispatched.');
            }

            $dispatchInfo = app(\Modules\Adjustment\Services\TransferMovementService::class)->dispatch($transfer, $acknowledgedHash);

            // Record dispatch review if drift was acknowledged
            if ($dispatchInfo['drift_detected'] && $acknowledgedHash === $dispatchInfo['current_hash']) {
                $this->recordHistory(
                    $transfer,
                    TransferActionHistory::ACTION_DISPATCH_REVIEWED,
                    Transfer::STATUS_APPROVED,
                    Transfer::STATUS_APPROVED,
                    $userId,
                    'Dispatch allocation drift acknowledged',
                    [
                        'acknowledged_hash' => $dispatchInfo['acknowledged_hash'],
                        'current_hash' => $dispatchInfo['current_hash'],
                    ]
                );
            }

            $transfer->update([
                'status'        => Transfer::STATUS_DISPATCHED,
                'revision'      => $transfer->revision + 1,
                'dispatched_by' => $userId,
                'dispatched_at' => now(),
            ]);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_DISPATCHED, Transfer::STATUS_APPROVED, Transfer::STATUS_DISPATCHED, $userId, 'Transfer dispatched');

            return $transfer;
        });
    }

    /**
     * Receive the transfer.
     */
    public function receive(Transfer $transfer, int $userId, int $currentSettingId): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId, $currentSettingId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            $transfer->loadMissing('destinationLocation.setting');
            if ($transfer->destinationLocation?->setting_id !== $currentSettingId) {
                throw new RuntimeException('Transfer can only be received by the destination tenant.');
            }

            if ($transfer->status !== Transfer::STATUS_DISPATCHED) {
                throw new RuntimeException('Only DISPATCHED transfers can be received.');
            }

            $fromStatus = $transfer->status;
            $newStatus = app(\Modules\Adjustment\Services\TransferMovementService::class)->receive($transfer);
            $transfer->refresh(); // Refresh to get updated status

            // Record RECEIVED action
            $this->recordHistory($transfer, TransferActionHistory::ACTION_RECEIVED, $fromStatus, $newStatus, $userId, 'Transfer received');
            
            // Record COMPLETED action if transfer is now complete
            if ($newStatus === Transfer::STATUS_COMPLETED) {
                $this->recordHistory($transfer, TransferActionHistory::ACTION_COMPLETED, $fromStatus, Transfer::STATUS_COMPLETED, $userId, 'Transfer completed');
            }

            return $transfer;
        });
    }

    /**
     * Dispatch return for the transfer.
     */
    public function dispatchReturn(Transfer $transfer, int $userId, int $currentSettingId): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId, $currentSettingId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            $transfer->loadMissing('destinationLocation.setting');
            if ($transfer->destinationLocation?->setting_id !== $currentSettingId) {
                throw new RuntimeException('Transfer can only be returned by the destination tenant.');
            }

            if ($transfer->status !== Transfer::STATUS_AWAITING_RETURN) {
                throw new RuntimeException('Transfer is not available for return dispatch.');
            }

            app(\Modules\Adjustment\Services\TransferMovementService::class)->dispatchReturn($transfer);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_RETURN_DISPATCHED, Transfer::STATUS_AWAITING_RETURN, $transfer->status, $userId, 'Transfer return dispatched');

            return $transfer;
        });
    }

    /**
     * Receive return for the transfer.
     */
    public function receiveReturn(Transfer $transfer, int $userId, int $currentSettingId): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId, $currentSettingId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            $transfer->loadMissing('originLocation.setting');
            if ($transfer->originLocation?->setting_id !== $currentSettingId) {
                throw new RuntimeException('Transfer return can only be received by the origin tenant.');
            }

            if ($transfer->status !== Transfer::STATUS_RETURN_DISPATCHED) {
                throw new RuntimeException('Transfer must be in return dispatched status.');
            }

            $previousStatus = $transfer->status;
            app(\Modules\Adjustment\Services\TransferMovementService::class)->receiveReturn($transfer);

            // Reload to get updated status
            $transfer->refresh();

            $this->recordHistory($transfer, TransferActionHistory::ACTION_RETURN_RECEIVED, $previousStatus, $transfer->status, $userId, 'Transfer return received');
            
            // Record completion when transfer reaches COMPLETED status
            if ($transfer->status === Transfer::STATUS_COMPLETED) {
                $this->recordHistory($transfer, TransferActionHistory::ACTION_COMPLETED, Transfer::STATUS_RETURN_DISPATCHED, Transfer::STATUS_COMPLETED, $userId, 'Transfer completed');
            }

            return $transfer;
        });
    }

    /**
     * Sync transfer products.
     */
    private function syncProducts(Transfer $transfer, array $productsData): void
    {
        // First, clear existing products
        $transfer->products()->delete();

        foreach ($productsData as $productData) {
            $quantity = (int) ($productData['quantity'] ?? ($productData['total'] ?? 0));
            if ($quantity <= 0) {
                continue;
            }

            $quantities = $productData['quantities'] ?? [];

            TransferProduct::create([
                'transfer_id'             => $transfer->id,
                'product_id'              => (int) ($productData['product_id'] ?? $productData['id']),
                'quantity'                => $quantity,
                'quantity_tax'            => $quantities['quantity_tax'] ?? 0,
                'quantity_non_tax'        => $quantities['quantity_non_tax'] ?? 0,
                'quantity_broken_tax'     => $quantities['quantity_broken_tax'] ?? 0,
                'quantity_broken_non_tax' => $quantities['quantity_broken_non_tax'] ?? 0,
                'serial_numbers'          => !empty($productData['serial_numbers']) ? $productData['serial_numbers'] : null,
            ]);
        }
    }

    /**
     * Lock a transfer and check its revision to prevent concurrent modifications.
     */
    private function lockTransfer(int $transferId, int $expectedRevision): Transfer
    {
        $transfer = Transfer::where('id', $transferId)->lockForUpdate()->first();
        
        if (! $transfer) {
            throw new RuntimeException("Transfer #{$transferId} not found.");
        }

        if ($transfer->revision !== $expectedRevision) {
            throw new RuntimeException("Transfer has been modified by another process. Please refresh and try again.");
        }

        return $transfer;
    }

    /**
     * Record an action in the history table.
     */
    private function recordHistory(Transfer $transfer, string $action, ?string $fromStatus, string $toStatus, ?int $actorId, ?string $reason = null, ?array $metadata = null, ?string $idempotencyKey = null): void
    {
        TransferActionHistory::create([
            'transfer_id'     => $transfer->id,
            'revision'        => $transfer->revision,
            'action'          => $action,
            'from_status'     => $fromStatus,
            'to_status'       => $toStatus,
            'actor_id'        => $actorId,
            'reason'          => $reason,
            'metadata'        => $metadata,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}

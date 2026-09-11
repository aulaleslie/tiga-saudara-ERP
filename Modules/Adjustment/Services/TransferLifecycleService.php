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
     * Create a new transfer in DRAFT status. Destination and stock condition
     * may be supplied later; a draft can be saved with only an origin, mode,
     * and product rows.
     * Uses DB transactions to handle idempotency safely.
     */
    public function createDraft(int $originLocationId, ?int $destinationLocationId, ?string $stockCondition, array $productsData, int $userId, ?string $idempotencyKey = null): Transfer
    {
        $idempotencyKey = $idempotencyKey ? strtoupper($idempotencyKey) : null;

        return DB::transaction(function () use ($originLocationId, $destinationLocationId, $stockCondition, $productsData, $userId, $idempotencyKey) {
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
                'stock_condition'         => $stockCondition,
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
     * Submit a DRAFT transfer to PENDING status. Requires a valid destination
     * to already be persisted on the transfer; the caller (TransferDraftService)
     * is responsible for authoritatively revalidating destination, products,
     * stock, conversions, and serials before invoking this boundary.
     */
    public function submitDraft(Transfer $transfer, int $userId): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            if ($transfer->status !== Transfer::STATUS_DRAFT) {
                throw new RuntimeException('Only DRAFT transfers can be submitted.');
            }

            if ($transfer->destination_location_id === null) {
                throw new RuntimeException('A destination location is required to submit a transfer for approval.');
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
     * Update a DRAFT or PENDING transfer, including its destination (only
     * meaningful while still in DRAFT; stock condition is always immutable
     * once a transfer is persisted). A no-op save (submitted state is
     * canonically identical to the persisted state) leaves status, revision,
     * lines, and history unchanged. A material edit to a PENDING transfer
     * atomically returns it to DRAFT so it must be explicitly resubmitted
     * before it can be approved.
     */
    public function updateTransfer(Transfer $transfer, ?int $destinationLocationId, ?string $stockCondition, array $productsData, int $userId): Transfer
    {
        return DB::transaction(function () use ($transfer, $destinationLocationId, $stockCondition, $productsData, $userId) {
            $transfer = $this->lockTransfer($transfer->id, $transfer->revision);

            if (! in_array($transfer->status, [Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING])) {
                throw new RuntimeException('Transfers can only be edited when in DRAFT or PENDING status.');
            }

            $isMaterial = $this->isMaterialChange($transfer, $destinationLocationId, $productsData);

            if (! $isMaterial) {
                return $transfer;
            }

            $fromStatus = $transfer->status;

            $this->syncProducts($transfer, $productsData);

            $attributes = ['revision' => $transfer->revision + 1];

            if ($transfer->status === Transfer::STATUS_DRAFT) {
                $attributes['destination_location_id'] = $destinationLocationId;
                $attributes['stock_condition'] = $stockCondition;
            } elseif ($transfer->status === Transfer::STATUS_PENDING) {
                $attributes['status'] = Transfer::STATUS_DRAFT;
            }

            $transfer->update($attributes);

            $this->recordHistory($transfer, TransferActionHistory::ACTION_EDITED, $fromStatus, $transfer->status, $userId, 'Transfer products updated');

            return $transfer;
        });
    }

    /**
     * Canonically compare the submitted destination/product state against
     * the persisted transfer, ignoring presentation-only ordering. Serial
     * selections and product rows are sorted before comparison so harmless
     * reordering is never mistaken for a material change.
     */
    private function isMaterialChange(Transfer $transfer, ?int $destinationLocationId, array $productsData): bool
    {
        if ((int) $transfer->destination_location_id !== (int) $destinationLocationId) {
            return true;
        }

        return $this->normalizeProductsData($productsData) !== $this->normalizePersistedProducts($transfer);
    }

    /**
     * @return array<int, array>
     */
    private function normalizeProductsData(array $productsData): array
    {
        $normalized = [];

        foreach ($productsData as $productData) {
            $quantity = (int) ($productData['quantity'] ?? ($productData['total'] ?? 0));
            if ($quantity <= 0) {
                continue;
            }

            $quantities = $productData['quantities'] ?? [];
            $serialIds = collect($productData['serial_numbers'] ?? [])
                ->map(fn ($serial) => (int) ($serial['id'] ?? $serial))
                ->sort()
                ->values()
                ->all();

            $normalized[(int) ($productData['product_id'] ?? $productData['id'])] = [
                'quantity_tax'            => (int) ($quantities['quantity_tax'] ?? 0),
                'quantity_non_tax'        => (int) ($quantities['quantity_non_tax'] ?? 0),
                'quantity_broken_tax'     => (int) ($quantities['quantity_broken_tax'] ?? 0),
                'quantity_broken_non_tax' => (int) ($quantities['quantity_broken_non_tax'] ?? 0),
                'serial_numbers'          => $serialIds,
            ];
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<int, array>
     */
    private function normalizePersistedProducts(Transfer $transfer): array
    {
        $normalized = [];

        foreach ($transfer->products()->get() as $product) {
            $serialIds = collect($product->serial_numbers ?? [])
                ->map(fn ($serial) => (int) ($serial['id'] ?? $serial))
                ->sort()
                ->values()
                ->all();

            $normalized[(int) $product->product_id] = [
                'quantity_tax'            => (int) $product->quantity_tax,
                'quantity_non_tax'        => (int) $product->quantity_non_tax,
                'quantity_broken_tax'     => (int) $product->quantity_broken_tax,
                'quantity_broken_non_tax' => (int) $product->quantity_broken_non_tax,
                'serial_numbers'          => $serialIds,
            ];
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Lock a DRAFT transfer and hand it to the given callback for
     * authoritative revalidation and mutation, all inside one transaction.
     * The callback receives the locked transfer and must return the
     * synchronized products data; this method then persists destination/mode/
     * products and transitions the transfer to PENDING atomically, so no
     * other process can observe or act on the transfer between validation
     * and the PENDING transition.
     *
     * @param callable(Transfer): array $revalidateAndBuildProducts
     */
    public function submitTransfer(Transfer $transfer, int $userId, callable $revalidateAndBuildProducts, int $destinationLocationId, ?string $stockCondition): Transfer
    {
        return DB::transaction(function () use ($transfer, $userId, $revalidateAndBuildProducts, $destinationLocationId, $stockCondition) {
            $locked = $this->lockTransfer($transfer->id, $transfer->revision);

            if ($locked->status !== Transfer::STATUS_DRAFT) {
                throw new RuntimeException('Only DRAFT transfers can be submitted for approval.');
            }

            // Revalidate against the locked, authoritative row (not the
            // caller's possibly-stale copy) before mutating anything.
            $productsData = $revalidateAndBuildProducts($locked);

            $this->syncProducts($locked, $productsData);

            $locked->update([
                'destination_location_id' => $destinationLocationId,
                'stock_condition'         => $stockCondition,
                'status'                  => Transfer::STATUS_PENDING,
                'revision'                => $locked->revision + 1,
            ]);

            $this->recordHistory($locked, TransferActionHistory::ACTION_SUBMITTED, Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING, $userId, 'Draft submitted for approval');

            return $locked;
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
    public function resubmit(Transfer $transfer, int $userId, int $currentSettingId, ?array $productsData = null, bool $destinationProvided = false, ?int $destinationLocationId = null, ?string $stockCondition = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $productsData, $userId, $currentSettingId, $destinationProvided, $destinationLocationId, $stockCondition) {
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

            $effectiveDestinationId = $destinationProvided ? $destinationLocationId : $transfer->destination_location_id;

            if ($effectiveDestinationId === null) {
                throw new RuntimeException('A destination location is required to submit a transfer for approval.');
            }

            $attributes = [
                'status'   => Transfer::STATUS_PENDING,
                'revision' => $transfer->revision + 1,
            ];

            if ($destinationProvided) {
                $attributes['destination_location_id'] = $destinationLocationId;
                $attributes['stock_condition'] = $stockCondition;
            }

            $transfer->update($attributes);

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

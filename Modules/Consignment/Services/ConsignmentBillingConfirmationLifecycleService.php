<?php

namespace Modules\Consignment\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentAllocationAuditLog;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSerializedAllocation;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Product\Entities\ProductSerialNumber;

class ConsignmentBillingConfirmationLifecycleService
{
    protected ConsignmentReturnEligibilityService $eligibilityService;
    protected ConsignmentReceiptAllocationService $receiptAllocationService;


    public function __construct(
        ConsignmentReturnEligibilityService $eligibilityService,
        ConsignmentReceiptAllocationService $receiptAllocationService
    ) {
        $this->eligibilityService = $eligibilityService;
        $this->receiptAllocationService = $receiptAllocationService;
    }

    /**
     * Create a new one-supplier confirmation draft.
     *
     * @param int $settingId
     * @param int $supplierId
     * @param string $date
     * @param array $linesData Array of line data with sold_source_id, product_id, location_id, allocated_qty, receipt_allocations, serial_allocations
     * @param string|null $notes
     * @param int|null $userId
     * @return ConsignmentBillingConfirmation
     */
    public function createDraft(
        int $settingId,
        int $supplierId,
        string $date,
        array $linesData,
        ?string $notes = null,
        ?int $userId = null
    ): ConsignmentBillingConfirmation {
        // Suppliers are shared master data across settings; only active status is enforced.
        $supplier = \Modules\People\Entities\Supplier::find($supplierId);
        if (!$supplier) {
            throw new InvalidArgumentException("Supplier is not available.");
        }
        if (isset($supplier->is_active) && !$supplier->is_active) {
            throw new InvalidArgumentException("Supplier #{$supplier->id} is inactive.");
        }

        return DB::transaction(function () use ($settingId, $supplierId, $date, $linesData, $notes, $userId) {
            $confirmationNumber = $this->generateConfirmationNumber($settingId);

            $confirmation = ConsignmentBillingConfirmation::create([
                'setting_id' => $settingId,
                'supplier_id' => $supplierId,
                'confirmation_number' => $confirmationNumber,
                'status' => ConsignmentBillingConfirmation::STATUS_DRAFT,
                'date' => $date,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $this->saveLinesAndAllocations($confirmation, $linesData);

            ConsignmentAllocationAuditLog::create([
                'consignment_billing_confirmation_id' => $confirmation->id,
                'action' => ConsignmentAllocationAuditLog::ACTION_DRAFT_CREATED,
                'actor_id' => $userId,
                'reason' => 'Draft created.',
                'snapshot' => $this->buildAuditSnapshot($confirmation),
            ]);

            return $confirmation;
        });
    }

    /**
     * Update an existing draft or rejected confirmation.
     *
     * The submitted payload describes only the sources the user could actually see
     * (one filtered/paginated page), never the whole draft. Passing the set of
     * visible sold-source ids lets this method distinguish:
     *
     *   - hidden saved lines      -> outside $visibleSoldSourceIds, left untouched
     *   - visible updated lines   -> present in $linesData, rewritten
     *   - explicit deselections   -> visible but absent from $linesData, deleted
     *   - newly selected lines    -> present in $linesData with no saved counterpart
     *
     * Reconciliation is keyed by consignment_sold_source_id, never by row index,
     * because pagination reuses indexes across pages.
     *
     * @param int[]|null $visibleSoldSourceIds Sources rendered on the submitted page.
     *                                         Null means the payload is authoritative
     *                                         for the entire draft (legacy behaviour).
     */
    public function updateDraft(
        ConsignmentBillingConfirmation $confirmation,
        array $linesData,
        ?string $notes = null,
        ?int $userId = null,
        ?array $visibleSoldSourceIds = null
    ): ConsignmentBillingConfirmation {
        return DB::transaction(function () use ($confirmation, $linesData, $notes, $userId, $visibleSoldSourceIds) {
            // Re-fetch with lock to prevent race with concurrent submit
            $header = ConsignmentBillingConfirmation::where('id', $confirmation->id)->lockForUpdate()->firstOrFail();

            if (! $header->canEdit()) {
                throw new InvalidArgumentException("Only draft or rejected confirmations can be updated. Current status: {$header->status}.");
            }

            $header->update([
                'notes' => $notes,
                'status' => ConsignmentBillingConfirmation::STATUS_DRAFT,
            ]);

            $submittedSourceIds = collect($linesData)
                ->pluck('consignment_sold_source_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($visibleSoldSourceIds === null) {
                // No visibility scope declared: the payload is the complete draft.
                $header->lines()->delete();
            } else {
                // Delete only lines the user could actually see and did not resubmit
                // (explicit deselection), plus any visible line being rewritten.
                $deletableSourceIds = array_unique(array_merge(
                    array_map('intval', $visibleSoldSourceIds),
                    $submittedSourceIds
                ));

                if (! empty($deletableSourceIds)) {
                    // Delete per model so the line's deleting hook (which guards
                    // submitted/approved confirmations) runs. The hook reads the
                    // parent confirmation, so eager-load it: lazy loading is
                    // disabled in tests and would throw here.
                    $header->lines()
                        ->with('confirmation')
                        ->whereIn('consignment_sold_source_id', $deletableSourceIds)
                        ->get()
                        ->each(fn ($line) => $line->delete());
                }
            }

            $this->saveLinesAndAllocations($header, $linesData);

            ConsignmentAllocationAuditLog::create([
                'consignment_billing_confirmation_id' => $header->id,
                'action' => ConsignmentAllocationAuditLog::ACTION_DRAFT_UPDATED,
                'actor_id' => $userId,
                'reason' => 'Draft updated.',
                'snapshot' => $this->buildAuditSnapshot($header),
            ]);

            return $header->fresh(['lines.receiptAllocations', 'lines.serializedAllocations']);
        });
    }

    /**
     * Delete a draft confirmation.
     */
    public function deleteDraft(ConsignmentBillingConfirmation $confirmation): bool
    {
        return DB::transaction(function () use ($confirmation) {
            // Re-fetch with lock to prevent race with concurrent submit
            $header = ConsignmentBillingConfirmation::where('id', $confirmation->id)->lockForUpdate()->firstOrFail();

            if (! $header->isDraft()) {
                throw new InvalidArgumentException("Only draft confirmations can be deleted. Current status: {$header->status}.");
            }

            $header->lines()->delete();
            return $header->delete();
        });
    }

    /**
     * Submit confirmation transaction with deterministic lock ordering and reservation checks.
     */
    public function submitConfirmation(ConsignmentBillingConfirmation $confirmation, ?int $userId = null): ConsignmentBillingConfirmation
    {
        return DB::transaction(function () use ($confirmation, $userId) {
            // 1. Lock header
            $header = ConsignmentBillingConfirmation::where('id', $confirmation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($header->status, [ConsignmentBillingConfirmation::STATUS_DRAFT, ConsignmentBillingConfirmation::STATUS_REJECTED])) {
                throw new InvalidArgumentException("Confirmation status must be DRAFT or REJECTED to submit. Current: {$header->status}.");
            }

            // Collect IDs for deterministic locking via unlocked reads
            $lines = $header->lines()->with(['receiptAllocations', 'serializedAllocations'])->get();
            $soldSourceIds = $lines->pluck('consignment_sold_source_id')->unique()->sort()->values()->toArray();
            $crdIds = $lines->flatMap(fn ($l) => $l->receiptAllocations->pluck('consignment_receiving_detail_id'))->filter()->unique()->sort()->values()->toArray();
            $serialIds = $lines->flatMap(fn ($l) => $l->serializedAllocations->pluck('product_serial_number_id'))->filter()->unique()->sort()->values()->toArray();

            $soldSources = $this->acquireDeterministicLocks($soldSourceIds, $crdIds, $serialIds);

            // Aggregate requested quantities by sold source across lines
            $soldSourceTotals = [];
            foreach ($lines as $line) {
                $soldSourceTotals[$line->consignment_sold_source_id] = ($soldSourceTotals[$line->consignment_sold_source_id] ?? 0.0) + (float) $line->allocated_base_quantity;
            }

            foreach ($soldSourceTotals as $soldSourceId => $totalRequestedQty) {
                $soldSource = $soldSources->get($soldSourceId);
                if (! $soldSource) {
                    throw new Exception("Sold source #{$soldSourceId} not found.");
                }

                $eligibility = $this->eligibilityService->calculateSoldEligibility($soldSource, $header->id);
                if ($eligibility['has_conflict']) {
                    throw new Exception("Sold source #{$soldSource->id} conflict: {$eligibility['conflict_reason']}");
                }

                if ($totalRequestedQty > $eligibility['remaining_quantity'] + 0.0001) {
                    throw new Exception("Requested total quantity ({$totalRequestedQty}) exceeds available sold source capacity ({$eligibility['remaining_quantity']}).");
                }
            }

            // Revalidate sold-source snapshot hashes and live canonical hashes across lines
            foreach ($lines as $line) {
                $soldSource = $soldSources->get($line->consignment_sold_source_id);
                if ($soldSource) {
                    $expectedHash = $soldSource->source_hash;
                    $lineHash = $line->sold_source_snapshot['source_hash'] ?? null;
                    if (! empty($lineHash) && ! empty($expectedHash) && strcasecmp($lineHash, $expectedHash) !== 0) {
                        throw new Exception("Sold source #{$soldSource->id} snapshot hash mismatch (stale or altered source).");
                    }

                    $liveHash = $this->computeCanonicalLiveHash($soldSource, true);
                    if (! empty($expectedHash) && ! empty($liveHash) && strcasecmp($expectedHash, $liveHash) !== 0) {
                        throw new Exception("Sold source #{$soldSource->id} live hash mismatch (authoritative dispatch detail altered).");
                    }

                    // Enforce serialized allocation requirement
                    $expectedSerials = $soldSource->serial_identities ?? [];
                    if (! empty($expectedSerials)) {
                        $returnedSerialIds = $this->eligibilityService->getEffectiveReturnedSerialIds($soldSource->dispatch_detail_id);
                        
                        $allocatedCount = count($line->serializedAllocations);
                        
                        if (fmod((float) $line->allocated_base_quantity, 1) !== 0.0) {
                            throw new Exception("Sold source #{$soldSource->id} contains serialized items and requires an integral allocated quantity.");
                        }
                        
                        $requiredCount = (int) $line->allocated_base_quantity;
                        
                        if ($allocatedCount !== $requiredCount) {
                            throw new Exception("Sold source #{$soldSource->id} requires {$requiredCount} serialized allocation(s) for the requested quantity, but {$allocatedCount} were provided.");
                        }
                        
                        $allowedSerialIds = [];
                        foreach ($soldSource->serials as $s) {
                            if (!in_array($s->product_serial_number_id, $returnedSerialIds)) {
                                $allowedSerialIds[] = $s->product_serial_number_id;
                            }
                        }
                        
                        foreach ($line->serializedAllocations as $sa) {
                            if (!in_array($sa->product_serial_number_id, $allowedSerialIds)) {
                                throw new Exception("Sold source #{$soldSource->id} includes an invalid, returned, or unassociated serial ID {$sa->product_serial_number_id}.");
                            }
                        }
                    }
                }
            }

            // Revalidate aggregated receipt pool capacity across entire confirmation
            $receiptTotalsByDetail = [];
            foreach ($lines as $line) {
                foreach ($line->receiptAllocations as $ra) {
                    $crdId = $ra->consignment_receiving_detail_id;
                    $receiptTotalsByDetail[$crdId] = ($receiptTotalsByDetail[$crdId] ?? 0.0) + (float) $ra->allocated_base_quantity;
                }
            }

            foreach ($receiptTotalsByDetail as $crdId => $totalAllocQty) {
                $crd = ConsignmentReceivingDetail::with('consignmentReceiving.receival')->find($crdId);
                if (! $crd) {
                    throw new Exception("Receipt detail #{$crdId} not found.");
                }
                $receiving = $crd->consignmentReceiving;
                if (! $receiving || $receiving->status !== \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_APPROVED) {
                    throw new Exception("Receipt detail #{$crdId} receiving is not approved.");
                }
                if ($receiving->setting_id != $header->setting_id || $receiving->receival->supplier_id != $header->supplier_id) {
                    throw new Exception("Receipt detail #{$crdId} tenant/supplier mismatch.");
                }

                $otherAllocated = ConsignmentReceiptAllocation::where('consignment_receiving_detail_id', $crdId)
                    ->whereHas('line.confirmation', function ($q) use ($header) {
                        $q->where('id', '!=', $header->id)
                          ->whereIn('status', [ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL, ConsignmentBillingConfirmation::STATUS_APPROVED]);
                    })
                    ->sum('allocated_base_quantity');

                $capacity = (float) $crd->quantity_received - (float) $otherAllocated;
                if ($totalAllocQty > $capacity + 0.0001) {
                    throw new Exception("Requested total receipt allocation ({$totalAllocQty}) exceeds remaining capacity ({$capacity}) for receiving detail #{$crdId}.");
                }
            }

            // Revalidate each line's receipt allocations & serialized claims
            foreach ($lines as $line) {
                $requestedQty = (float) $line->allocated_base_quantity;

                $allocData = [];
                foreach ($line->receiptAllocations as $ra) {
                    $allocData[] = [
                        'consignment_receiving_detail_id' => $ra->consignment_receiving_detail_id,
                        'allocated_base_quantity' => (float) $ra->allocated_base_quantity,
                    ];
                }

                $valResult = $this->receiptAllocationService->validateReceiptAllocations(
                    $allocData,
                    $requestedQty,
                    $header->setting_id,
                    $header->supplier_id,
                    $line->product_id,
                    $line->location_id,
                    $header->id
                );

                if (! $valResult['is_valid']) {
                    throw new Exception("Receipt allocation validation failed: " . implode(' ', $valResult['errors']));
                }

                // Revalidate serialized claims
                foreach ($line->serializedAllocations as $sa) {
                    $serial = ProductSerialNumber::find($sa->product_serial_number_id);
                    if (! $serial) {
                        throw new Exception("Serial #{$sa->product_serial_number_id} not found.");
                    }

                    $lineage = $this->receiptAllocationService->resolveSerialLineage($serial, $header->setting_id, $line->location_id);
                    if ($lineage['has_blocker']) {
                        throw new Exception("Serial #{$serial->serial_number} lineage error: {$lineage['blocker_reason']}");
                    }

                    if ($lineage['supplier_id'] != $header->supplier_id) {
                        throw new Exception("Serial #{$serial->serial_number} belongs to supplier #{$lineage['supplier_id']}, expected confirmation supplier #{$header->supplier_id}.");
                    }

                    if ($lineage['consignment_receiving_detail_id'] != $sa->consignment_receiving_detail_id) {
                        throw new Exception("Serial #{$serial->serial_number} resolves to receiving detail #{$lineage['consignment_receiving_detail_id']}, but allocation claims #{$sa->consignment_receiving_detail_id}.");
                    }

                    // Reserve active claim
                    ConsignmentActiveSerialClaim::create([
                        'product_serial_number_id' => $serial->id,
                        'consignment_billing_confirmation_id' => $header->id,
                        'consignment_serialized_allocation_id' => $sa->id,
                    ]);

                    $sa->update(['status' => ConsignmentSerializedAllocation::STATUS_RESERVED]);
                }
            }

            // Move header to WAITING_APPROVAL
            $header->update([
                'status' => ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL,
                'submitted_by' => $userId,
                'submitted_at' => now(),
            ]);
            
            $header->load(['lines.receiptAllocations', 'lines.serializedAllocations']);

            ConsignmentAllocationAuditLog::create([
                'consignment_billing_confirmation_id' => $header->id,
                'action' => ConsignmentAllocationAuditLog::ACTION_SUBMITTED,
                'actor_id' => $userId,
                'reason' => 'Submitted for approval.',
                'snapshot' => [
                    'status' => 'WAITING_APPROVAL',
                    'lines' => $header->lines->toArray(),
                ],
            ]);

            return $header;
        });
    }

    /**
     * Transactional approval: converts reservations to immutable approved allocations with full revalidation under lock.
     */
    public function approveConfirmation(ConsignmentBillingConfirmation $confirmation, ?int $userId = null): ConsignmentBillingConfirmation
    {
        return DB::transaction(function () use ($confirmation, $userId) {
            $header = ConsignmentBillingConfirmation::where('id', $confirmation->id)->lockForUpdate()->firstOrFail();

            if (! $header->isWaitingApproval()) {
                throw new InvalidArgumentException("Only WAITING_APPROVAL confirmations can be approved. Current: {$header->status}.");
            }

            $lines = $header->lines()->with(['receiptAllocations', 'serializedAllocations'])->get();
            $soldSourceIds = $lines->pluck('consignment_sold_source_id')->unique()->sort()->values()->toArray();
            $crdIds = $lines->flatMap(fn ($l) => $l->receiptAllocations->pluck('consignment_receiving_detail_id'))->unique()->sort()->values()->toArray();
            $serialIds = $lines->flatMap(fn ($l) => $l->serializedAllocations->pluck('product_serial_number_id'))->unique()->sort()->values()->toArray();

            // Re-acquire locks in deterministic order
            $soldSources = $this->acquireDeterministicLocks($soldSourceIds, $crdIds, $serialIds);

            // 1. Revalidate aggregated sold-source capacity
            $soldSourceTotals = [];
            foreach ($lines as $line) {
                $soldSourceTotals[$line->consignment_sold_source_id] = ($soldSourceTotals[$line->consignment_sold_source_id] ?? 0.0) + (float) $line->allocated_base_quantity;
            }

            foreach ($soldSourceTotals as $soldSourceId => $totalRequestedQty) {
                $soldSource = $soldSources->get($soldSourceId);
                if (! $soldSource) {
                    throw new Exception("Approval failed: sold source #{$soldSourceId} not found.");
                }

                $eligibility = $this->eligibilityService->calculateSoldEligibility($soldSource, $header->id);
                if ($eligibility['has_conflict']) {
                    throw new Exception("Approval failed for sold source #{$soldSource->id}: {$eligibility['conflict_reason']}");
                }

                if ($totalRequestedQty > $eligibility['remaining_quantity'] + 0.0001) {
                    throw new Exception("Approval failed: requested quantity ({$totalRequestedQty}) exceeds sold source remaining capacity ({$eligibility['remaining_quantity']}).");
                }
            }

            // 1.5 Revalidate sold-source snapshot hashes, live canonical hashes, and exactly match receipt allocation totals
            foreach ($lines as $line) {
                $soldSource = $soldSources->get($line->consignment_sold_source_id);
                if ($soldSource) {
                    $expectedHash = $soldSource->source_hash;
                    $lineHash = $line->sold_source_snapshot['source_hash'] ?? null;
                    if (! empty($lineHash) && ! empty($expectedHash) && strcasecmp($lineHash, $expectedHash) !== 0) {
                        throw new Exception("Approval failed: sold source #{$soldSource->id} snapshot hash mismatch (stale or altered source).");
                    }

                    $liveHash = $this->computeCanonicalLiveHash($soldSource, true);
                    if (! empty($expectedHash) && ! empty($liveHash) && strcasecmp($expectedHash, $liveHash) !== 0) {
                        throw new Exception("Approval failed: sold source #{$soldSource->id} live hash mismatch (authoritative dispatch detail altered).");
                    }

                    // Enforce serialized allocation requirement
                    $expectedSerials = $soldSource->serial_identities ?? [];
                    if (! empty($expectedSerials)) {
                        $returnedSerialIds = $this->eligibilityService->getEffectiveReturnedSerialIds($soldSource->dispatch_detail_id);
                        
                        $allocatedCount = count($line->serializedAllocations);
                        
                        if (fmod((float) $line->allocated_base_quantity, 1) !== 0.0) {
                            throw new Exception("Approval failed: sold source #{$soldSource->id} contains serialized items and requires an integral allocated quantity.");
                        }
                        
                        $requiredCount = (int) $line->allocated_base_quantity;
                        
                        if ($allocatedCount !== $requiredCount) {
                            throw new Exception("Approval failed: sold source #{$soldSource->id} requires {$requiredCount} serialized allocation(s) for the requested quantity, but {$allocatedCount} were provided.");
                        }
                        
                        $allowedSerialIds = [];
                        foreach ($soldSource->serials as $s) {
                            if (!in_array($s->product_serial_number_id, $returnedSerialIds)) {
                                $allowedSerialIds[] = $s->product_serial_number_id;
                            }
                        }
                        
                        foreach ($line->serializedAllocations as $sa) {
                            if (!in_array($sa->product_serial_number_id, $allowedSerialIds)) {
                                throw new Exception("Approval failed: sold source #{$soldSource->id} includes an invalid, returned, or unassociated serial ID {$sa->product_serial_number_id}.");
                            }
                        }
                    }
                }
                
                // Exactly reconcile receipt allocation balances
                $requestedQty = (float) $line->allocated_base_quantity;
                $allocData = [];
                foreach ($line->receiptAllocations as $ra) {
                    $allocData[] = [
                        'consignment_receiving_detail_id' => $ra->consignment_receiving_detail_id,
                        'allocated_base_quantity' => (float) $ra->allocated_base_quantity,
                    ];
                }

                $valResult = $this->receiptAllocationService->validateReceiptAllocations(
                    $allocData,
                    $requestedQty,
                    $header->setting_id,
                    $header->supplier_id,
                    $line->product_id,
                    $line->location_id,
                    $header->id
                );

                if (! $valResult['is_valid']) {
                    throw new Exception("Approval failed: receipt allocation validation error: " . implode(' ', $valResult['errors']));
                }
            }

            // 2. Cross-line receipt pool capacity aggregation under lock
            $receiptTotalsByDetail = [];
            foreach ($lines as $line) {
                foreach ($line->receiptAllocations as $ra) {
                    $crdId = $ra->consignment_receiving_detail_id;
                    $receiptTotalsByDetail[$crdId] = ($receiptTotalsByDetail[$crdId] ?? 0.0) + (float) $ra->allocated_base_quantity;
                }
            }

            foreach ($receiptTotalsByDetail as $crdId => $totalAllocQty) {
                $crd = ConsignmentReceivingDetail::with('consignmentReceiving.receival')->find($crdId);
                if (! $crd) {
                    throw new Exception("Approval failed: receipt detail #{$crdId} not found.");
                }
                $receiving = $crd->consignmentReceiving;
                if (! $receiving || $receiving->status !== \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_APPROVED) {
                    throw new Exception("Approval failed: receipt detail #{$crdId} receiving is not approved.");
                }
                if ($receiving->setting_id != $header->setting_id || $receiving->receival->supplier_id != $header->supplier_id) {
                    throw new Exception("Approval failed: receipt detail #{$crdId} tenant/supplier mismatch.");
                }

                $otherAllocated = ConsignmentReceiptAllocation::where('consignment_receiving_detail_id', $crdId)
                    ->whereHas('line.confirmation', function ($q) use ($header) {
                        $q->where('id', '!=', $header->id)
                          ->whereIn('status', [ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL, ConsignmentBillingConfirmation::STATUS_APPROVED]);
                    })
                    ->sum('allocated_base_quantity');

                $capacity = (float) $crd->quantity_received - (float) $otherAllocated;
                if ($totalAllocQty > $capacity + 0.0001) {
                    throw new Exception("Approval failed: requested total receipt allocation ({$totalAllocQty}) exceeds remaining capacity ({$capacity}) for receiving detail #{$crdId}.");
                }
            }

            // 3. Revalidate serialized claims under lock
            foreach ($lines as $line) {
                foreach ($line->serializedAllocations as $sa) {
                    $serial = ProductSerialNumber::find($sa->product_serial_number_id);
                    if (! $serial) {
                        throw new Exception("Approval failed: serial #{$sa->product_serial_number_id} not found.");
                    }

                    $lineage = $this->receiptAllocationService->resolveSerialLineage($serial, $header->setting_id, $line->location_id, $header->id);
                    if ($lineage['has_blocker']) {
                        throw new Exception("Approval failed for serial #{$serial->serial_number}: {$lineage['blocker_reason']}");
                    }

                    if ($lineage['supplier_id'] != $header->supplier_id) {
                        throw new Exception("Approval failed: serial #{$serial->serial_number} belongs to supplier #{$lineage['supplier_id']}, expected supplier #{$header->supplier_id}.");
                    }

                    if ($lineage['consignment_receiving_detail_id'] != $sa->consignment_receiving_detail_id) {
                        throw new Exception("Approval failed: serial #{$serial->serial_number} resolves to receiving detail #{$lineage['consignment_receiving_detail_id']}, but allocation claims #{$sa->consignment_receiving_detail_id}.");
                    }

                    $sa->update(['status' => ConsignmentSerializedAllocation::STATUS_APPROVED]);
                }
            }

            // Update status to APPROVED
            $header->update([
                'status' => ConsignmentBillingConfirmation::STATUS_APPROVED,
                'is_ready_for_billing' => true,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            ConsignmentAllocationAuditLog::create([
                'consignment_billing_confirmation_id' => $header->id,
                'action' => ConsignmentAllocationAuditLog::ACTION_APPROVED,
                'actor_id' => $userId,
                'reason' => 'Confirmation approved and marked ready for billing.',
                'snapshot' => $this->buildAuditSnapshot($header),
            ]);

            return $header;
        });
    }

    /**
     * Transactional rejection with required reason, releasing reservations.
     */
    public function rejectConfirmation(ConsignmentBillingConfirmation $confirmation, string $rejectionReason, ?int $userId = null): ConsignmentBillingConfirmation
    {
        if (empty(trim($rejectionReason))) {
            throw new InvalidArgumentException("Rejection reason is required.");
        }

        return DB::transaction(function () use ($confirmation, $rejectionReason, $userId) {
            $header = ConsignmentBillingConfirmation::where('id', $confirmation->id)->lockForUpdate()->firstOrFail();

            if (! $header->isWaitingApproval()) {
                throw new InvalidArgumentException("Only WAITING_APPROVAL confirmations can be rejected. Current: {$header->status}.");
            }

            // Release active serial claims
            ConsignmentActiveSerialClaim::where('consignment_billing_confirmation_id', $header->id)->delete();

            // Release serialized allocations
            ConsignmentSerializedAllocation::where('consignment_billing_confirmation_id', $header->id)
                ->update(['status' => ConsignmentSerializedAllocation::STATUS_RELEASED]);

            $header->update([
                'status' => ConsignmentBillingConfirmation::STATUS_REJECTED,
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            $header->load(['lines.receiptAllocations', 'lines.serializedAllocations']);

            ConsignmentAllocationAuditLog::create([
                'consignment_billing_confirmation_id' => $header->id,
                'action' => ConsignmentAllocationAuditLog::ACTION_REJECTED,
                'actor_id' => $userId,
                'reason' => $rejectionReason,
                'snapshot' => [
                    'status' => 'REJECTED',
                    'lines' => $header->lines->toArray(),
                ],
            ]);

            return $header;
        });
    }

    /**
     * Acquire locks deterministically for lifecycle boundaries.
     * Order matches existing return/dispatch patterns:
     * SaleReturn/PosReturn -> Location -> PosCheckoutSale -> Dispatch -> ConsignmentReceiving
     */
    protected function acquireDeterministicLocks(array $soldSourceIds, array $crdIds, array $serialIds = [])
    {
        $unlockedSources = ConsignmentSoldSource::whereIn('id', $soldSourceIds)->with('dispatchDetail')->get();
        $dispatchDetailIds = $unlockedSources->pluck('dispatch_detail_id')->filter()->unique()->sort()->values()->toArray();
        $locationIds = $unlockedSources->pluck('dispatchDetail.location_id')->filter()->unique()->sort()->values()->toArray();
        $saleIds = $unlockedSources->pluck('dispatchDetail.sale_id')->filter()->unique()->sort()->values()->toArray();

        $dispatchIds = [];
        if (!empty($dispatchDetailIds)) {
            $dispatchIds = \Modules\Sale\Entities\DispatchDetail::whereIn('id', $dispatchDetailIds)->pluck('dispatch_id')->filter()->unique()->sort()->values()->toArray();
        }

        $saleReturnIds = [];
        if (class_exists(\Modules\SalesReturn\Entities\SaleReturnDetail::class) && !empty($dispatchDetailIds)) {
            $saleReturnIds = \Modules\SalesReturn\Entities\SaleReturnDetail::whereIn('dispatch_detail_id', $dispatchDetailIds)->pluck('sale_return_id')->filter()->unique()->sort()->values()->toArray();
        }

        $posReturnIds = [];
        if (class_exists(\Modules\Pos\Entities\PosReturnLine::class) && !empty($dispatchDetailIds)) {
            $posReturnIds = \Modules\Pos\Entities\PosReturnLine::whereIn('dispatch_detail_id', $dispatchDetailIds)->pluck('pos_return_id')->filter()->unique()->sort()->values()->toArray();
        }

        $receivingIds = [];
        if (!empty($crdIds)) {
            $receivingIds = ConsignmentReceivingDetail::whereIn('id', $crdIds)->pluck('consignment_receiving_id')->filter()->unique()->sort()->values()->toArray();
        }

        // Lock parents first in deterministic return-first order
        if (!empty($posReturnIds) && class_exists(\Modules\Pos\Entities\PosReturn::class)) \Modules\Pos\Entities\PosReturn::whereIn('id', $posReturnIds)->orderBy('id')->lockForUpdate()->get();
        if (!empty($saleReturnIds) && class_exists(\Modules\SalesReturn\Entities\SaleReturn::class)) \Modules\SalesReturn\Entities\SaleReturn::whereIn('id', $saleReturnIds)->orderBy('id')->lockForUpdate()->get();
        if (!empty($locationIds)) \Modules\Setting\Entities\Location::whereIn('id', $locationIds)->orderBy('id')->lockForUpdate()->get();
        
        if (!empty($saleIds) && class_exists(\Modules\Pos\Entities\PosCheckoutSale::class)) {
            \Modules\Pos\Entities\PosCheckoutSale::whereIn('sale_id', $saleIds)->orderBy('sale_id')->lockForUpdate()->get();
        }

        if (!empty($dispatchIds)) \Modules\Sale\Entities\Dispatch::whereIn('id', $dispatchIds)->orderBy('id')->lockForUpdate()->get();
        if (!empty($receivingIds)) \Modules\Consignment\Entities\ConsignmentReceiving::whereIn('id', $receivingIds)->orderBy('id')->lockForUpdate()->get();

        // Lock details
        if (!empty($dispatchDetailIds) && class_exists(\Modules\SalesReturn\Entities\SaleReturnDetail::class)) \Modules\SalesReturn\Entities\SaleReturnDetail::whereIn('dispatch_detail_id', $dispatchDetailIds)->orderBy('id')->lockForUpdate()->get();
        if (!empty($dispatchDetailIds) && class_exists(\Modules\Pos\Entities\PosReturnLine::class)) \Modules\Pos\Entities\PosReturnLine::whereIn('dispatch_detail_id', $dispatchDetailIds)->orderBy('id')->lockForUpdate()->get();
        $lockedDetails = collect();
        if (!empty($dispatchDetailIds)) $lockedDetails = \Modules\Sale\Entities\DispatchDetail::whereIn('id', $dispatchDetailIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if (!empty($crdIds)) ConsignmentReceivingDetail::whereIn('id', $crdIds)->orderBy('id')->lockForUpdate()->get();

        // Lock Products in deterministic ID order BEFORE ProductSerialNumber rows, matching
        // the Product -> ProductSerialNumber order used by Consignment physical receiving.
        // Acquiring them one-by-one during revalidation would both break ID ordering and
        // invert that hierarchy, which can deadlock against a concurrent receiving.
        $productIds = $lockedDetails->pluck('product_id')->filter()->unique()->sort()->values()->toArray();

        $lockedProducts = ! empty($productIds)
            ? \Modules\Product\Entities\Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();

        // Carry the locked Product on its locked DispatchDetail so revalidation consumes
        // authority scoped to THIS transaction, rather than mutable service state that
        // could outlive the transaction that acquired the locks.
        foreach ($lockedDetails as $lockedDetail) {
            $lockedDetail->setRelation('product', $lockedProducts->get((int) $lockedDetail->product_id));
        }

        // Finally lock the exact consignment targets
        $soldSources = ConsignmentSoldSource::whereIn('id', $soldSourceIds)->with(['serials', 'dispatchDetail'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if (!empty($serialIds)) ProductSerialNumber::whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get();

        // Replace each sold source's eager-loaded detail with the locked instance.
        foreach ($soldSources as $soldSource) {
            $lockedDetail = $lockedDetails->get((int) $soldSource->dispatch_detail_id);
            if ($lockedDetail) {
                $soldSource->setRelation('dispatchDetail', $lockedDetail);
            }
        }

        return $soldSources;
    }

    /**
     * Helper to save lines, receipt allocations, and serialized allocations with tenant scope validation.
     */
    protected function saveLinesAndAllocations(ConsignmentBillingConfirmation $confirmation, array $linesData): void
    {
        foreach ($linesData as $lineData) {
            $soldSource = ConsignmentSoldSource::findOrFail($lineData['consignment_sold_source_id']);

            if ($soldSource->setting_id != $confirmation->setting_id) {
                throw new InvalidArgumentException("Sold source #{$soldSource->id} does not belong to confirmation setting #{$confirmation->setting_id}.");
            }

            $snapshot = $soldSource->source_snapshot ?? [];
            if (empty($snapshot['source_hash']) && ! empty($soldSource->source_hash)) {
                $snapshot['source_hash'] = $soldSource->source_hash;
            }

            $line = ConsignmentBillingConfirmationLine::create([
                'consignment_billing_confirmation_id' => $confirmation->id,
                'consignment_sold_source_id' => $soldSource->id,
                'product_id' => $soldSource->product_id,
                'location_id' => $soldSource->location_id,
                'allocated_base_quantity' => $lineData['allocated_base_quantity'],
                'sold_source_snapshot' => $snapshot,
            ]);

            // Save receipt allocations
            if (! empty($lineData['receipt_allocations'])) {
                foreach ($lineData['receipt_allocations'] as $raData) {
                    $crd = ConsignmentReceivingDetail::with('consignmentReceiving.receival')->findOrFail($raData['consignment_receiving_detail_id']);

                    if ($crd->consignmentReceiving->setting_id != $confirmation->setting_id ||
                        $crd->consignmentReceiving->receival->supplier_id != $confirmation->supplier_id) {
                        throw new InvalidArgumentException("Receipt detail #{$crd->id} does not match confirmation setting and supplier.");
                    }

                    $allocQty = (float) $raData['allocated_base_quantity'];
                    $taxRate = (float) ($crd->tax_rate ?? 0);
                    $unitPrice = (float) ($crd->unit_dpp > 0 ? $crd->unit_dpp : $crd->unit_cost);
                    $calculatedTaxAmount = round($unitPrice * $allocQty * ($taxRate / 100.0), 2);

                    ConsignmentReceiptAllocation::create([
                        'consignment_billing_confirmation_line_id' => $line->id,
                        'consignment_receiving_detail_id' => $crd->id,
                        'allocated_base_quantity' => $allocQty,
                        'unit_cost' => $crd->unit_cost,
                        'unit_dpp' => $crd->unit_dpp,
                        'tax_id' => $crd->tax_id,
                        'tax_rate' => $crd->tax_rate,
                        'tax_amount' => $calculatedTaxAmount,
                        'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
                        'receival_reference' => $crd->consignmentReceiving->receival->receival_number ?? null,
                        'receiving_reference' => $crd->consignmentReceiving->receiving_number ?? null,
                        'receiving_detail_snapshot' => [
                            'unit_cost' => $crd->unit_cost,
                            'unit_dpp' => $crd->unit_dpp,
                            'tax_id' => $crd->tax_id,
                            'tax_rate' => $crd->tax_rate,
                            'tax_snapshot_version' => ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_PROPORTIONAL,
                        ],
                    ]);
                }
            }

            // Save serialized allocations
            if (! empty($lineData['serialized_allocations'])) {
                foreach ($lineData['serialized_allocations'] as $saData) {
                    ConsignmentSerializedAllocation::create([
                        'consignment_billing_confirmation_id' => $confirmation->id,
                        'consignment_billing_confirmation_line_id' => $line->id,
                        'consignment_sold_source_id' => $soldSource->id,
                        'product_serial_number_id' => $saData['product_serial_number_id'],
                        'consignment_receiving_detail_id' => $saData['consignment_receiving_detail_id'],
                        'status' => ConsignmentSerializedAllocation::STATUS_RESERVED,
                    ]);
                }
            }
        }
    }

    /**
     * Generate sequential confirmation number per setting with concurrency lock.
     */
    protected function generateConfirmationNumber(int $settingId): string
    {
        // Lock setting row to serialize sequence generation per setting
        \Modules\Setting\Entities\Setting::where('id', $settingId)->lockForUpdate()->firstOrFail();

        $prefix = "CBC-" . date('Ym') . "-";
        $latest = ConsignmentBillingConfirmation::where('setting_id', $settingId)
            ->where('confirmation_number', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->first();

        $seq = $latest ? ((int) substr($latest->confirmation_number, -4) + 1) : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Compute canonical live hash from underlying dispatch detail.
     *
     * @param  bool  $requireLockedProduct  Pass true from lifecycle paths that hold the
     *   deterministic locks: the authoritative Product must then already be injected on
     *   the sold source's DispatchDetail, and a missing one fails closed rather than
     *   falling back to an unlocked read. Read-only callers leave this false.
     */
    public function computeCanonicalLiveHash(ConsignmentSoldSource $soldSource, bool $requireLockedProduct = false): string
    {
        $detail = $soldSource->dispatchDetail;
        if (! $detail) {
            return $soldSource->source_hash ?? '';
        }
        $detail->loadMissing(['dispatch', 'location']);

        $posCashReturnedQty = 0.0;
        if (class_exists(\Modules\Pos\Entities\PosReturnLine::class)) {
            $posCashReturnedQty = (float) \Modules\Pos\Entities\PosReturnLine::where('dispatch_detail_id', $detail->id)
                ->where('resolution', \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN)
                ->where(function ($q) {
                    $q->whereHas('posReturn', function ($pq) {
                        $pq->where('status', 'completed');
                    })->orWhereHas('saleReturn', function ($sq) {
                        $sq->whereIn('status', ['Completed', 'Awaiting Settlement']);
                    });
                })
                ->sum('quantity');
        }

        $originalBaseQty = (float) $detail->dispatched_quantity + $posCashReturnedQty;
        $dispatchedAt = $detail->dispatch->approved_at ?? $detail->dispatch->created_at ?? $detail->created_at;
        $dispatchedAtStr = $dispatchedAt ? \Carbon\Carbon::parse($dispatchedAt)->format('Y-m-d H:i:s') : null;
        
        $rawSerials = $detail->serial_numbers;
        $serialIdentities = [];
        if (!empty($rawSerials)) {
            if (is_array($rawSerials)) {
                $serialIdentities = array_map('trim', array_filter($rawSerials));
            } elseif (is_string($rawSerials)) {
                $decoded = json_decode($rawSerials, true);
                if (is_array($decoded)) {
                    $serialIdentities = array_map('trim', array_filter($decoded));
                } else {
                    $serialIdentities = array_map('trim', array_filter(explode(',', $rawSerials)));
                }
            }
        }
        sort($serialIdentities);

        $posCheckoutId = null;
        if (class_exists(\Modules\Pos\Entities\PosCheckoutSale::class)) {
            $checkoutSale = \Modules\Pos\Entities\PosCheckoutSale::where('sale_id', $detail->sale_id)->first();
            if ($checkoutSale) {
                $posCheckoutId = $checkoutSale->pos_checkout_id;
            }
        }

        // The payload shape is selected from the stored snapshot version, never inferred
        // from the presence of a JSON key, so historical hashes keep validating exactly.
        $version = ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion($soldSource->source_snapshot);

        if ($version === null) {
            $stored = $soldSource->source_snapshot['snapshot_version'] ?? null;
            $rendered = is_scalar($stored) ? var_export($stored, true) : gettype($stored);
            throw new Exception("Sold source #{$soldSource->id} uses unsupported snapshot/hash version {$rendered}; upgrade the application or re-discover this source before continuing this operation.");
        }

        // Version 2 records how the row was classified, not just its persisted nullable
        // flag. A compatibility source stays eligible only while the live classifier still
        // reaches the same decision, so a product that later becomes non-stock-managed
        // blocks the lifecycle instead of hashing to the same null value as before.
        if ($version >= 2) {
            $snapshot = $soldSource->source_snapshot ?? [];

            // Version-2 evidence is mandatory, not best-effort: a snapshot missing these
            // keys (or carrying junk in them) cannot be revalidated, so it must block
            // rather than silently skip classification revalidation and fail open.
            if (! array_key_exists('inventory_classification', $snapshot)) {
                throw new Exception("Sold source #{$soldSource->id} has corrupted version-2 evidence: inventory_classification is missing; re-discover this source before continuing this operation.");
            }

            $storedClassification = $snapshot['inventory_classification'];
            if (! in_array($storedClassification, ConsignmentSoldSourceDiscoveryService::SUPPORTED_CLASSIFICATIONS, true)) {
                $rendered = is_scalar($storedClassification) ? var_export($storedClassification, true) : gettype($storedClassification);
                throw new Exception("Sold source #{$soldSource->id} has corrupted version-2 evidence: unknown inventory_classification {$rendered}; re-discover this source before continuing this operation.");
            }

            if (! array_key_exists('used_historical_compatibility_rule', $snapshot) || ! is_bool($snapshot['used_historical_compatibility_rule'])) {
                throw new Exception("Sold source #{$soldSource->id} has corrupted version-2 evidence: used_historical_compatibility_rule must be a boolean; re-discover this source before continuing this operation.");
            }

            // stock_managed is decisive for compatibility sources and can change
            // concurrently, so it must be read under lock. Consume the Product that
            // acquireDeterministicLocks() locked in ID order and injected onto this
            // detail; locking here would acquire rows out of order and after
            // ProductSerialNumber, inverting the receiving hierarchy.
            $productId = (int) ($detail->product_id ?? 0);
            $lockedProduct = null;

            if ($productId > 0) {
                if ($requireLockedProduct) {
                    // A lifecycle caller states that it holds the deterministic locks, so
                    // the authoritative Product must already be injected on this detail.
                    // Reading it here would take an unlocked row, or a lock out of
                    // hierarchy order, so fail closed rather than weaken the contract.
                    if (! $detail->relationLoaded('product') || $detail->getRelation('product') === null) {
                        throw new Exception("Sold source #{$soldSource->id} was revalidated without its locked Product; acquire deterministic locks before computing the canonical hash.");
                    }

                    $lockedProduct = $detail->getRelation('product');
                } else {
                    // Read-only computation: a plain read is the documented behavior.
                    $lockedProduct = $detail->relationLoaded('product')
                        ? $detail->getRelation('product')
                        : \Modules\Product\Entities\Product::whereKey($productId)->first();
                }
            }

            $location = $detail->location;
            $locationIsValid = $location
                && (int) $location->setting_id === (int) $soldSource->setting_id
                && $location->is_consignment
                && $location->is_active;

            $live = app(ConsignmentSoldSourceDiscoveryService::class)->classifyInventoryEvidence(
                $detail,
                $lockedProduct,
                $locationIsValid ? $location : null
            );

            if ($live['classification'] !== $storedClassification) {
                throw new Exception("Sold source #{$soldSource->id} inventory classification changed since capture ({$storedClassification} → {$live['classification']}); re-verify the authoritative dispatch detail before billing.");
            }

            if ($live['used_compatibility_rule'] !== $snapshot['used_historical_compatibility_rule']) {
                throw new Exception("Sold source #{$soldSource->id} historical compatibility evidence changed since capture; re-verify the authoritative dispatch detail before billing.");
            }
        }

        $hashPayload = ConsignmentSoldSourceDiscoveryService::buildCanonicalPayload($version, [
            'dispatch_detail_id' => $detail->id,
            'setting_id' => $soldSource->setting_id,
            'location_id' => $detail->location_id,
            'product_id' => $detail->product_id,
            'original_base_quantity' => (string) $originalBaseQty,
            'dispatched_at' => $dispatchedAtStr,
            'serials' => $serialIdentities,
            'dispatch_status' => $detail->dispatch->status ?? null,
            'is_consignment_location' => $detail->location->is_consignment ?? true,
            'pos_checkout_id' => $posCheckoutId,
            'tax_id' => $detail->tax_id,
            'bundle_id' => $detail->bundle_id,
            'is_inventory_managed' => $detail->getAttribute('is_inventory_managed') === null
                ? null
                : (bool) $detail->is_inventory_managed,
        ]);

        return hash('sha256', json_encode($hashPayload));
    }

    /**
     * Build a consistent full-body audit snapshot for any lifecycle action.
     * Includes status and all line bodies with receipt allocations and serialized allocations.
     */
    private function buildAuditSnapshot(ConsignmentBillingConfirmation $confirmation): array
    {
        $confirmation->load(['lines.receiptAllocations', 'lines.serializedAllocations']);

        return [
            'status' => $confirmation->status,
            'lines' => $confirmation->lines->toArray(),
        ];
    }
}

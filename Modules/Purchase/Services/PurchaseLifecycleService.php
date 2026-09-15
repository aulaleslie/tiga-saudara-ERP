<?php

namespace Modules\Purchase\Services;

use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseStatusTransitionAudit;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Exceptions\PurchaseLifecycleTransitionException;

class PurchaseLifecycleService
{
    public const ACTION_SUBMIT_APPROVAL = 'SUBMIT_APPROVAL';
    public const ACTION_APPROVE = 'APPROVE';
    public const ACTION_REJECT = 'REJECT';
    public const ACTION_RESET_TO_DRAFT = 'RESET_TO_DRAFT';
    public const SOURCE_RECEIVING_APPROVAL = 'RECEIVING_APPROVAL';
    public const SOURCE_SHORTFALL_COMPLETION = 'SHORTFALL_COMPLETION';

    /**
     * Map of target statuses to user-driven lifecycle actions.
     */
    public const STATUS_TO_ACTION = [
        Purchase::STATUS_WAITING_APPROVAL => self::ACTION_SUBMIT_APPROVAL,
        Purchase::STATUS_APPROVED => self::ACTION_APPROVE,
        Purchase::STATUS_REJECTED => self::ACTION_REJECT,
        Purchase::STATUS_DRAFTED => self::ACTION_RESET_TO_DRAFT,
    ];

    /**
     * Permitted state transitions for user-driven actions:
     * Current Status => Allowed Target Statuses
     */
    protected const ALLOWED_USER_TRANSITIONS = [
        Purchase::STATUS_DRAFTED => [Purchase::STATUS_WAITING_APPROVAL],
        Purchase::STATUS_WAITING_APPROVAL => [Purchase::STATUS_APPROVED, Purchase::STATUS_REJECTED],
        Purchase::STATUS_REJECTED => [Purchase::STATUS_DRAFTED],
    ];

    public function __construct(
        protected DocumentNotificationService $notificationService
    ) {}

    /**
     * Transition a Purchase to a target status requested by a user.
     *
     * @param Purchase $purchase
     * @param string $targetStatus
     * @param string|null $reasonOrRejectionNote
     * @param User|null $actor
     * @return Purchase
     *
     * @throws PurchaseLifecycleTransitionException
     */
    public function transitionUserStatus(
        Purchase $purchase,
        string $targetStatus,
        ?string $reasonOrRejectionNote = null,
        ?User $actor = null
    ): Purchase {
        $actor = $actor ?? auth()->user();

        // 1. Validate that target status is a valid user-driven status
        if (!array_key_exists($targetStatus, self::STATUS_TO_ACTION)) {
            throw new PurchaseLifecycleTransitionException(
                'invalid_target_status',
                "Status {$targetStatus} tidak dapat diatur secara manual melalui aksi status pengguna."
            );
        }

        $action = self::STATUS_TO_ACTION[$targetStatus];

        // 2. Authorize action
        $this->authorizeAction($action, $actor);

        // 3. Execute in transaction with row lock
        return DB::transaction(function () use ($purchase, $targetStatus, $action, $reasonOrRejectionNote, $actor) {
            /** @var Purchase $lockedPurchase */
            $lockedPurchase = Purchase::query()
                ->where('id', $purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Guard against archived or consignment billing if needed
            PurchaseSourceGuard::assertCommercialEditAllowed($lockedPurchase);

            $oldStatus = $lockedPurchase->status;

            // Check if transition is valid
            $allowedTargets = self::ALLOWED_USER_TRANSITIONS[$oldStatus] ?? [];
            if (!in_array($targetStatus, $allowedTargets, true)) {
                // If the purchase has positive approved receiving evidence or is in received state
                $hasApprovedReceiving = $this->hasPositiveApprovedReceiving($lockedPurchase);
                if ($hasApprovedReceiving || in_array($oldStatus, [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY], true)) {
                    throw new PurchaseLifecycleTransitionException(
                        'stale_transition_after_receipt',
                        "Pembelian {$lockedPurchase->reference} sudah memiliki penerimaan barang yang disetujui ({$oldStatus}) dan tidak dapat diubah menjadi {$targetStatus}."
                    );
                }

                throw new PurchaseLifecycleTransitionException(
                    'invalid_transition',
                    "Perubahan status dari {$oldStatus} menjadi {$targetStatus} tidak diizinkan."
                );
            }

            // If target is APPROVED or moving to pre-approval, ensure no approved receiving evidence contradicts this
            if ($targetStatus === Purchase::STATUS_APPROVED && $this->hasPositiveApprovedReceiving($lockedPurchase)) {
                throw new PurchaseLifecycleTransitionException(
                    'stale_transition_after_receipt',
                    "Pembelian {$lockedPurchase->reference} sudah memiliki penerimaan barang yang disetujui dan tidak dapat diubah menjadi APPROVED."
                );
            }

            // Update Purchase
            $updateData = ['status' => $targetStatus];
            if ($targetStatus === Purchase::STATUS_REJECTED) {
                $updateData['rejection_note'] = $reasonOrRejectionNote;
            } elseif ($targetStatus === Purchase::STATUS_DRAFTED || $targetStatus === Purchase::STATUS_WAITING_APPROVAL) {
                // Optionally keep or clear rejection note, but let's keep it or leave as is
            }

            $lockedPurchase->update($updateData);

            // Persist immutable audit evidence
            $this->recordAudit(
                purchase: $lockedPurchase,
                oldStatus: $oldStatus,
                newStatus: $targetStatus,
                actionOrSource: $action,
                receivedNoteId: null,
                actorUserId: $actor?->id,
                reason: $reasonOrRejectionNote
            );

            // Trigger notifications
            $this->dispatchNotifications($lockedPurchase, $targetStatus, $reasonOrRejectionNote);

            return $lockedPurchase;
        });
    }

    /**
     * Record a receiving-derived or shortfall-completion transition audit.
     */
    public function recordDerivedTransition(
        Purchase $purchase,
        ?string $oldStatus,
        string $newStatus,
        string $source,
        ?int $receivedNoteId = null,
        ?int $actorUserId = null,
        ?string $reason = null
    ): PurchaseStatusTransitionAudit {
        return $this->recordAudit(
            purchase: $purchase,
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            actionOrSource: $source,
            receivedNoteId: $receivedNoteId,
            actorUserId: $actorUserId,
            reason: $reason
        );
    }

    /**
     * Check if purchase has approved receiving notes with positive received quantities.
     */
    public function hasPositiveApprovedReceiving(Purchase $purchase): bool
    {
        return DB::table('received_notes')
            ->join('received_note_details', 'received_notes.id', '=', 'received_note_details.received_note_id')
            ->where('received_notes.po_id', $purchase->id)
            ->where('received_notes.status', ReceivedNote::STATUS_APPROVED)
            ->where('received_note_details.quantity_received', '>', 0)
            ->exists();
    }

    protected function recordAudit(
        Purchase $purchase,
        ?string $oldStatus,
        string $newStatus,
        string $actionOrSource,
        ?int $receivedNoteId = null,
        ?int $actorUserId = null,
        ?string $reason = null
    ): PurchaseStatusTransitionAudit {
        return PurchaseStatusTransitionAudit::create([
            'setting_id' => $purchase->setting_id,
            'purchase_id' => $purchase->id,
            'received_note_id' => $receivedNoteId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'action_or_source' => $actionOrSource,
            'actor_user_id' => $actorUserId ?? auth()->id(),
            'reason' => $reason,
            'transitioned_at' => now(),
        ]);
    }

    protected function authorizeAction(string $action, ?User $actor): void
    {
        if ($actor && method_exists($actor, 'hasRole') && $actor->hasRole('Super Admin')) {
            return;
        }

        switch ($action) {
            case self::ACTION_SUBMIT_APPROVAL:
            case self::ACTION_RESET_TO_DRAFT:
                if (!Gate::forUser($actor)->check('purchases.update')) {
                    abort(403, 'Anda tidak memiliki izin untuk mengubah status draft/pengajuan pembelian.');
                }
                break;

            case self::ACTION_APPROVE:
            case self::ACTION_REJECT:
                if (!Gate::forUser($actor)->check('purchases.approval')) {
                    abort(403, 'Anda tidak memiliki izin untuk menyetujui atau menolak pembelian.');
                }
                break;

            default:
                abort(403, 'Aksi tidak diizinkan.');
        }
    }

    protected function dispatchNotifications(Purchase $purchase, string $targetStatus, ?string $reason): void
    {
        if ($targetStatus === Purchase::STATUS_WAITING_APPROVAL) {
            $this->notificationService->notifyApprovalNeeded($purchase, $purchase->reference, $purchase->setting_id);
            $this->notificationService->resolveRevision($purchase);
        } elseif ($targetStatus === Purchase::STATUS_REJECTED) {
            $this->notificationService->notifyRevisionNeeded($purchase, $purchase->reference, $purchase->setting_id, $reason ?? '');
            $this->notificationService->resolveApproval($purchase);
        } else {
            $this->notificationService->resolveApproval($purchase);
            $this->notificationService->resolveRevision($purchase);
        }
    }
}

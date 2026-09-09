<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Setting\Entities\Location;

/**
 * Submission, rejection, and approval-eligibility lifecycle for the
 * redesigned (normal versioned) Stock Opname workflow. Mirrors the
 * lock-then-transition pattern used by TransferLifecycleService.
 *
 * Actual inventory reconciliation and mutation on approval is implemented
 * separately (section 4); this service only guards and performs the
 * waiting_approval transition and exposes a guarded entry point that the
 * approval poster must go through.
 */
class StockOpnameLifecycleService
{
    /**
     * Idempotently submit an owned, non-empty DRAFT to waiting_approval.
     * Rejected documents must first be edited (which returns them to draft)
     * before they can be resubmitted; this method does not accept them
     * directly. Records submitter/time and returns the adjustment. If
     * already waiting_approval, returns it unchanged without re-notifying
     * (idempotent no-op).
     *
     * The active setting is captured once by the caller and revalidated
     * against the row-locked model inside the transaction, so a concurrent
     * setting/location change cannot slip past the initial check.
     *
     * @throws ValidationException
     */
    public function submit(Adjustment $adjustment, User $actor, ?int $activeSettingId = null): Adjustment
    {
        $activeSettingId ??= (int) session('setting_id');

        if (!$actor->can('adjustments.edit')) {
            throw ValidationException::withMessages([
                'permission' => ['Anda tidak memiliki izin untuk mengajukan proposal stock opname ini.'],
            ]);
        }

        app(AdjustmentOwnershipGuard::class)->assertOwned($adjustment, $activeSettingId);

        return DB::transaction(function () use ($adjustment, $actor, $activeSettingId) {
            /** @var Adjustment $locked */
            $locked = Adjustment::where('id', $adjustment->id)->lockForUpdate()->firstOrFail();

            app(AdjustmentOwnershipGuard::class)->assertOwned($locked, $activeSettingId);

            if (!$locked->isNormalVersioned()) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya proposal stock opname format baru yang dapat diajukan melalui alur ini.'],
                ]);
            }

            $status = AdjustmentStatus::normalize($locked->status);

            if ($status === AdjustmentStatus::WaitingApproval) {
                // Idempotent: already submitted, no-op.
                return $locked;
            }

            if ($status !== AdjustmentStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya dokumen berstatus draf yang dapat diajukan untuk persetujuan. Dokumen yang ditolak harus diubah dan disimpan terlebih dahulu.'],
                ]);
            }

            $rows = (array) ($locked->count_draft['rows'] ?? []);
            if (empty($rows)) {
                throw ValidationException::withMessages([
                    'count_draft' => ['Dokumen stock opname kosong tidak dapat diajukan. Tambahkan minimal satu produk sebelum mengajukan.'],
                ]);
            }

            $locked->update([
                'status' => AdjustmentStatus::WaitingApproval,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
            ]);

            $location = Location::find($locked->location_id);

            app(\App\Services\Notification\DocumentNotificationService::class)->notifyApprovalNeeded(
                $locked,
                $locked->reference ?? 'Stock Opname',
                (int) ($location?->setting_id ?? $activeSettingId),
                $locked->location_id
            );

            return $locked;
        });
    }

    /**
     * Idempotently reject an owned waiting_approval document with a
     * Bahasa Indonesia reason. Does not mutate inventory. If already
     * rejected, returns unchanged (idempotent no-op).
     *
     * The active setting is captured once by the caller and revalidated
     * against the row-locked model inside the transaction.
     *
     * @throws ValidationException
     */
    public function reject(Adjustment $adjustment, User $actor, string $reason, ?int $activeSettingId = null): Adjustment
    {
        $activeSettingId ??= (int) session('setting_id');

        if (!$actor->can('adjustments.approval')) {
            throw ValidationException::withMessages([
                'permission' => ['Anda tidak memiliki izin untuk menolak proposal stock opname ini.'],
            ]);
        }

        app(AdjustmentOwnershipGuard::class)->assertOwned($adjustment, $activeSettingId);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => ['Alasan penolakan wajib diisi.'],
            ]);
        }

        return DB::transaction(function () use ($adjustment, $actor, $reason, $activeSettingId) {
            /** @var Adjustment $locked */
            $locked = Adjustment::where('id', $adjustment->id)->lockForUpdate()->firstOrFail();

            app(AdjustmentOwnershipGuard::class)->assertOwned($locked, $activeSettingId);

            if (!$locked->isNormalVersioned()) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya proposal stock opname format baru yang dapat ditolak melalui alur ini.'],
                ]);
            }

            $status = AdjustmentStatus::normalize($locked->status);

            if ($status === AdjustmentStatus::Rejected) {
                // Idempotent: already rejected, no-op.
                return $locked;
            }

            if ($status !== AdjustmentStatus::WaitingApproval) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya dokumen berstatus menunggu persetujuan yang dapat ditolak.'],
                ]);
            }

            $locked->update([
                'status' => AdjustmentStatus::Rejected,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $location = Location::find($locked->location_id);
            $settingId = (int) ($location?->setting_id ?? $activeSettingId);

            app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($locked);
            app(\App\Services\Notification\DocumentNotificationService::class)->notifyRevisionNeeded(
                $locked,
                $locked->reference ?? 'Stock Opname',
                $settingId,
                $reason,
                $locked->location_id
            );

            return $locked;
        });
    }

    /**
     * Guard the approval entry point for redesigned Stock Opname documents:
     * verify actor permission, ownership, and that the document is an owned
     * waiting_approval normal versioned document. Does not itself perform
     * reconciliation or mutation (section 4); callers use this before
     * invoking the atomic approval poster.
     *
     * This check does NOT lock the adjustment row and is only a non-mutating
     * eligibility guard for section 3 (it currently only produces an "under
     * development" response and never mutates inventory or status, so a
     * benign TOCTOU race here cannot corrupt state). Task 4.1's atomic
     * approval poster MUST NOT rely on this check alone: it must lock and
     * reload the adjustment and repeat the authoritative status/ownership
     * check against that locked row inside its own transaction before
     * applying any inventory mutation.
     *
     * @throws ValidationException
     */
    public function assertApprovable(Adjustment $adjustment, User $actor, ?int $activeSettingId = null): void
    {
        $activeSettingId ??= (int) session('setting_id');

        if (!$actor->can('adjustments.approval')) {
            throw ValidationException::withMessages([
                'permission' => ['Anda tidak memiliki izin untuk menyetujui proposal stock opname ini.'],
            ]);
        }

        app(AdjustmentOwnershipGuard::class)->assertOwned($adjustment, $activeSettingId);

        if (!$adjustment->isNormalVersioned()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya proposal stock opname format baru yang menggunakan alur persetujuan ini.'],
            ]);
        }

        $status = AdjustmentStatus::normalize($adjustment->status);
        if ($status !== AdjustmentStatus::WaitingApproval) {
            throw ValidationException::withMessages([
                'status' => ['Hanya dokumen berstatus menunggu persetujuan yang dapat disetujui.'],
            ]);
        }
    }

    /**
     * Delete an owned, editable (draft or rejected) normal versioned Stock
     * Opname document. This method is scoped exclusively to the redesigned
     * (normal versioned) lifecycle: any document that is not normal
     * versioned — legacy pending, breakage, or otherwise — is explicitly
     * rejected rather than deleted, regardless of its status. Locks and
     * reloads the adjustment inside the deletion transaction, then repeats
     * the actor-permission/ownership/status checks against that
     * authoritative row immediately before deleting, so a document that was
     * concurrently submitted between the caller's initial load and this call
     * cannot be deleted.
     *
     * @throws ValidationException
     */
    public function deleteDraft(Adjustment $adjustment, User $actor, ?int $activeSettingId = null): void
    {
        $activeSettingId ??= (int) session('setting_id');

        if (!$actor->can('adjustments.delete')) {
            throw ValidationException::withMessages([
                'permission' => ['Anda tidak memiliki izin untuk menghapus proposal stock opname ini.'],
            ]);
        }

        app(AdjustmentOwnershipGuard::class)->assertOwned($adjustment, $activeSettingId);

        DB::transaction(function () use ($adjustment, $activeSettingId) {
            /** @var Adjustment $locked */
            $locked = Adjustment::where('id', $adjustment->id)->lockForUpdate()->firstOrFail();

            app(AdjustmentOwnershipGuard::class)->assertOwned($locked, $activeSettingId);

            if (!$locked->isNormalVersioned()) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya proposal stock opname format baru yang dapat dihapus melalui alur ini.'],
                ]);
            }

            if (!app(CountDraftService::class)->canEditAdjustment($locked)) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya penyesuaian normal berstatus draf atau ditolak yang dapat dihapus.'],
                ]);
            }

            $locked->delete();
        });
    }
}

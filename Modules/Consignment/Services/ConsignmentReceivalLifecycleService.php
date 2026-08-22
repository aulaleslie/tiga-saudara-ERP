<?php

namespace Modules\Consignment\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Setting\Entities\Setting;

class ConsignmentReceivalLifecycleService
{
    /**
     * Submit a draft or rejected receival for approval.
     */
    public function submit(ConsignmentReceival $receival, int $userId): ConsignmentReceival
    {
        return DB::transaction(function () use ($receival, $userId) {
            $lockedReceival = ConsignmentReceival::whereKey($receival->id)->lockForUpdate()->firstOrFail();

            if (!in_array($lockedReceival->status, [ConsignmentReceival::STATUS_DRAFT, ConsignmentReceival::STATUS_REJECTED], true)) {
                throw new Exception("Dokumen konsinyasi berstatus '{$lockedReceival->status}' tidak dapat diajukan.");
            }

            if ($lockedReceival->lines()->count() === 0) {
                throw new Exception("Dokumen konsinyasi harus memiliki minimal satu baris produk sebelum diajukan.");
            }

            $lockedReceival->update([
                'status' => ConsignmentReceival::STATUS_WAITING_APPROVAL,
                'submitted_by' => $userId,
                'submitted_at' => now(),
                'updated_by' => $userId,
            ]);

            $notificationService = app(\App\Services\Notification\DocumentNotificationService::class);
            $notificationService->notifyApprovalNeeded($lockedReceival, $lockedReceival->reference, $lockedReceival->setting_id);
            $notificationService->resolveRevision($lockedReceival);

            return $lockedReceival->fresh(['lines', 'supplier', 'setting']);
        });
    }

    /**
     * Approve a waiting_approval receival.
     */
    public function approve(ConsignmentReceival $receival, int $userId): ConsignmentReceival
    {
        return DB::transaction(function () use ($receival, $userId) {
            $lockedReceival = ConsignmentReceival::whereKey($receival->id)->lockForUpdate()->firstOrFail();

            if ($lockedReceival->status !== ConsignmentReceival::STATUS_WAITING_APPROVAL) {
                throw new Exception("Hanya dokumen berstatus 'Menunggu Persetujuan' yang dapat disetujui.");
            }

            $lockedReceival->update([
                'status' => ConsignmentReceival::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => now(),
                'updated_by' => $userId,
            ]);

            $notificationService = app(\App\Services\Notification\DocumentNotificationService::class);
            $notificationService->resolveApproval($lockedReceival);

            return $lockedReceival->fresh(['lines', 'supplier', 'setting']);
        });
    }

    /**
     * Reject a waiting_approval receival with a required reason.
     */
    public function reject(ConsignmentReceival $receival, int $userId, string $reason): ConsignmentReceival
    {
        $reason = trim($reason);
        if (empty($reason)) {
            throw new Exception("Alasan penolakan dokumen konsinyasi wajib diisi.");
        }

        return DB::transaction(function () use ($receival, $userId, $reason) {
            $lockedReceival = ConsignmentReceival::whereKey($receival->id)->lockForUpdate()->firstOrFail();

            if ($lockedReceival->status !== ConsignmentReceival::STATUS_WAITING_APPROVAL) {
                throw new Exception("Hanya dokumen berstatus 'Menunggu Persetujuan' yang dapat ditolak.");
            }

            $lockedReceival->update([
                'status' => ConsignmentReceival::STATUS_REJECTED,
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'updated_by' => $userId,
            ]);

            $notificationService = app(\App\Services\Notification\DocumentNotificationService::class);
            $notificationService->notifyRevisionNeeded($lockedReceival, $lockedReceival->reference, $lockedReceival->setting_id, $reason);
            $notificationService->resolveApproval($lockedReceival);

            return $lockedReceival->fresh(['lines', 'supplier', 'setting']);
        });
    }
}

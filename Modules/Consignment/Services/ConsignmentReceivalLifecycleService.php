<?php

namespace Modules\Consignment\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\People\Entities\Supplier;
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

    /**
     * Update a draft or rejected receival.
     */
    public function update(ConsignmentReceival $receival, array $data, array $normalizedLines, int $userId): ConsignmentReceival
    {
        return DB::transaction(function () use ($receival, $data, $normalizedLines, $userId) {
            $lockedReceival = ConsignmentReceival::whereKey($receival->id)->lockForUpdate()->firstOrFail();

            if (!$lockedReceival->canBeEdited()) {
                throw new Exception("Dokumen konsinyasi berstatus '{$lockedReceival->status}' tidak dapat diubah.");
            }

            $supplierId = (int) ($data['supplier_id'] ?? 0);
            $supplier = Supplier::where('id', $supplierId)
                ->where('setting_id', $lockedReceival->setting_id)
                ->first();

            if (!$supplier) {
                throw new Exception("Supplier tidak valid atau tidak terdaftar pada bisnis ini.");
            }

            $lockedReceival->update([
                'supplier_id' => $supplier->id,
                'date' => $data['date'],
                'supplier_delivery_reference' => $data['supplier_delivery_reference'] ?? null,
                'note' => $data['note'] ?? null,
                'updated_by' => $userId,
            ]);

            $lockedReceival->lines()->delete();

            foreach ($normalizedLines as $lineData) {
                $lineData['consignment_receival_id'] = $lockedReceival->id;
                ConsignmentReceivalLine::create($lineData);
            }

            return $lockedReceival->fresh(['lines', 'supplier', 'setting']);
        });
    }

    /**
     * Delete a draft receival with no receiving history.
     */
    public function delete(ConsignmentReceival $receival): void
    {
        DB::transaction(function () use ($receival) {
            $lockedReceival = ConsignmentReceival::whereKey($receival->id)->lockForUpdate()->firstOrFail();

            if (!$lockedReceival->canBeDeleted()) {
                throw new Exception("Hanya draf dokumen yang belum memiliki riwayat penerimaan yang dapat dihapus.");
            }

            $lockedReceival->lines()->delete();
            $lockedReceival->delete();
        });
    }
}

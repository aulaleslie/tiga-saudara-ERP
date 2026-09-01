<?php

namespace Modules\Expense\Services;

use Modules\Expense\Entities\Expense;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    /**
     * Verify that the expense belongs to the current active setting in session.
     * Throws 403 if there is a mismatch.
     *
     * @param Expense $expense
     * @return void
     */
    public function verifySettingOwnership(Expense $expense): void
    {
        $currentSettingId = session('setting_id');

        if (is_null($currentSettingId) || (int) $expense->setting_id !== (int) $currentSettingId) {
            Log::warning('Expense setting ownership mismatch', [
                'expense_id' => $expense->id,
                'expense_setting' => $expense->setting_id,
                'session_setting' => $currentSettingId,
                'user_id' => auth()->id()
            ]);

            abort(403, 'Anda tidak memiliki akses ke data ini.');
        }
    }

    /**
     * Save an expense from a structured payload.
     *
     * @param array $data
     * @param Expense|null $expense
     * @return Expense
     */
    public function saveExpense(array $data, ?Expense $expense = null): Expense
    {
        $settingId = $data['setting_id'] ?? session('setting_id');
        $isPkp = (bool) (Setting::query()->whereKey((int) $settingId)->value('is_pkp') ?? false);

        // Validate supplier belongs to current setting if provided
        $supplierId = $data['supplier_id'] ?? null;
        if ($supplierId) {
            $supplierId = (int) $supplierId;
            $supplierExists = \Modules\People\Entities\Supplier::where('id', $supplierId)
                ->where('setting_id', $settingId)
                ->exists();
            if (!$supplierExists) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier yang dipilih tidak valid untuk setting ini.']);
            }
        }

        $tagIds = $data['tag_ids'] ?? [];
        if (!is_array($tagIds)) {
            $tagIds = [];
        }

        if (!empty($tagIds)) {
            $validTagCount = \Spatie\Tags\Tag::whereIn('id', $tagIds)->count();
            if ($validTagCount !== count(array_unique($tagIds))) {
                throw ValidationException::withMessages(['tag_ids' => 'Satu atau lebih tag yang dipilih tidak valid.']);
            }
        }

        // Normalize details and calculate totals
        $normalizedDetails = [];
        $totalAmount = 0;

        $details = $data['details'] ?? [];
        if (empty($details)) {
            throw ValidationException::withMessages(['details' => 'Detail pengeluaran tidak boleh kosong.']);
        }

        $status = $data['status'] ?? Expense::STATUS_DRAFT;

        if (in_array($status, [Expense::STATUS_APPROVED, Expense::STATUS_REJECTED]) && \Illuminate\Support\Facades\Gate::denies('expenses.approval')) {
            abort(403, 'Anda tidak memiliki hak untuk menetapkan status ini.');
        }

        // When editing a rejected expense, return it to DRAFT
        if ($expense && $expense->status === Expense::STATUS_REJECTED) {
            $status = Expense::STATUS_DRAFT;
        }

        $result = DB::transaction(function () use ($data, $expense, $settingId, $details, $isPkp, $status, $supplierId, $tagIds) {
            if ($expense) {
                $this->verifySettingOwnership($expense);

                // Allow edit only for DRAFT or REJECTED
                if (in_array($expense->status, [Expense::STATUS_SUBMITTED, Expense::STATUS_APPROVED])) {
                    abort(403, 'Pengeluaran yang sudah diajukan atau disetujui tidak dapat diubah.');
                }
            }

            // Lock referenced tax rows for update
            $referencedTaxIds = array_values(array_unique(array_filter(
                array_map(fn ($r) => $isPkp ? (empty($r['tax_id'] ?? null) ? null : (int) $r['tax_id']) : null, $details),
                fn ($id) => !is_null($id)
            )));

            $taxRates = [];
            $activeTaxIds = [];
            if (!empty($referencedTaxIds)) {
                $taxModels = Tax::whereIn('id', $referencedTaxIds)->lockForUpdate()->get();
                foreach ($taxModels as $taxModel) {
                    $taxRates[$taxModel->id] = (float) $taxModel->value;
                    if ($taxModel->is_active) {
                        $activeTaxIds[] = $taxModel->id;
                    }
                }
            }

            // Lock existing detail rows for update if editing
            $persistedRowTaxes = [];
            if ($expense && $expense->exists) {
                $persistedRowTaxes = $expense->detailRows()
                    ->lockForUpdate()
                    ->whereNotNull('tax_id')
                    ->pluck('tax_id', 'id')
                    ->all();
            }

            // Validate details and compute totals inside transaction
            $isTaxIncluded = $isPkp ? (bool) ($data['is_tax_included'] ?? false) : false;
            $normalizedDetails = [];
            $totalAmount = 0;

            foreach ($details as $index => $row) {
                $amount = floatval($row['amount']);
                if ($amount <= 0) {
                    throw ValidationException::withMessages(["details.{$index}.amount" => 'Nominal harus lebih besar dari nol.']);
                }

                $taxId = $isPkp ? ($row['tax_id'] ?? null) : null;
                $taxId = empty($taxId) ? null : (int) $taxId;

                if ($taxId) {
                    $rowId = !empty($row['id']) ? (int) $row['id'] : null;
                    $isPersistedSameTax = $rowId && isset($persistedRowTaxes[$rowId]) && ((int) $persistedRowTaxes[$rowId] === $taxId);
                    $isActiveTax = in_array($taxId, $activeTaxIds, true);

                    if (!$isActiveTax && !$isPersistedSameTax) {
                        throw ValidationException::withMessages(["details.{$index}.tax_id" => 'Pajak yang dipilih tidak aktif atau tidak valid.']);
                    }
                }

                $normalizedDetails[] = [
                    'id' => $row['id'] ?? null,
                    'name' => $row['name'],
                    'amount' => $amount,
                    'tax_id' => $taxId,
                ];

                $totalAmount += $amount;

                if (!$isTaxIncluded && $taxId && isset($taxRates[$taxId])) {
                    $taxRate = $taxRates[$taxId];
                    if ($taxRate > 0) {
                        $totalAmount += ($amount * $taxRate) / 100;
                    }
                }
            }

            $detailsSummary = collect($normalizedDetails)->pluck('name')->implode(', ');

            if ($expense) {
                $expense->update([
                    'date' => $data['date'],
                    'category_id' => $data['category_id'],
                    'supplier_id' => $supplierId,
                    'amount' => $totalAmount,
                    'status' => $status,
                    'details' => $detailsSummary,
                    'is_tax_included' => $isTaxIncluded,
                ]);
            } else {
                try {
                    $expense = Expense::create([
                        'setting_id' => $settingId,
                        'date' => $data['date'],
                        'category_id' => $data['category_id'],
                        'supplier_id' => $supplierId,
                        'amount' => $totalAmount,
                        'status' => $status,
                        'details' => $detailsSummary,
                        'is_tax_included' => $isTaxIncluded,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'expenses_setting_id_reference_unique')) {
                        throw ValidationException::withMessages(['reference' => 'Terjadi konflik referensi saat menyimpan data. Silakan coba lagi.']);
                    }
                    throw $e;
                }
            }

            // Sync details
            $existingIds = $expense->detailRows()->pluck('id')->all();
            $retainedIds = [];

            foreach ($normalizedDetails as $detailData) {
                if (!empty($detailData['id']) && in_array($detailData['id'], $existingIds, true)) {
                    $updateData = Arr::only($detailData, ['name', 'tax_id', 'amount']);
                    if (isset($updateData['name'])) {
                        $updateData['name'] = mb_strtoupper(trim($updateData['name']), 'UTF-8');
                    }
                    $expense->detailRows()->whereKey($detailData['id'])->update($updateData);
                    $retainedIds[] = $detailData['id'];
                } else {
                    $newDetail = $expense->detailRows()->create(Arr::only($detailData, ['name', 'tax_id', 'amount']));
                    $retainedIds[] = $newDetail->id;
                }
            }

            $idsToDelete = array_diff($existingIds, $retainedIds);
            if (!empty($idsToDelete)) {
                $expense->detailRows()->whereIn('id', $idsToDelete)->delete();
            }

            // Handle removed attachments
            if (!empty($data['removed_attachment_ids'])) {
                $expense->media()->whereIn('id', $data['removed_attachment_ids'])->get()->each->delete();
            }

            // Handle new attachments
            if (!empty($data['files'])) {
                foreach ($data['files'] as $file) {
                    $expense->addMedia($file)->toMediaCollection('attachments');
                }
            }

            // Sync tags
            if (!empty($tagIds)) {
                $tags = \Spatie\Tags\Tag::whereIn('id', $tagIds)->get();
                $expense->syncTags($tags);
            } else {
                $expense->syncTags([]);
            }

            return $expense->load('detailRows', 'media', 'tags');
        });

        $notificationService = app(\App\Services\Notification\DocumentNotificationService::class);
        if ($result->status === Expense::STATUS_SUBMITTED) {
            $notificationService->notifyApprovalNeeded($result, $result->reference, $result->setting_id);
            $notificationService->resolveRevision($result);
        }

        return $result;
    }

    public function submit(Expense $expense): Expense
    {
        $this->verifySettingOwnership($expense);

        if (!in_array($expense->status, [Expense::STATUS_DRAFT, Expense::STATUS_REJECTED])) {
            abort(403, 'Hanya pengeluaran dengan status Draft atau Ditolak yang dapat diajukan.');
        }

        $expense->update(['status' => Expense::STATUS_SUBMITTED]);

        app(\App\Services\Notification\DocumentNotificationService::class)->notifyApprovalNeeded($expense, $expense->reference, $expense->setting_id);
        app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($expense);

        return $expense;
    }

    public function approve(Expense $expense): Expense
    {
        $this->verifySettingOwnership($expense);

        if ($expense->status !== Expense::STATUS_SUBMITTED) {
            abort(403, 'Hanya pengeluaran yang diajukan yang dapat disetujui.');
        }

        $expense->update(['status' => Expense::STATUS_APPROVED]);

        app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($expense);
        app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($expense);

        return $expense;
    }

    public function reject(Expense $expense, string $reason): Expense
    {
        $this->verifySettingOwnership($expense);

        if ($expense->status !== Expense::STATUS_SUBMITTED) {
            abort(403, 'Hanya pengeluaran yang diajukan yang dapat ditolak.');
        }

        if (empty(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
        }

        $expense->update([
            'status' => Expense::STATUS_REJECTED,
            'rejection_reason' => $reason
        ]);

        app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($expense);
        app(\App\Services\Notification\DocumentNotificationService::class)->notifyRevisionNeeded($expense, $expense->reference, $expense->setting_id, $reason);

        return $expense;
    }

    public function archive(Expense $expense, ?string $reason = null): Expense
    {
        $this->verifySettingOwnership($expense);

        if (!in_array($expense->status, [Expense::STATUS_SUBMITTED, Expense::STATUS_APPROVED])) {
            abort(403, 'Hanya pengeluaran yang diajukan atau disetujui yang dapat diarsipkan.');
        }

        if ($expense->status === Expense::STATUS_APPROVED && empty(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Alasan arsip wajib diisi untuk pengeluaran yang disetujui.']);
        }

        $expense->update([
            'archived_at' => now(),
            'archived_by' => auth()->id(),
            'archive_reason' => $reason
        ]);

        return $expense;
    }

    public function delete(Expense $expense): void
    {
        $this->verifySettingOwnership($expense);

        if (in_array($expense->status, [Expense::STATUS_SUBMITTED, Expense::STATUS_APPROVED])) {
            abort(403, 'Pengeluaran yang diajukan atau disetujui tidak dapat dihapus. Silakan gunakan fitur arsip.');
        }

        $expense->delete();
    }
}

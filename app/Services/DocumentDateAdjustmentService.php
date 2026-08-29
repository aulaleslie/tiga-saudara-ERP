<?php

namespace App\Services;

use App\DTOs\DateAdjustmentCommand;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\DueDateAudit;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\ReportingDateAudit;
use Modules\Sale\Entities\Sale;

class DateAdjustmentResult
{
    public function __construct(
        public Model $document,
        public ?ReportingDateAudit $reportingAudit = null,
        public ?DueDateAudit $dueDateAudit = null,
    ) {
    }
}

class DocumentDateAdjustmentService
{
    /**
     * Perform an atomic date adjustment for a Purchase or Sale model.
     *
     * @param Purchase|Sale $document
     * @param DateAdjustmentCommand $command
     * @param User $user
     * @return DateAdjustmentResult
     */
    public function adjustDates(Model $document, DateAdjustmentCommand $command, User $user, bool $authorize = true): DateAdjustmentResult
    {
        if (empty($command->reason)) {
            throw new \InvalidArgumentException('Alasan wajib diisi untuk penyesuaian tanggal.');
        }

        if ($command->reportingAction === 'keep' && $command->dueDateAction === 'keep') {
            throw new \InvalidArgumentException('Tidak ada perubahan tanggal yang diminta.');
        }

        return DB::transaction(function () use ($document, $command, $user, $authorize) {
            // Lock the document row for update
            $locked = $document::where('id', $document->id)->lockForUpdate()->firstOrFail();

            // Re-authorize inside transaction after acquiring row lock if requested
            if ($authorize) {
                if ($command->reportingAction !== 'keep') {
                    if (!Gate::forUser($user)->allows('overrideReportingDate', $locked)) {
                        throw new \Illuminate\Auth\Access\AuthorizationException('Tidak memiliki hak akses untuk mengubah tanggal pelaporan.');
                    }
                }

                if ($command->dueDateAction !== 'keep') {
                    if (!Gate::forUser($user)->allows('overrideDueDate', $locked)) {
                        throw new \Illuminate\Auth\Access\AuthorizationException('Tidak memiliki hak akses untuk mengubah tanggal jatuh tempo.');
                    }
                }
            }

            if ($locked instanceof Purchase) {
                if ($command->reportingAction !== 'keep') {
                    \Modules\Purchase\Services\PurchaseSourceGuard::assertReportingDateOverrideAllowed($locked);
                }
            }

            $reportingAudit = null;
            $dueDateAudit = null;
            $updates = [];
            $effectiveChanges = 0;

            // 1. Process Reporting Date Action
            if ($command->reportingAction === 'set') {
                if (empty($command->reportingDate)) {
                    throw new \InvalidArgumentException('Tanggal pelaporan wajib diisi jika ingin diubah.');
                }
                $targetReportingDate = Carbon::parse($command->reportingDate)->format('Y-m-d H:i:s');
                $currentReportingDate = $locked->reporting_date ? Carbon::parse($locked->reporting_date)->format('Y-m-d H:i:s') : null;

                if ($currentReportingDate !== $targetReportingDate) {
                    $updates['reporting_date'] = $targetReportingDate;
                    $priorOverride = $locked->reporting_date;

                    $reportingAudit = new ReportingDateAudit([
                        'auditable_type' => $locked::class,
                        'auditable_id' => $locked->id,
                        'setting_id' => $locked->setting_id,
                        'user_id' => $user->id,
                        'reason' => $command->reason,
                        'original_date' => $locked->date,
                        'prior_override' => $priorOverride,
                        'resulting_override' => $targetReportingDate,
                    ]);
                    $effectiveChanges++;
                }
            } elseif ($command->reportingAction === 'clear') {
                if ($locked->reporting_date !== null) {
                    $updates['reporting_date'] = null;
                    $priorOverride = $locked->reporting_date;

                    $reportingAudit = new ReportingDateAudit([
                        'auditable_type' => $locked::class,
                        'auditable_id' => $locked->id,
                        'setting_id' => $locked->setting_id,
                        'user_id' => $user->id,
                        'reason' => $command->reason,
                        'original_date' => $locked->date,
                        'prior_override' => $priorOverride,
                        'resulting_override' => null,
                    ]);
                    $effectiveChanges++;
                }
            }

            // 2. Process Due Date Action
            if ($command->dueDateAction === 'set') {
                if (empty($command->dueDate)) {
                    throw new \InvalidArgumentException('Tanggal jatuh tempo wajib diisi jika ingin diubah.');
                }
                $targetDueDate = Carbon::parse($command->dueDate)->format('Y-m-d H:i:s');
                $currentDueDate = $locked->due_date ? Carbon::parse($locked->due_date)->format('Y-m-d H:i:s') : null;

                if ($currentDueDate !== $targetDueDate) {
                    $updates['due_date'] = $targetDueDate;
                    $priorDueDate = $locked->due_date;

                    $dueDateAudit = new DueDateAudit([
                        'auditable_type' => $locked::class,
                        'auditable_id' => $locked->id,
                        'setting_id' => $locked->setting_id,
                        'user_id' => $user->id,
                        'reason' => $command->reason,
                        'prior_due_date' => $priorDueDate,
                        'resulting_due_date' => $targetDueDate,
                    ]);
                    $effectiveChanges++;
                }
            }

            if ($effectiveChanges === 0) {
                throw new \InvalidArgumentException('Nilai tanggal yang dimasukkan sama dengan nilai saat ini. Tidak ada perubahan.');
            }

            // Perform document updates
            $locked->update($updates);

            // Save audits
            if ($reportingAudit) {
                $reportingAudit->save();
            }
            if ($dueDateAudit) {
                $dueDateAudit->save();
            }

            return new DateAdjustmentResult(
                document: $locked->fresh(),
                reportingAudit: $reportingAudit,
                dueDateAudit: $dueDateAudit,
            );
        });
    }
}

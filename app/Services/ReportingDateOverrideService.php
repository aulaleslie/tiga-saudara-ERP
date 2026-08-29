<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\ReportingDateAudit;
use Modules\Sale\Entities\Sale;

class ReportingDateOverrideService
{
    /**
     * Set or replace a reporting-date override atomically.
     *
     * @param Purchase|Sale $document
     * @param Carbon|string|null $newDate The new reporting date (null to clear)
     * @param string $reason Required reason for the change
     * @param User $user The user making the change
     * @return ReportingDateAudit The created audit entry
     */
    public function setOverride(Model $document, $newDate, string $reason, User $user): ReportingDateAudit
    {
        $command = new \App\DTOs\DateAdjustmentCommand(
            reportingAction: $newDate !== null ? 'set' : 'clear',
            reportingDate: $newDate !== null ? (string) $newDate : null,
            dueDateAction: 'keep',
            dueDate: null,
            reason: $reason,
        );

        $service = app(DocumentDateAdjustmentService::class);
        $result = $service->adjustDates($document, $command, $user, authorize: true);

        return $result->reportingAudit;
    }

    /**
     * Clear a reporting-date override.
     *
     * @param Purchase|Sale $document
     * @param string $reason Required reason for the clear
     * @param User $user The user making the change
     * @return ReportingDateAudit The created audit entry
     */
    public function clearOverride(Model $document, string $reason, User $user): ReportingDateAudit
    {
        return $this->setOverride($document, null, $reason, $user);
    }

    /**
     * Get the effective date for a document (override if present, else original date).
     *
     * @param Purchase|Sale $document
     * @return Carbon|null
     */
    public function getEffectiveDate(Model $document): ?Carbon
    {
        if ($document->reporting_date) {
            return Carbon::parse($document->reporting_date);
        }
        return $document->date ? Carbon::parse($document->date) : null;
    }
}

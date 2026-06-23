<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

class ExpenseDetailsReportSnapshotService
{
    private const SESSION_KEY = 'expense_details_report_snapshot';

    public function createSnapshot(ExpenseDetailsReportFilterData $filter, int $resultCount): ExpenseDetailsReportSnapshot
    {
        $snapshot = new ExpenseDetailsReportSnapshot(
            snapshotKey: Str::uuid()->toString(),
            validatedFilterHash: $filter->hash(),
            generatedAt: now()->toIso8601String(),
            actorUserId: auth()->id() ?? 0,
            scopeSettingId: $filter->scopeSettingId,
            resultCount: $resultCount
        );

        $this->persist($snapshot);

        return $snapshot;
    }

    public function getLatestSnapshot(): ?ExpenseDetailsReportSnapshot
    {
        $data = session()->get(self::SESSION_KEY);
        if (!$data) {
            return null;
        }

        return ExpenseDetailsReportSnapshot::fromArray($data);
    }

    public function isValidForExport(ExpenseDetailsReportFilterData $currentFilter): bool
    {
        $snapshot = $this->getLatestSnapshot();
        
        if (!$snapshot) {
            return false;
        }

        return $snapshot->validatedFilterHash === $currentFilter->hash();
    }

    public function invalidate(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public function persist(ExpenseDetailsReportSnapshot $snapshot): void
    {
        session()->put(self::SESSION_KEY, $snapshot->toArray());
    }
}

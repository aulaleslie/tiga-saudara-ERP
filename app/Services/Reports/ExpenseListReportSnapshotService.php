<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

class ExpenseListReportSnapshotService
{
    private const SESSION_KEY = 'expense_list_report_snapshot';

    public function createSnapshot(ExpenseListReportFilterData $filter, int $resultCount): ExpenseListReportSnapshot
    {
        $snapshot = new ExpenseListReportSnapshot(
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

    public function getLatestSnapshot(): ?ExpenseListReportSnapshot
    {
        $data = session()->get(self::SESSION_KEY);
        if (!$data) {
            return null;
        }

        return ExpenseListReportSnapshot::fromArray($data);
    }

    public function isValidForExport(ExpenseListReportFilterData $currentFilter): bool
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

    public function persist(ExpenseListReportSnapshot $snapshot): void
    {
        session()->put(self::SESSION_KEY, $snapshot->toArray());
    }
}

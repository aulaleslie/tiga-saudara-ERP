<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Session;

class SalesOrderCompletionReportSnapshotService
{
    private const SESSION_KEY = 'sales_order_completion_report_snapshot';

    public function createSnapshot(SalesOrderCompletionReportFilterData $filter, int $resultCount): SalesOrderCompletionReportSnapshot
    {
        $snapshot = new SalesOrderCompletionReportSnapshot(
            snapshotKey: uniqid('socr_', true),
            validatedFilterHash: $filter->hash(),
            generatedAt: new \DateTimeImmutable(),
            actorUserId: (int) auth()->id(),
            isGlobal: $filter->isGlobal,
            scopeSettingId: $filter->scopeSettingId,
            resultCount: $resultCount,
        );

        $this->persist($snapshot);

        return $snapshot;
    }

    public function getLatestSnapshot(): ?SalesOrderCompletionReportSnapshot
    {
        $data = Session::get(self::SESSION_KEY);
        return $data ? SalesOrderCompletionReportSnapshot::fromArray($data) : null;
    }

    public function isValidForExport(SalesOrderCompletionReportFilterData $currentFilter): bool
    {
        $snapshot = $this->getLatestSnapshot();
        if (!$snapshot) {
            return false;
        }

        return $snapshot->validatedFilterHash === $currentFilter->hash();
    }

    public function invalidate(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    private function persist(SalesOrderCompletionReportSnapshot $snapshot): void
    {
        Session::put(self::SESSION_KEY, $snapshot->toArray());
    }
}

<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Session;

class SaleReportSnapshotService
{
    private const SESSION_KEY = 'sale_report_snapshot';

    public function createSnapshot(SaleReportFilterData $filter, int $resultCount): SaleReportSnapshot
    {
        $snapshot = new SaleReportSnapshot(
            snapshotKey: uniqid('sr_', true),
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

    public function getLatestSnapshot(): ?SaleReportSnapshot
    {
        $data = Session::get(self::SESSION_KEY);
        return $data ? SaleReportSnapshot::fromArray($data) : null;
    }

    public function isValidForExport(SaleReportFilterData $currentFilter): bool
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

    private function persist(SaleReportSnapshot $snapshot): void
    {
        Session::put(self::SESSION_KEY, $snapshot->toArray());
    }
}

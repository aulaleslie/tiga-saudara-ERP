<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Session;

class PurchaseReportSnapshotService
{
    private const SESSION_KEY = 'purchase_report_snapshot';

    public function createSnapshot(PurchaseReportFilterData $filter, int $resultCount): PurchaseReportSnapshot
    {
        $snapshot = new PurchaseReportSnapshot(
            snapshotKey: uniqid('pr_', true),
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

    public function getLatestSnapshot(): ?PurchaseReportSnapshot
    {
        $data = Session::get(self::SESSION_KEY);
        return $data ? PurchaseReportSnapshot::fromArray($data) : null;
    }

    public function isValidForExport(PurchaseReportFilterData $currentFilter): bool
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

    private function persist(PurchaseReportSnapshot $snapshot): void
    {
        Session::put(self::SESSION_KEY, $snapshot->toArray());
    }
}

<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Session;

class PurchaseOrderCompletionReportSnapshotService
{
    private const SESSION_KEY = 'purchase_order_completion_report_snapshot';

    public function createSnapshot(PurchaseOrderCompletionReportFilterData $filter, int $resultCount): PurchaseOrderCompletionReportSnapshot
    {
        $snapshot = new PurchaseOrderCompletionReportSnapshot(
            snapshotKey: uniqid('pocr_', true),
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

    public function getLatestSnapshot(): ?PurchaseOrderCompletionReportSnapshot
    {
        $data = Session::get(self::SESSION_KEY);
        return $data ? PurchaseOrderCompletionReportSnapshot::fromArray($data) : null;
    }

    public function isValidForExport(PurchaseOrderCompletionReportFilterData $currentFilter): bool
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

    private function persist(PurchaseOrderCompletionReportSnapshot $snapshot): void
    {
        Session::put(self::SESSION_KEY, $snapshot->toArray());
    }
}

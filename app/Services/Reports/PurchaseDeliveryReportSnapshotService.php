<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

class PurchaseDeliveryReportSnapshotService
{
    private const SESSION_KEY = 'purchase_delivery_report_snapshot';

    public function createSnapshot(PurchaseDeliveryReportFilterData $filter, int $resultCount): PurchaseDeliveryReportSnapshot
    {
        $snapshot = new PurchaseDeliveryReportSnapshot(
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

    public function getLatestSnapshot(): ?PurchaseDeliveryReportSnapshot
    {
        $data = session()->get(self::SESSION_KEY);
        if (!$data) {
            return null;
        }

        return PurchaseDeliveryReportSnapshot::fromArray($data);
    }

    public function isValidForExport(PurchaseDeliveryReportFilterData $currentFilter): bool
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

    public function persist(PurchaseDeliveryReportSnapshot $snapshot): void
    {
        session()->put(self::SESSION_KEY, $snapshot->toArray());
    }
}

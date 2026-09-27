<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

class PurchaseBySupplierReportSnapshotService
{
    private const SESSION_KEY = 'purchase_by_supplier_report_snapshot';

    public function createSnapshot(PurchaseBySupplierReportFilterData $filter, int $resultCount): PurchaseBySupplierReportSnapshot
    {
        $snapshot = new PurchaseBySupplierReportSnapshot(
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

    public function getLatestSnapshot(): ?PurchaseBySupplierReportSnapshot
    {
        $data = session()->get(self::SESSION_KEY);
        if (!$data) {
            return null;
        }

        return PurchaseBySupplierReportSnapshot::fromArray($data);
    }

    public function isValidForExport(PurchaseBySupplierReportFilterData $currentFilter): bool
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

    public function persist(PurchaseBySupplierReportSnapshot $snapshot): void
    {
        session()->put(self::SESSION_KEY, $snapshot->toArray());
    }
}

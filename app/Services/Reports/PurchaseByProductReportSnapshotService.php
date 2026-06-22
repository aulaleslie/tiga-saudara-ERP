<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

class PurchaseByProductReportSnapshotService
{
    private const SESSION_KEY = 'purchase_by_product_report_snapshot';

    public function createSnapshot(PurchaseByProductReportFilterData $filter, int $resultCount): PurchaseByProductReportSnapshot
    {
        $snapshot = new PurchaseByProductReportSnapshot(
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

    public function getLatestSnapshot(): ?PurchaseByProductReportSnapshot
    {
        $data = session()->get(self::SESSION_KEY);
        if (!$data) {
            return null;
        }

        return PurchaseByProductReportSnapshot::fromArray($data);
    }

    public function isValidForExport(PurchaseByProductReportFilterData $currentFilter): bool
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

    public function persist(PurchaseByProductReportSnapshot $snapshot): void
    {
        session()->put(self::SESSION_KEY, $snapshot->toArray());
    }
}

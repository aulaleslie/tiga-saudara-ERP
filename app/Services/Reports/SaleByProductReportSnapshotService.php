<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

class SaleByProductReportSnapshotService
{
    private const SESSION_KEY = 'sale_by_product_report_snapshot';

    public function createSnapshot(SaleByProductReportFilterData $filter, int $resultCount): SaleByProductReportSnapshot
    {
        $snapshot = new SaleByProductReportSnapshot(
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

    public function getLatestSnapshot(): ?SaleByProductReportSnapshot
    {
        $data = session()->get(self::SESSION_KEY);
        if (!$data) {
            return null;
        }

        return SaleByProductReportSnapshot::fromArray($data);
    }

    public function isValidForExport(SaleByProductReportFilterData $currentFilter): bool
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

    public function persist(SaleByProductReportSnapshot $snapshot): void
    {
        session()->put(self::SESSION_KEY, $snapshot->toArray());
    }
}

<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

class SalesTaxReportSnapshotService
{
    private const SESSION_KEY = 'sales_tax_report_snapshot';

    public function createSnapshot(SalesTaxReportFilterData $filter, int $resultCount): SalesTaxReportSnapshot
    {
        $snapshot = new SalesTaxReportSnapshot(
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

    public function getLatestSnapshot(): ?SalesTaxReportSnapshot
    {
        $data = session()->get(self::SESSION_KEY);
        if (!$data) {
            return null;
        }

        return SalesTaxReportSnapshot::fromArray($data);
    }

    public function isValidForExport(SalesTaxReportFilterData $currentFilter): bool
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

    public function persist(SalesTaxReportSnapshot $snapshot): void
    {
        session()->put(self::SESSION_KEY, $snapshot->toArray());
    }
}

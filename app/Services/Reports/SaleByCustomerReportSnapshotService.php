<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

class SaleByCustomerReportSnapshotService
{
    private const SESSION_KEY = 'sale_by_customer_report_snapshot';

    public function createSnapshot(SaleByCustomerReportFilterData $filter, int $resultCount): SaleByCustomerReportSnapshot
    {
        $snapshot = new SaleByCustomerReportSnapshot(
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

    public function getLatestSnapshot(): ?SaleByCustomerReportSnapshot
    {
        $data = session()->get(self::SESSION_KEY);
        if (!$data) {
            return null;
        }

        return SaleByCustomerReportSnapshot::fromArray($data);
    }

    public function isValidForExport(SaleByCustomerReportFilterData $currentFilter): bool
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

    public function persist(SaleByCustomerReportSnapshot $snapshot): void
    {
        session()->put(self::SESSION_KEY, $snapshot->toArray());
    }
}

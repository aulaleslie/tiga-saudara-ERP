<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

class CustomerReceivablesReportSnapshotService
{
    private const SESSION_KEY = 'customer_receivables_report_snapshot';

    public function createSnapshot(CustomerReceivablesReportFilterData $filter, int $resultCount): CustomerReceivablesReportSnapshot
    {
        $snapshot = new CustomerReceivablesReportSnapshot(
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

    public function getLatestSnapshot(): ?CustomerReceivablesReportSnapshot
    {
        $data = session()->get(self::SESSION_KEY);
        if (!$data) {
            return null;
        }

        return CustomerReceivablesReportSnapshot::fromArray($data);
    }

    public function isValidForExport(CustomerReceivablesReportFilterData $currentFilter): bool
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

    public function persist(CustomerReceivablesReportSnapshot $snapshot): void
    {
        session()->put(self::SESSION_KEY, $snapshot->toArray());
    }
}

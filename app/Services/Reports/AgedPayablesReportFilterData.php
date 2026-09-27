<?php

namespace App\Services\Reports;

class AgedPayablesReportFilterData
{
    public function __construct(
        public string $asOfDate,
        public string $agingBasis = 'Tanggal Transaksi',
        public ?int $scopeSettingId = null,
        public array $supplierIds = [],
        public array $tagIds = [],
        public string $tagLogic = 'Salah satu',
        public string $sortField = 'supplier_name',
        public string $sortDirection = 'asc',
        public ?string $periodPreset = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'asOfDate' => $this->asOfDate,
            'agingBasis' => $this->agingBasis,
            'scopeSettingId' => $this->scopeSettingId,
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
            'periodPreset' => $this->periodPreset,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            asOfDate: $data['asOfDate'] ?? '',
            agingBasis: $data['agingBasis'] ?? 'Tanggal Transaksi',
            scopeSettingId: $data['scopeSettingId'] ?? null,
            supplierIds: $data['supplierIds'] ?? [],
            tagIds: $data['tagIds'] ?? [],
            tagLogic: $data['tagLogic'] ?? 'Salah satu',
            sortField: $data['sortField'] ?? 'supplier_name',
            sortDirection: $data['sortDirection'] ?? 'asc',
            periodPreset: $data['periodPreset'] ?? null,
        );
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

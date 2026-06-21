<?php

namespace App\Services\Reports;

class SupplierPayablesReportFilterData
{
    public function __construct(
        public string $endDate,
        public ?int $scopeSettingId = null,
        public ?string $dueDateUntil = null,
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
            'endDate' => $this->endDate,
            'scopeSettingId' => $this->scopeSettingId,
            'dueDateUntil' => $this->dueDateUntil,
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
            endDate: $data['endDate'] ?? '',
            scopeSettingId: $data['scopeSettingId'] ?? null,
            dueDateUntil: $data['dueDateUntil'] ?? null,
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

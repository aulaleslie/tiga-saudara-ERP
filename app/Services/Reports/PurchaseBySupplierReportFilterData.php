<?php

namespace App\Services\Reports;

class PurchaseBySupplierReportFilterData
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public ?int $scopeSettingId = null,
        public array $supplierIds = [],
        public array $tagIds = [],
        public string $tagLogic = 'Salah satu',
        public array $categoryIds = [],
        public string $categoryLogic = 'Salah satu',
        public string $sortField = 'date',
        public string $sortDirection = 'asc',
        public ?string $periodPreset = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'scopeSettingId' => $this->scopeSettingId,
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'categoryIds' => $this->categoryIds,
            'categoryLogic' => $this->categoryLogic,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
            'periodPreset' => $this->periodPreset,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: $data['startDate'] ?? '',
            endDate: $data['endDate'] ?? '',
            scopeSettingId: $data['scopeSettingId'] ?? null,
            supplierIds: $data['supplierIds'] ?? [],
            tagIds: $data['tagIds'] ?? [],
            tagLogic: $data['tagLogic'] ?? 'Salah satu',
            categoryIds: $data['categoryIds'] ?? [],
            categoryLogic: $data['categoryLogic'] ?? 'Salah satu',
            sortField: $data['sortField'] ?? 'date',
            sortDirection: $data['sortDirection'] ?? 'asc',
            periodPreset: $data['periodPreset'] ?? null,
        );
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

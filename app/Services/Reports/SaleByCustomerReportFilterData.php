<?php

namespace App\Services\Reports;

class SaleByCustomerReportFilterData
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public ?int $scopeSettingId = null,
        public array $customerIds = [],
        public array $tagIds = [],
        public string $tagLogic = 'Salah satu',
        public array $categoryIds = [],
        public string $categoryLogic = 'Salah satu',
        public string $sortField = 'date',
        public string $sortDirection = 'desc',
        public ?string $periodPreset = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'scopeSettingId' => $this->scopeSettingId,
            'customerIds' => $this->customerIds,
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
            customerIds: $data['customerIds'] ?? [],
            tagIds: $data['tagIds'] ?? [],
            tagLogic: $data['tagLogic'] ?? 'Salah satu',
            categoryIds: $data['categoryIds'] ?? [],
            categoryLogic: $data['categoryLogic'] ?? 'Salah satu',
            sortField: $data['sortField'] ?? 'date',
            sortDirection: $data['sortDirection'] ?? 'desc',
            periodPreset: $data['periodPreset'] ?? null,
        );
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

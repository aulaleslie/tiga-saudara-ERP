<?php

namespace App\Services\Reports;

class SaleByProductReportFilterData
{
    public array $scopeSettingIds = [];

    public function __construct(
        public string $startDate,
        public string $endDate,
        array $scopeSettingIds = [],
        public array $customerIds = [],
        public array $tagIds = [],
        public string $tagLogic = 'Salah satu',
        public array $categoryIds = [],
        public string $categoryLogic = 'Salah satu',
        public array $productIds = [],
        public string $sortField = 'product_name',
        public string $sortDirection = 'asc',
        public ?string $periodPreset = null
    ) {
        $filtered = array_filter(array_map('intval', $scopeSettingIds), fn($id) => $id > 0);
        sort($filtered, SORT_NUMERIC);
        $this->scopeSettingIds = array_values($filtered);
    }

    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'scopeSettingIds' => $this->scopeSettingIds,
            'customerIds' => $this->customerIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'categoryIds' => $this->categoryIds,
            'categoryLogic' => $this->categoryLogic,
            'productIds' => $this->productIds,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
            'periodPreset' => $this->periodPreset,
        ];
    }

    public static function fromArray(array $data): self
    {
        $scopeSettingIds = [];
        if (isset($data['scopeSettingIds']) && is_array($data['scopeSettingIds'])) {
            $scopeSettingIds = $data['scopeSettingIds'];
        } elseif (isset($data['scopeSettingId']) && !is_null($data['scopeSettingId'])) {
            $scopeSettingIds = [(int) $data['scopeSettingId']];
        }

        return new self(
            startDate: $data['startDate'] ?? '',
            endDate: $data['endDate'] ?? '',
            scopeSettingIds: $scopeSettingIds,
            customerIds: $data['customerIds'] ?? [],
            tagIds: $data['tagIds'] ?? [],
            tagLogic: $data['tagLogic'] ?? 'Salah satu',
            categoryIds: $data['categoryIds'] ?? [],
            categoryLogic: $data['categoryLogic'] ?? 'Salah satu',
            productIds: $data['productIds'] ?? [],
            sortField: $data['sortField'] ?? 'product_name',
            sortDirection: $data['sortDirection'] ?? 'asc',
            periodPreset: $data['periodPreset'] ?? null,
        );
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

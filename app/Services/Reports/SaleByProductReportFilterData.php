<?php

namespace App\Services\Reports;

class SaleByProductReportFilterData
{
    public array $scopeSettingIds = [];
    public array $customerIds = [];
    public array $tagIds = [];
    public array $categoryIds = [];
    public array $brandIds = [];
    public array $productIds = [];

    public function __construct(
        public string $startDate,
        public string $endDate,
        array $scopeSettingIds = [],
        array $customerIds = [],
        array $tagIds = [],
        public string $tagLogic = 'Salah satu',
        array $categoryIds = [],
        public string $categoryLogic = 'Salah satu',
        array $brandIds = [],
        array $productIds = [],
        public string $sortField = 'product_name',
        public string $sortDirection = 'asc',
        public ?string $periodPreset = null
    ) {
        $this->scopeSettingIds = $this->normalizeIds($scopeSettingIds);
        $this->customerIds = $this->normalizeIds($customerIds);
        $this->tagIds = $this->normalizeIds($tagIds);
        $this->categoryIds = $this->normalizeIds($categoryIds);
        $this->brandIds = $this->normalizeIds($brandIds);
        $this->productIds = $this->normalizeIds($productIds);

        // Category matching is always OR (with descendants); normalize legacy values.
        $this->categoryLogic = 'Salah satu';

        // Obsolete return-quantity sort falls back to deterministic product_name ordering.
        if (!in_array($this->sortField, ['product_name', 'product_code', 'sold_quantity', 'sold_value', 'average_sales_value'], true)) {
            $this->sortField = 'product_name';
        }
    }

    /**
     * @param  array<mixed>  $ids
     * @return array<int>
     */
    private function normalizeIds(array $ids): array
    {
        $filtered = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => $id > 0
        )));
        sort($filtered, SORT_NUMERIC);

        return $filtered;
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
            'brandIds' => $this->brandIds,
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
            brandIds: $data['brandIds'] ?? [],
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

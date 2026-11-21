<?php

namespace App\Services\Reports;

class CrossBusinessStockInventoryFilterData
{
    public string $search;
    /** @var array<int> */
    public array $businessIds;
    /** @var array<int> */
    public array $categoryIds;
    /** @var array<int> */
    public array $brandIds;
    /** @var array<int> */
    public array $productIds;
    public string $availability; // 'all', 'available', 'non_available'

    public function __construct(
        string $search = '',
        array $businessIds = [],
        array $categoryIds = [],
        array $brandIds = [],
        string $availability = 'all',
        array $productIds = []
    ) {
        $this->search = trim($search);
        $this->businessIds = self::normalizeIds($businessIds);
        $this->categoryIds = self::normalizeIds($categoryIds);
        $this->brandIds = self::normalizeIds($brandIds);
        $this->productIds = self::normalizeIds($productIds);
        $this->availability = in_array($availability, ['all', 'available', 'non_available'], true) ? $availability : 'all';
    }

    public static function fromArray(array $filters): self
    {
        return new self(
            (string) ($filters['search'] ?? ''),
            (array) ($filters['businessIds'] ?? []),
            (array) ($filters['categoryIds'] ?? []),
            (array) ($filters['brandIds'] ?? []),
            (string) ($filters['availability'] ?? 'all'),
            (array) ($filters['productIds'] ?? [])
        );
    }

    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'businessIds' => $this->businessIds,
            'categoryIds' => $this->categoryIds,
            'brandIds' => $this->brandIds,
            'productIds' => $this->productIds,
            'availability' => $this->availability,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray()));
    }

    /**
     * @param  array<int|string|null>  $ids
     * @return array<int>
     */
    public static function normalizeIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => $id > 0
        )));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}

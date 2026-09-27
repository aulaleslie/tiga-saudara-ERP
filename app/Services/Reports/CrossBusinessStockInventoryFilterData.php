<?php

namespace App\Services\Reports;

class CrossBusinessStockInventoryFilterData
{
    public string $search;
    public array $businessIds;
    public array $categoryIds;
    public array $brandIds;
    public string $availability; // 'all', 'available', 'non_available'

    public function __construct(
        string $search = '',
        array $businessIds = [],
        array $categoryIds = [],
        array $brandIds = [],
        string $availability = 'all'
    ) {
        $this->search = trim($search);
        $this->businessIds = array_map('intval', array_filter($businessIds));
        $this->categoryIds = array_map('intval', array_filter($categoryIds));
        $this->brandIds = array_map('intval', array_filter($brandIds));
        $this->availability = in_array($availability, ['all', 'available', 'non_available']) ? $availability : 'all';
    }

    public static function fromArray(array $filters): self
    {
        return new self(
            $filters['search'] ?? '',
            $filters['businessIds'] ?? [],
            $filters['categoryIds'] ?? [],
            $filters['brandIds'] ?? [],
            $filters['availability'] ?? 'all'
        );
    }
}

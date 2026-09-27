<?php

namespace App\Services\Reports;

class WarehouseStockQuantityReportFilterData
{
    public function __construct(
        public ?string $asOfDate = null,
        public ?string $periodPreset = null,
        public array $warehouseIds = [],
        public string $sortField = 'product_name',
        public string $sortDirection = 'asc',
        public ?int $scopeSettingId = null,
        public int $perPage = 15,
        public int $page = 1
    ) {
    }

    public function toArray(): array
    {
        return [
            'asOfDate' => $this->asOfDate,
            'periodPreset' => $this->periodPreset,
            'warehouseIds' => $this->warehouseIds,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
            'scopeSettingId' => $this->scopeSettingId,
            'perPage' => $this->perPage,
            'page' => $this->page,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            asOfDate: $data['asOfDate'] ?? null,
            periodPreset: $data['periodPreset'] ?? null,
            warehouseIds: $data['warehouseIds'] ?? [],
            sortField: $data['sortField'] ?? 'product_name',
            sortDirection: $data['sortDirection'] ?? 'asc',
            scopeSettingId: $data['scopeSettingId'] ?? null,
            perPage: (int) ($data['perPage'] ?? 15),
            page: (int) ($data['page'] ?? 1),
        );
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

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
        public array $selectedSettingIds = [],
        public int $perPage = 15,
        public int $page = 1
    ) {
        if (!empty($selectedSettingIds)) {
            $this->selectedSettingIds = array_values(array_unique(array_filter(array_map('intval', $selectedSettingIds))));
        } elseif ($scopeSettingId) {
            $this->selectedSettingIds = [(int) $scopeSettingId];
        }
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
            'selectedSettingIds' => $this->selectedSettingIds,
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
            selectedSettingIds: $data['selectedSettingIds'] ?? [],
            perPage: (int) ($data['perPage'] ?? 15),
            page: (int) ($data['page'] ?? 1),
        );
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

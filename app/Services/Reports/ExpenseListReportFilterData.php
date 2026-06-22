<?php

namespace App\Services\Reports;

class ExpenseListReportFilterData
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public ?int $scopeSettingId = null,
        public array $supplierIds = [],
        public array $tagIds = [],
        public string $tagLogic = 'Salah satu',
        public string $sortField = 'date',
        public string $sortDirection = 'desc',
        public bool $detailMode = false
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
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
            'detailMode' => $this->detailMode,
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
            sortField: $data['sortField'] ?? 'date',
            sortDirection: $data['sortDirection'] ?? 'desc',
            detailMode: (bool) ($data['detailMode'] ?? false),
        );
    }

    public function hash(): string
    {
        $data = $this->toArray();
        unset($data['detailMode']);
        return md5(serialize($data));
    }
}

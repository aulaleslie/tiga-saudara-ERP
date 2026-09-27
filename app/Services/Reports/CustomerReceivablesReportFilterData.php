<?php

namespace App\Services\Reports;

class CustomerReceivablesReportFilterData
{
    public function __construct(
        public string $endDate,
        public ?int $scopeSettingId = null,
        public ?string $dueDateUntil = null,
        public array $customerIds = [],
        public array $tagIds = [],
        public string $tagLogic = 'Salah satu',
        public string $sortField = 'customer_name',
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
            'customerIds' => $this->customerIds,
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
            customerIds: $data['customerIds'] ?? [],
            tagIds: $data['tagIds'] ?? [],
            tagLogic: $data['tagLogic'] ?? 'Salah satu',
            sortField: $data['sortField'] ?? 'customer_name',
            sortDirection: $data['sortDirection'] ?? 'asc',
            periodPreset: $data['periodPreset'] ?? null,
        );
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

<?php

namespace App\Services\Reports;

class ExpenseDetailsReportFilterData
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public ?int $scopeSettingId = null,
        public array $categoryIds = [],
        public array $tagIds = [],
        public string $tagLogic = 'Salah satu',
        public string $sortDirection = 'asc'
    ) {
    }

    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'scopeSettingId' => $this->scopeSettingId,
            'categoryIds' => $this->categoryIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: $data['startDate'] ?? '',
            endDate: $data['endDate'] ?? '',
            scopeSettingId: $data['scopeSettingId'] ?? null,
            categoryIds: $data['categoryIds'] ?? [],
            tagIds: $data['tagIds'] ?? [],
            tagLogic: $data['tagLogic'] ?? 'Salah satu',
            sortDirection: $data['sortDirection'] ?? 'asc',
        );
    }

    public function hash(): string
    {
        $data = $this->toArray();
        return md5(serialize($data));
    }
}

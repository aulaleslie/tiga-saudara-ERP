<?php

namespace App\Services\Reports;

class SalesOrderCompletionReportFilterData
{
    public function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $sourceStage = 'Pemesanan',
        public readonly array $customerIds = [],
        public readonly array $tagIds = [],
        public readonly string $tagLogic = 'any',
        public readonly bool $isGlobal = false,
        public readonly ?int $scopeSettingId = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: $data['startDate'],
            endDate: $data['endDate'],
            sourceStage: $data['sourceStage'] ?? 'Pemesanan',
            customerIds: array_map('intval', (array) ($data['customerIds'] ?? [])),
            tagIds: array_map('intval', (array) ($data['tagIds'] ?? [])),
            tagLogic: in_array($data['tagLogic'] ?? 'any', ['any', 'all']) ? $data['tagLogic'] : 'any',
            isGlobal: (bool) ($data['isGlobal'] ?? false),
            scopeSettingId: isset($data['scopeSettingId']) ? (int) $data['scopeSettingId'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'sourceStage' => $this->sourceStage,
            'customerIds' => $this->customerIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'isGlobal' => $this->isGlobal,
            'scopeSettingId' => $this->scopeSettingId,
        ];
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

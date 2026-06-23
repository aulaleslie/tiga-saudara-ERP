<?php

namespace App\Services\Reports;

class SalesTaxReportFilterData
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $periodPreset = 'Bulan ini',
        public ?int $scopeSettingId = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'periodPreset' => $this->periodPreset,
            'scopeSettingId' => $this->scopeSettingId,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: $data['startDate'] ?? '',
            endDate: $data['endDate'] ?? '',
            periodPreset: $data['periodPreset'] ?? 'Bulan ini',
            scopeSettingId: $data['scopeSettingId'] ?? null,
        );
    }

    public function hash(): string
    {
        $data = $this->toArray();
        return md5(serialize($data));
    }
}

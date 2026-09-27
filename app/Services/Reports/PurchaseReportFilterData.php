<?php

namespace App\Services\Reports;

class PurchaseReportFilterData
{
    public function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly array $supplierIds = [],
        public readonly array $tagIds = [],
        public readonly array $documentStatuses = [],
        public readonly array $paymentStatuses = [],
        public readonly bool $isGlobal = false,
        public readonly ?int $scopeSettingId = null,
        public readonly string $dateBasis = 'transaction_date',
        public readonly string $reportMode = 'detail',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: $data['startDate'],
            endDate: $data['endDate'],
            supplierIds: array_map('intval', (array) ($data['supplierIds'] ?? [])),
            tagIds: array_map('intval', (array) ($data['tagIds'] ?? [])),
            documentStatuses: (array) ($data['documentStatuses'] ?? []),
            paymentStatuses: array_map('strtoupper', (array) ($data['paymentStatuses'] ?? [])),
            isGlobal: (bool) ($data['isGlobal'] ?? false),
            scopeSettingId: $data['scopeSettingId'] ? (int) $data['scopeSettingId'] : null,
            dateBasis: $data['dateBasis'] ?? 'transaction_date',
            reportMode: self::normalizeReportMode($data['reportMode'] ?? 'detail'),
        );
    }

    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'documentStatuses' => $this->documentStatuses,
            'paymentStatuses' => $this->paymentStatuses,
            'isGlobal' => $this->isGlobal,
            'scopeSettingId' => $this->scopeSettingId,
            'dateBasis' => $this->dateBasis,
            'reportMode' => $this->reportMode,
        ];
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }

    private static function normalizeReportMode(mixed $reportMode): string
    {
        return in_array($reportMode, ['detail', 'header'], true) ? $reportMode : 'detail';
    }
}

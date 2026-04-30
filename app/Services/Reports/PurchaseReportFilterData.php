<?php

namespace App\Services\Reports;

class PurchaseReportFilterData
{
    public function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly array $supplierIds = [],
        public readonly ?string $withTax = null,
        public readonly array $tagIds = [],
        public readonly ?string $status = null,
        public readonly ?string $paymentStatus = null,
        public readonly bool $isGlobal = false,
        public readonly ?int $scopeSettingId = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: $data['startDate'],
            endDate: $data['endDate'],
            supplierIds: array_map('intval', (array) ($data['supplierIds'] ?? [])),
            withTax: $data['withTax'] ?? null,
            tagIds: array_map('intval', (array) ($data['tagIds'] ?? [])),
            status: $data['status'] ?? null,
            paymentStatus: $data['paymentStatus'] ?? null,
            isGlobal: (bool) ($data['isGlobal'] ?? false),
            scopeSettingId: $data['scopeSettingId'] ? (int) $data['scopeSettingId'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'supplierIds' => $this->supplierIds,
            'withTax' => $this->withTax,
            'tagIds' => $this->tagIds,
            'status' => $this->status,
            'paymentStatus' => $this->paymentStatus,
            'isGlobal' => $this->isGlobal,
            'scopeSettingId' => $this->scopeSettingId,
        ];
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

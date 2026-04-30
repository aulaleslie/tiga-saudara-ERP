<?php

namespace App\Services\Reports;

class PurchaseReportFilterData
{
    public function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?int $supplierId = null,
        public readonly ?string $withTax = null,
        public readonly ?int $selectedTag = null,
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
            supplierId: $data['supplierId'] ? (int) $data['supplierId'] : null,
            withTax: $data['withTax'] ?? null,
            selectedTag: $data['selectedTag'] ? (int) $data['selectedTag'] : null,
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
            'supplierId' => $this->supplierId,
            'withTax' => $this->withTax,
            'selectedTag' => $this->selectedTag,
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

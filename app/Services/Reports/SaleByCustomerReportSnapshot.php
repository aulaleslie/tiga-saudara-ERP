<?php

namespace App\Services\Reports;

class SaleByCustomerReportSnapshot
{
    public function __construct(
        public string $snapshotKey,
        public string $validatedFilterHash,
        public string $generatedAt,
        public int $actorUserId,
        public ?int $scopeSettingId,
        public int $resultCount
    ) {
    }

    public function toArray(): array
    {
        return [
            'snapshotKey' => $this->snapshotKey,
            'validatedFilterHash' => $this->validatedFilterHash,
            'generatedAt' => $this->generatedAt,
            'actorUserId' => $this->actorUserId,
            'scopeSettingId' => $this->scopeSettingId,
            'resultCount' => $this->resultCount,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            snapshotKey: $data['snapshotKey'],
            validatedFilterHash: $data['validatedFilterHash'],
            generatedAt: $data['generatedAt'],
            actorUserId: $data['actorUserId'],
            scopeSettingId: $data['scopeSettingId'] ?? null,
            resultCount: $data['resultCount'] ?? 0
        );
    }
}

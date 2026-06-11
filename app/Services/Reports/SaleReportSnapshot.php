<?php

namespace App\Services\Reports;

class SaleReportSnapshot
{
    public function __construct(
        public readonly string $snapshotKey,
        public readonly string $validatedFilterHash,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly int $actorUserId,
        public readonly bool $isGlobal,
        public readonly ?int $scopeSettingId,
        public readonly int $resultCount,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            snapshotKey: $data['snapshotKey'],
            validatedFilterHash: $data['validatedFilterHash'],
            generatedAt: new \DateTimeImmutable($data['generatedAt']),
            actorUserId: (int) $data['actorUserId'],
            isGlobal: (bool) $data['isGlobal'],
            scopeSettingId: $data['scopeSettingId'] ? (int) $data['scopeSettingId'] : null,
            resultCount: (int) $data['resultCount'],
        );
    }

    public function toArray(): array
    {
        return [
            'snapshotKey' => $this->snapshotKey,
            'validatedFilterHash' => $this->validatedFilterHash,
            'generatedAt' => $this->generatedAt->format('Y-m-d H:i:s'),
            'actorUserId' => $this->actorUserId,
            'isGlobal' => $this->isGlobal,
            'scopeSettingId' => $this->scopeSettingId,
            'resultCount' => $this->resultCount,
        ];
    }
}

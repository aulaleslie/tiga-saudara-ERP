<?php

namespace App\Services\Reports;

class SaleByProductReportSnapshot
{
    public function __construct(
        public string $snapshotKey,
        public string $validatedFilterHash,
        public string $generatedAt,
        public int $actorUserId,
        public array $scopeSettingIds,
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
            'scopeSettingIds' => $this->scopeSettingIds,
            'resultCount' => $this->resultCount,
        ];
    }

    public static function fromArray(array $data): self
    {
        $scopeSettingIds = [];
        if (isset($data['scopeSettingIds']) && is_array($data['scopeSettingIds'])) {
            $scopeSettingIds = $data['scopeSettingIds'];
        } elseif (isset($data['scopeSettingId']) && !is_null($data['scopeSettingId'])) {
            $scopeSettingIds = [(int) $data['scopeSettingId']];
        }

        return new self(
            snapshotKey: $data['snapshotKey'],
            validatedFilterHash: $data['validatedFilterHash'],
            generatedAt: $data['generatedAt'],
            actorUserId: $data['actorUserId'],
            scopeSettingIds: $scopeSettingIds,
            resultCount: $data['resultCount'] ?? 0
        );
    }
}

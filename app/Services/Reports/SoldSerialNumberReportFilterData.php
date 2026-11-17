<?php

namespace App\Services\Reports;

class SoldSerialNumberReportFilterData
{
    /** @var array<int> */
    public array $productIds = [];

    /** @var array<int> */
    public array $scopeSettingIds = [];

    public function __construct(
        public string $startDate,
        public string $endDate,
        array $productIds = [],
        array $scopeSettingIds = [],
    ) {
        $products = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            fn (int $id): bool => $id > 0
        )));
        sort($products, SORT_NUMERIC);
        $this->productIds = $products;

        $settings = array_values(array_unique(array_filter(
            array_map('intval', $scopeSettingIds),
            fn (int $id): bool => $id > 0
        )));
        sort($settings, SORT_NUMERIC);
        $this->scopeSettingIds = $settings;
    }

    public function toArray(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'productIds' => $this->productIds,
            'scopeSettingIds' => $this->scopeSettingIds,
        ];
    }

    public static function fromArray(array $data): self
    {
        $scopeSettingIds = [];
        if (isset($data['scopeSettingIds']) && is_array($data['scopeSettingIds'])) {
            $scopeSettingIds = $data['scopeSettingIds'];
        } elseif (isset($data['selectedSettingIds']) && is_array($data['selectedSettingIds'])) {
            $scopeSettingIds = $data['selectedSettingIds'];
        } elseif (isset($data['scopeSettingId']) && !is_null($data['scopeSettingId'])) {
            $scopeSettingIds = [(int) $data['scopeSettingId']];
        }

        return new self(
            startDate: (string) ($data['startDate'] ?? ''),
            endDate: (string) ($data['endDate'] ?? ''),
            productIds: is_array($data['productIds'] ?? null) ? $data['productIds'] : [],
            scopeSettingIds: $scopeSettingIds,
        );
    }

    public function hash(): string
    {
        return md5(serialize($this->toArray()));
    }
}

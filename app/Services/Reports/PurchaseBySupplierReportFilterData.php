<?php

namespace App\Services\Reports;

class PurchaseBySupplierReportFilterData
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public ?int $scopeSettingId = null,
        public array $supplierIds = [],
        public array $tagIds = [],
        public string $tagLogic = 'Salah satu',
        public array $categoryIds = [],
        public string $categoryLogic = 'Salah satu',
        public string $sortField = 'date',
        public string $sortDirection = 'desc',
        public ?string $periodPreset = null
    ) {
    }
}

<?php

namespace App\Services\Reports;

class ProductStockInsightsRow
{
    public int $productId;
    public string $productCode;
    public string $productName;
    public ?string $barcode;
    public ?int $categoryId;
    public ?string $categoryName;
    public ?int $brandId;
    public ?string $brandName;
    public string $unitName;

    // Minimum alert
    public int $stockAlert; // integer product_stock_alert

    // Global stock buckets
    public StockBucketData $globalStock;

    // Business & location hierarchical buckets:
    // businessId => [
    //    'setting_id' => int,
    //    'company_name' => string,
    //    'stock' => StockBucketData,
    //    'locations' => [
    //        locationId => [
    //            'id' => int,
    //            'name' => string,
    //            'stock' => StockBucketData,
    //        ]
    //    ]
    // ]
    public array $businesses;

    // Status attention flags
    public bool $isOutOfStock;       // Stok Habis
    public bool $isReorderRequired;  // Perlu Dibeli Lagi
    public bool $isMinimumUnset;     // Batas Minimum Belum Diatur
    public bool $isSlowMoving;       // Lama Tidak Terjual

    // Sales & Financials for the selected rolling period
    public float $soldQuantity;
    public float $salesValue;        // DPP less allocated header discount
    public float $soldCost;          // captured HPP snapshots
    public float $grossProfit;       // salesValue - soldCost
    public bool $isCostIncomplete;   // True if any contributing sale line lacks usable snapshot
    public ?string $lastSaleDate;    // effective sale reporting date 'Y-m-d' or null

    public function __construct(
        int $productId,
        string $productCode,
        string $productName,
        ?string $barcode = null,
        ?int $categoryId = null,
        ?string $categoryName = null,
        ?int $brandId = null,
        ?string $brandName = null,
        string $unitName = 'Pcs',
        int $stockAlert = 0,
        ?StockBucketData $globalStock = null,
        array $businesses = [],
        bool $isOutOfStock = false,
        bool $isReorderRequired = false,
        bool $isMinimumUnset = false,
        bool $isSlowMoving = false,
        float $soldQuantity = 0.0,
        float $salesValue = 0.0,
        float $soldCost = 0.0,
        float $grossProfit = 0.0,
        bool $isCostIncomplete = false,
        ?string $lastSaleDate = null
    ) {
        $this->productId = $productId;
        $this->productCode = $productCode;
        $this->productName = $productName;
        $this->barcode = $barcode;
        $this->categoryId = $categoryId;
        $this->categoryName = $categoryName;
        $this->brandId = $brandId;
        $this->brandName = $brandName;
        $this->unitName = $unitName;
        $this->stockAlert = $stockAlert;
        $this->globalStock = $globalStock ?? new StockBucketData();
        $this->businesses = $businesses;
        $this->isOutOfStock = $isOutOfStock;
        $this->isReorderRequired = $isReorderRequired;
        $this->isMinimumUnset = $isMinimumUnset;
        $this->isSlowMoving = $isSlowMoving;
        $this->soldQuantity = $soldQuantity;
        $this->salesValue = $salesValue;
        $this->soldCost = $soldCost;
        $this->grossProfit = $grossProfit;
        $this->isCostIncomplete = $isCostIncomplete;
        $this->lastSaleDate = $lastSaleDate;
    }

    /**
     * Active statuses array in Bahasa Indonesia
     *
     * @return array<string>
     */
    public function getActiveStatuses(): array
    {
        $statuses = [];
        if ($this->isOutOfStock) {
            $statuses[] = StockInsightsFilterData::STATUS_OUT_OF_STOCK;
        }
        if ($this->isReorderRequired) {
            $statuses[] = StockInsightsFilterData::STATUS_REORDER_REQUIRED;
        }
        if ($this->isMinimumUnset) {
            $statuses[] = StockInsightsFilterData::STATUS_MINIMUM_UNSET;
        }
        if ($this->isSlowMoving) {
            $statuses[] = StockInsightsFilterData::STATUS_SLOW_MOVING;
        }
        return $statuses;
    }
}

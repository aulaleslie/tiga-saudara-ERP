<?php

namespace Modules\Adjustment\DTOs;

class TransferFormLineState
{
    public int $productId;
    public string $productName;
    public string $productCode;
    public ?string $barcode;
    public bool $isSerialNumberRequired;
    
    public bool $isBrokenMode;
    public float $requestedBaseQuantity;
    
    /**
     * @var array
     * Key is conversion_id, value is associative array with scan_count, factor, unit_name
     */
    public array $conversionScanContext = [];
    
    /**
     * @var array
     * List of selected serial numbers
     */
    public array $selectedSerials = [];
    
    public ?AllocationPreview $allocationPreview = null;
    
    public function __construct(
        int $productId,
        string $productName,
        string $productCode = '',
        ?string $barcode = null,
        bool $isSerialNumberRequired = false,
        bool $isBrokenMode = false,
        float $requestedBaseQuantity = 0.0
    ) {
        $this->productId = $productId;
        $this->productName = $productName;
        $this->productCode = $productCode;
        $this->barcode = $barcode;
        $this->isSerialNumberRequired = $isSerialNumberRequired;
        $this->isBrokenMode = $isBrokenMode;
        $this->requestedBaseQuantity = $requestedBaseQuantity;
    }
}

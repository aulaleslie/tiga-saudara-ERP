<?php

namespace Modules\Purchase\Services\DTOs;

class PurchaseUomConversionResult
{
    public function __construct(
        public readonly ?int $purchaseUnitId,
        public readonly ?int $productUnitConversionId,
        public readonly float $enteredQuantity,
        public readonly float $canonicalQuantity,
        public readonly ?float $enteredUnitPrice,
        public readonly ?float $normalizedUnitPrice,
        public readonly float $conversionFactor,
        public readonly string $unitName,
        public readonly string $baseUnitName
    ) {
    }

    /**
     * Export persistence array for PurchaseDetail creation/update.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'purchase_unit_id' => $this->purchaseUnitId,
            'product_unit_conversion_id' => $this->productUnitConversionId,
            'quantity' => number_format($this->canonicalQuantity, 3, '.', ''),
            'entered_quantity' => number_format($this->enteredQuantity, 3, '.', ''),
            'unit_price' => $this->normalizedUnitPrice !== null ? number_format($this->normalizedUnitPrice, 6, '.', '') : null,
            'entered_unit_price' => $this->enteredUnitPrice !== null ? number_format($this->enteredUnitPrice, 2, '.', '') : null,
            'conversion_factor' => number_format($this->conversionFactor, 6, '.', ''),
            'unit_name' => $this->unitName,
            'base_unit_name' => $this->baseUnitName,
        ];
    }
}

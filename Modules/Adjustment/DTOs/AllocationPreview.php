<?php

namespace Modules\Adjustment\DTOs;

class AllocationPreview
{
    public float $allocatedNonTax;
    public float $allocatedTax;
    public bool $isInsufficient;
    public float $mandatoryReturnQuantity;

    public function __construct(
        float $allocatedNonTax,
        float $allocatedTax,
        bool $isInsufficient,
        float $mandatoryReturnQuantity
    ) {
        $this->allocatedNonTax = $allocatedNonTax;
        $this->allocatedTax = $allocatedTax;
        $this->isInsufficient = $isInsufficient;
        $this->mandatoryReturnQuantity = $mandatoryReturnQuantity;
    }
}

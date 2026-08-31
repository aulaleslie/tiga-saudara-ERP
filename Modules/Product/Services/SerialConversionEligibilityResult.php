<?php

namespace Modules\Product\Services;

use Modules\Product\Entities\Product;

class SerialConversionEligibilityResult
{
    public function __construct(
        public readonly bool $isEligible,
        public readonly array $blockingReasons = [],
        public readonly ?Product $product = null
    ) {}

    public function toArray(): array
    {
        return [
            'is_eligible' => $this->isEligible,
            'blocking_reasons' => $this->blockingReasons,
        ];
    }
}

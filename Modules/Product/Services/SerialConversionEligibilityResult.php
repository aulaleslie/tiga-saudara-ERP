<?php

namespace Modules\Product\Services;

use Modules\Product\Entities\Product;

class SerialConversionEligibilityResult
{
    public function __construct(
        public readonly bool $isEligible,
        public readonly array $blockingReasons = [],
        public readonly ?Product $product = null,
        public readonly array $structuredBlockers = [],
        public readonly array $nonDocumentReasons = []
    ) {}

    public function toArray(): array
    {
        return [
            'is_eligible' => $this->isEligible,
            'blocking_reasons' => $this->blockingReasons,
            'structured_blockers' => $this->structuredBlockers,
            'non_document_reasons' => $this->nonDocumentReasons,
        ];
    }
}

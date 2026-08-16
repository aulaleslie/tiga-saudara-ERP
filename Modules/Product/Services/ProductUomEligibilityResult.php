<?php

namespace Modules\Product\Services;

use Modules\Product\Entities\Product;

class ProductUomEligibilityResult
{
    /**
     * @param bool $eligible
     * @param array<string> $blockingReasons
     * @param array<array{document_type: string, id: int, reference: string, status: string, payment_amount: float, owner_or_customer: string|null, created_at: string|null}> $removableDocuments
     * @param array{
     *     product_id: int,
     *     product_name: string,
     *     current_base_unit_id: int,
     *     current_base_unit_name: string|null,
     *     target_unit_id: int,
     *     target_unit_name: string|null,
     *     conversion_factor: float,
     *     product_quantity: float,
     *     projected_product_quantity: float,
     *     stocks: array<array{location_id: int, location_name: string|null, quantity: float, quantity_tax: float, quantity_non_tax: float, projected_quantity: float, projected_quantity_tax: float, projected_quantity_non_tax: float}>,
     *     prices: array<array{setting_id: int, average_purchase_price: float|null, last_purchase_price: float|null, projected_average_purchase_price: float|null, projected_last_purchase_price: float|null}>,
     *     rounding_notes: string|null
     * }|null $preview
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $blockingReasons = [],
        public readonly array $removableDocuments = [],
        public readonly ?array $preview = null
    ) {
    }

    public function isEligible(): bool
    {
        return $this->eligible;
    }
}

<?php

namespace Modules\Product\Rules;

use Illuminate\Contracts\Validation\Rule;
use Modules\Product\Services\BarcodeIdentityService;

class UniqueBarcodeIdentity implements Rule
{
    protected ?int $ignoreProductId;
    protected ?int $ignoreConversionId;
    protected string $message = 'Barcode ini sudah digunakan.';

    public function __construct(?int $ignoreProductId = null, ?int $ignoreConversionId = null)
    {
        $this->ignoreProductId = $ignoreProductId;
        $this->ignoreConversionId = $ignoreConversionId;
    }

    public function passes($attribute, $value): bool
    {
        if (empty($value)) {
            return true;
        }

        $identityService = app(BarcodeIdentityService::class);
        $conflict = $identityService->findConflict($value);

        if (!$conflict) {
            return true; // No conflict found
        }

        // Check if the conflict is with the ignored product/conversion
        if ($this->ignoreConversionId !== null && $conflict['type'] === 'conversion' && $conflict['conversion_id'] === $this->ignoreConversionId) {
            return true;
        }

        if ($this->ignoreProductId !== null && $conflict['type'] === 'product' && $conflict['product_id'] === $this->ignoreProductId) {
            return true;
        }

        // Generate descriptive error message
        if ($conflict['type'] === 'conversion') {
            $this->message = sprintf(
                'Barcode sudah digunakan pada produk "%s" (%s) untuk unit %s.',
                $conflict['product_name'],
                $conflict['product_code'],
                $conflict['unit_short_name']
            );
        } elseif ($conflict['type'] === 'product') {
            $this->message = sprintf(
                'Barcode sudah digunakan pada produk "%s" (%s).',
                $conflict['product_name'],
                $conflict['product_code']
            );
        } else {
            $this->message = 'Barcode sudah digunakan.';
        }

        return false;
    }

    public function message(): string
    {
        return $this->message;
    }
}

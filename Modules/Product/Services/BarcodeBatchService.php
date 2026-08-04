<?php

namespace Modules\Product\Services;

use Illuminate\Support\Collection;
use Milon\Barcode\Facades\DNS1DFacade;
use Modules\Product\Entities\Product;

/**
 * Resolves a barcode-label batch (product ids + quantities) into validated,
 * expanded label records priced from the selected business' non-tier
 * `product_prices.sale_price`.
 */
class BarcodeBatchService
{
    public const MAX_PER_PRODUCT = 100;
    public const MAX_TOTAL_LABELS = 200;

    /** Maximum SKU characters printed in full on a physical label. */
    public const MAX_LABEL_SKU_LENGTH = 40;

    /**
     * Deterministic SKU display rule for the physical label layout.
     *
     * Values of MAX_LABEL_SKU_LENGTH characters or fewer print in full. Longer
     * values print their first 39 characters followed by a visible Unicode
     * ellipsis, marking the truncation explicitly rather than relying on CSS
     * clipping. The stored `products.product_code` is never modified, and the
     * barcode value remains complete and machine-readable.
     */
    public static function displaySku(?string $productCode): string
    {
        $sku = (string) $productCode;

        if (mb_strlen($sku) <= self::MAX_LABEL_SKU_LENGTH) {
            return $sku;
        }

        return mb_substr($sku, 0, self::MAX_LABEL_SKU_LENGTH - 1) . '…';
    }

    /** Symbologies the renderer supports for label output. */
    public const SUPPORTED_SYMBOLOGIES = ['EAN13', 'C128', 'C39', 'UPCA', 'EAN8'];

    /** Stored spellings that map onto a supported renderer symbology. */
    public const SYMBOLOGY_ALIASES = [
        'CODE128' => 'C128',
        'CODE-128' => 'C128',
        'CODE 128' => 'C128',
        'CODE39' => 'C39',
        'CODE-39' => 'C39',
        'CODE 39' => 'C39',
        'EAN-13' => 'EAN13',
        'EAN-8' => 'EAN8',
        'UPC-A' => 'UPCA',
    ];

    /**
     * Normalize a stored symbology to the renderer's spelling. Unknown values
     * are returned upper-cased so supported-symbology validation can reject them.
     */
    public function normalizeSymbology(?string $symbology): string
    {
        $normalized = strtoupper(trim((string) $symbology));

        return self::SYMBOLOGY_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * Load the unique requested products with their selected-business price rows.
     *
     * @param array<int> $productIds
     * @return Collection<int, Product> keyed by product id
     */
    public function loadProducts(array $productIds, int $settingId): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        if ($ids === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->with(['prices' => fn ($q) => $q->where('setting_id', $settingId)])
            ->get()
            ->keyBy('id');
    }

    /**
     * Validate a batch and expand it into individual label records.
     *
     * @param array<int, array{product_id: int|string, quantity: int|string}> $items
     * @return array{labels: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function expand(array $items, int $settingId): array
    {
        $errors = [];
        $labels = [];

        $products = $this->loadProducts(
            array_map(fn ($item) => (int) ($item['product_id'] ?? 0), $items),
            $settingId
        );

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            /** @var Product|null $product */
            $product = $products->get($productId);

            if (! $product) {
                $errors[] = "Produk dengan ID {$productId} tidak ditemukan.";
                continue;
            }

            $label = $this->buildLabel($product, $settingId, $errors);

            if ($label === null) {
                continue;
            }

            for ($i = 0; $i < $quantity; $i++) {
                $labels[] = $label;
            }
        }

        return ['labels' => $labels, 'errors' => $errors];
    }

    /**
     * Build the immutable label payload for a product, appending any blocking errors.
     *
     * @param array<int, string> $errors
     * @return array<string, mixed>|null
     */
    public function buildLabel(Product $product, int $settingId, array &$errors): ?array
    {
        $identity = $product->product_name . ' (' . $product->product_code . ')';
        $barcode = trim((string) $product->barcode);
        $symbology = $this->normalizeSymbology($product->product_barcode_symbology);

        $valid = true;

        if ($barcode === '') {
            $errors[] = "Produk {$identity} tidak memiliki barcode.";
            $valid = false;
        }

        if ($symbology === '' || ! in_array($symbology, self::SUPPORTED_SYMBOLOGIES, true)) {
            $errors[] = "Produk {$identity} memiliki simbologi barcode yang tidak didukung.";
            $valid = false;
        }

        $priceRow = $product->relationLoaded('prices')
            ? $product->prices->firstWhere('setting_id', $settingId)
            : $product->prices()->where('setting_id', $settingId)->first();

        if (! $priceRow) {
            $errors[] = "Produk {$identity} tidak memiliki harga jual untuk perusahaan yang dipilih.";
            $valid = false;
        } elseif ($priceRow->sale_price === null) {
            $errors[] = "Produk {$identity} memiliki harga jual kosong untuk perusahaan yang dipilih.";
            $valid = false;
        }

        if (! $valid) {
            return null;
        }

        $svg = $this->renderSvg($barcode, $symbology);

        if ($svg === null) {
            $errors[] = "Barcode produk {$identity} tidak dapat dirender sebagai {$symbology}.";

            return null;
        }

        return [
            'svg' => $svg,
            'product_id' => (int) $product->id,
            'product_name' => (string) $product->product_name,
            'product_code' => (string) $product->product_code,
            'barcode' => $barcode,
            'symbology' => $symbology,
            'sale_price' => (float) $priceRow->sale_price,
        ];
    }

    /**
     * Render inline SVG for a barcode, or null when the library rejects the
     * value for the requested symbology (e.g. an invalid EAN-13 check digit).
     */
    public function renderSvg(string $barcode, string $symbology): ?string
    {
        try {
            $svg = DNS1DFacade::getBarcodeSVG($barcode, $symbology, 1.2, 45, 'black', false, true);
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($svg) && str_contains($svg, '<svg') ? $svg : null;
    }
}

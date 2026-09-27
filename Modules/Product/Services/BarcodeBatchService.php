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

    /** Explicitly recognized EAN-13 spellings that enforce strict validation. */
    public const EXPLICIT_EAN13_SPELLINGS = ['EAN13', 'EAN-13'];

    /**
     * Check if a barcode is valid EAN-13 (13 digits with correct check digit).
     */
    public function isValidEan13(string $barcode): bool
    {
        $barcode = trim($barcode);

        if (mb_strlen($barcode) !== 13 || !ctype_digit($barcode)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $barcode[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        $checkDigit = ((10 - ($sum % 10)) % 10);
        return (int) $barcode[12] === $checkDigit;
    }

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
     * Resolve the print renderer type based on stored symbology and barcode semantics.
     * Returns 'EAN13' for explicitly stored or inferred valid EAN-13.
     * Returns 'C128' for other nonblank barcodes.
     * Returns empty string if barcode is blank (validation error).
     */
    public function resolveRendererType(string $barcode, ?string $storedSymbology): string
    {
        $barcode = trim($barcode);

        if ($barcode === '') {
            return '';
        }

        $normalized = $this->normalizeSymbology($storedSymbology);

        // Explicit EAN13 spelling requires the barcode to be valid EAN-13.
        if (in_array($normalized, self::EXPLICIT_EAN13_SPELLINGS, true)) {
            return $this->isValidEan13($barcode) ? 'EAN13' : 'INVALID_EAN13';
        }

        // Absent or unrecognized symbology: infer EAN13 or fall back to C128.
        if ($normalized === '' || !in_array($normalized, self::SUPPORTED_SYMBOLOGIES, true)) {
            return $this->isValidEan13($barcode) ? 'EAN13' : 'C128';
        }

        // Other recognized symbologies (C128, C39, UPCA, EAN8) use their stored value.
        return $normalized;
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
     * Build the preview result for a single product.
     *
     * @return array{
     *     valid: bool,
     *     product_id: int,
     *     product_name: string,
     *     product_code: string,
     *     display_sku: string,
     *     barcode: string,
     *     symbology: string,
     *     sale_price: float|null,
     *     svg: string|null,
     *     error: string|null
     * }
     */
    public function buildProductPreview(Product $product, int $settingId): array
    {
        $identity = $product->product_name . ' (' . $product->product_code . ')';
        $barcode = trim((string) $product->barcode);
        $storedSymbology = $product->product_barcode_symbology;
        $displaySku = self::displaySku($product->product_code);

        if ($barcode === '') {
            return [
                'valid' => false,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) $product->product_code,
                'display_sku' => $displaySku,
                'barcode' => $barcode,
                'symbology' => '',
                'sale_price' => null,
                'svg' => null,
                'error' => "Produk {$identity} tidak memiliki barcode.",
            ];
        }

        $rendererType = $this->resolveRendererType($barcode, $storedSymbology);

        if ($rendererType === 'INVALID_EAN13') {
            return [
                'valid' => false,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) $product->product_code,
                'display_sku' => $displaySku,
                'barcode' => $barcode,
                'symbology' => $rendererType,
                'sale_price' => null,
                'svg' => null,
                'error' => "Produk {$identity} memiliki barcode yang tidak valid untuk simbologi EAN-13.",
            ];
        }

        if ($rendererType === '') {
            return [
                'valid' => false,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) $product->product_code,
                'display_sku' => $displaySku,
                'barcode' => $barcode,
                'symbology' => '',
                'sale_price' => null,
                'svg' => null,
                'error' => "Produk {$identity} tidak memiliki barcode.",
            ];
        }

        $priceRow = $product->relationLoaded('prices')
            ? $product->prices->firstWhere('setting_id', $settingId)
            : $product->prices()->where('setting_id', $settingId)->first();

        if (! $priceRow) {
            return [
                'valid' => false,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) $product->product_code,
                'display_sku' => $displaySku,
                'barcode' => $barcode,
                'symbology' => $rendererType,
                'sale_price' => null,
                'svg' => null,
                'error' => "Produk {$identity} tidak memiliki harga jual untuk perusahaan yang dipilih.",
            ];
        }

        if ($priceRow->sale_price === null) {
            return [
                'valid' => false,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) $product->product_code,
                'display_sku' => $displaySku,
                'barcode' => $barcode,
                'symbology' => $rendererType,
                'sale_price' => null,
                'svg' => null,
                'error' => "Produk {$identity} memiliki harga jual kosong untuk perusahaan yang dipilih.",
            ];
        }

        $svg = $this->renderSvg($barcode, $rendererType);

        if ($svg === null) {
            return [
                'valid' => false,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) $product->product_code,
                'display_sku' => $displaySku,
                'barcode' => $barcode,
                'symbology' => $rendererType,
                'sale_price' => (float) $priceRow->sale_price,
                'svg' => null,
                'error' => "Barcode produk {$identity} tidak dapat dirender sebagai {$rendererType}.",
            ];
        }

        return [
            'valid' => true,
            'product_id' => (int) $product->id,
            'product_name' => (string) $product->product_name,
            'product_code' => (string) $product->product_code,
            'display_sku' => $displaySku,
            'barcode' => $barcode,
            'symbology' => $rendererType,
            'sale_price' => (float) $priceRow->sale_price,
            'svg' => $svg,
            'error' => null,
        ];
    }

    /**
     * Resolve a bounded map of product previews keyed by product id.
     *
     * @param array<int|string> $productIds
     * @return array<int, array{
     *     valid: bool,
     *     product_id: int,
     *     product_name: string,
     *     product_code: string,
     *     display_sku: string,
     *     barcode: string,
     *     symbology: string,
     *     sale_price: float|null,
     *     svg: string|null,
     *     error: string|null
     * }>
     */
    public function resolvePreviewMap(array $productIds, int $settingId): array
    {
        $products = $this->loadProducts($productIds, $settingId);
        $map = [];

        foreach ($productIds as $rawId) {
            $productId = (int) $rawId;
            if ($productId <= 0 || isset($map[$productId])) {
                continue;
            }

            /** @var Product|null $product */
            $product = $products->get($productId);

            if (! $product) {
                $map[$productId] = [
                    'valid' => false,
                    'product_id' => $productId,
                    'product_name' => '',
                    'product_code' => '',
                    'display_sku' => '',
                    'barcode' => '',
                    'symbology' => '',
                    'sale_price' => null,
                    'svg' => null,
                    'error' => "Produk dengan ID {$productId} tidak ditemukan.",
                ];
                continue;
            }

            $map[$productId] = $this->buildProductPreview($product, $settingId);
        }

        return $map;
    }

    /**
     * Build the immutable label payload for a product, appending any blocking errors.
     *
     * @param array<int, string> $errors
     * @return array<string, mixed>|null
     */
    public function buildLabel(Product $product, int $settingId, array &$errors): ?array
    {
        $preview = $this->buildProductPreview($product, $settingId);

        if (! $preview['valid']) {
            $errors[] = (string) $preview['error'];

            return null;
        }

        return [
            'svg' => $preview['svg'],
            'product_id' => $preview['product_id'],
            'product_name' => $preview['product_name'],
            'product_code' => $preview['product_code'],
            'barcode' => $preview['barcode'],
            'symbology' => $preview['symbology'],
            'sale_price' => $preview['sale_price'],
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

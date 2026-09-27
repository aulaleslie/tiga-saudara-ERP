<?php

namespace App\Livewire\Product;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;

class BundleTable extends Component
{
    public $items = [];
    public $suggestions = [];
    public $productId; // passed from the blade view
    public array $rowKeys = [];
    public $bundleId = null;

    protected $listeners = [
        'productSelected' => 'updateProductRow',
    ];

    /**
     * When the component mounts, initialize items.
     */
    public function mount($productId, $initialItems = [], $bundleId = null): void
    {
        $this->productId = $productId;
        $this->bundleId = $bundleId;

        // Priority: old input (validation fail) → initialItems (edit) → empty one row (create)
        $old = session()->getOldInput('items');
        if (is_array($old) && count($old) > 0) {
            $this->normalizeAndSetItems($old, isOldInput: true);
        } elseif (!empty($initialItems) && is_array($initialItems)) {
            $this->normalizeAndSetItems($initialItems, isOldInput: false);
        } else {
            $this->items = [$this->blankItemRow()];
            $this->rowKeys = [$this->newRowKey()];
        }
    }

    /**
     * Produce a blank item row in canonical shape.
     */
    private function blankItemRow(): array
    {
        return [
            'product_id' => null,
            'product_name' => '',
            'quantity' => 1,
            'informational_item_price' => null,
            'search' => '',
        ];
    }

    /**
     * Generate a new string row key.
     */
    private function newRowKey(): string
    {
        return (string) uniqid('item_', true);
    }

    /**
     * Bulk-normalize rows and set items & rowKeys.
     */
    private function normalizeAndSetItems(array $rawRows, bool $isOldInput): void
    {
        $this->items = [];
        $this->rowKeys = [];

        // 1. Collect valid integer product IDs to query in bulk
        $productIds = [];
        foreach ($rawRows as $row) {
            if (is_array($row) && !empty($row['product_id']) && is_numeric($row['product_id'])) {
                $productIds[] = (int) $row['product_id'];
            }
        }
        $productIds = array_values(array_unique($productIds));

        // 2. Bulk load product names and active setting prices
        $productNames = [];
        $salePrices = [];
        if (!empty($productIds)) {
            $productNames = Product::query()
                ->whereIn('id', $productIds)
                ->pluck('product_name', 'id')
                ->all();

            $settingId = $this->resolveActiveSettingId();
            $salePrices = ProductPrice::query()
                ->whereIn('product_id', $productIds)
                ->where('setting_id', $settingId)
                ->pluck('sale_price', 'product_id')
                ->all();
        }

        // 3. Map rows into canonical shape
        foreach ($rawRows as $row) {
            if (!is_array($row)) {
                $this->items[] = $this->blankItemRow();
                $this->rowKeys[] = $this->newRowKey();
                continue;
            }

            $rawProductId = $row['product_id'] ?? null;
            $productId = (is_numeric($rawProductId) && (int) $rawProductId > 0) ? (int) $rawProductId : null;

            $productName = '';
            $informationalPrice = null;

            if ($productId !== null && isset($productNames[$productId])) {
                $productName = (string) $productNames[$productId];
                if (isset($salePrices[$productId]) && $salePrices[$productId] !== null) {
                    $informationalPrice = (float) $salePrices[$productId];
                } elseif (!$isOldInput && isset($row['informational_item_price']) && is_numeric($row['informational_item_price'])) {
                    // For initial persisted edit items, retain existing snapshot if active setting row missing
                    $informationalPrice = (float) $row['informational_item_price'];
                }
            }

            // Quantity: preserve submitted value (even if string / invalid) or default to 1
            $quantity = array_key_exists('quantity', $row) ? $row['quantity'] : 1;

            $this->items[] = [
                'product_id' => $productId !== null && isset($productNames[$productId]) ? $productId : ($productId ?? null),
                'product_name' => $productName,
                'quantity' => $quantity,
                'informational_item_price' => $informationalPrice,
                'search' => '',
            ];

            if (!$isOldInput && isset($row['id']) && $row['id'] !== null && $row['id'] !== '') {
                $this->rowKeys[] = (string) $row['id'];
            } else {
                $this->rowKeys[] = $this->newRowKey();
            }
        }

        if (empty($this->items)) {
            $this->items = [$this->blankItemRow()];
            $this->rowKeys = [$this->newRowKey()];
        }
    }

    /**
     * Add a new bundle item row.
     */
    public function addItem(): void
    {
        $this->items[] = $this->blankItemRow();
        $this->rowKeys[] = $this->newRowKey();
    }

    /**
     * Remove a bundle item row by index.
     */
    public function removeItem($key): void
    {
        if (count($this->items) <= 1) {
            return;
        }

        $keyStr = (string) $key;
        $index = array_search($keyStr, $this->rowKeys, true);
        if ($index === false) {
            return;
        }

        unset($this->items[$index], $this->rowKeys[$index]);
        $this->items = array_values($this->items);
        $this->rowKeys = array_values($this->rowKeys);
    }

    public function updateProductRow($payload, $maybeProduct = null): void
    {
        // Support both dispatch styles:
        // 1) dispatch('productSelected', ['index' => $key, 'product' => $product])
        // 2) dispatch('productSelected', $key, $product)

        if (is_array($payload) && array_key_exists('index', $payload)) {
            $key = $payload['index'] ?? null;
            $product = $payload['product'] ?? null;
        } else {
            $key = $payload;
            $product = $maybeProduct;
        }

        if ($key === null || $key === '') {
            Log::error('updateProductRow missing index', compact('payload', 'maybeProduct'));
            return;
        }

        $keyStr = (string) $key;
        $index = array_search($keyStr, $this->rowKeys, true);
        if ($index === false) {
            Log::error('updateProductRow could not find matching rowKey', compact('key', 'keyStr'));
            return;
        }

        if ($product === null) {
            $this->items[$index]['product_id'] = null;
            $this->items[$index]['product_name'] = '';
            $this->items[$index]['informational_item_price'] = null;
            return;
        }

        $this->items[$index]['product_id'] = $product['id'];
        $this->items[$index]['product_name'] = $product['product_name'];
        
        $settingId = $this->resolveActiveSettingId();
        $price = ProductPrice::query()
            ->where('product_id', $product['id'])
            ->where('setting_id', $settingId)
            ->value('sale_price');

        Log::info('BundleTable updateProductRow', [
            'product_id' => $product['id'],
            'setting_id' => $settingId,
            'resolved_price' => $price,
        ]);

        $this->items[$index]['informational_item_price'] = $price !== null ? (float) $price : null;
    }

    private function resolveActiveSettingId(): int
    {
        $user = auth()->user();

        return (int) (
            session('setting_id')
            ?? optional($user?->settings()->select('settings.id')->first())->id
            ?? Setting::query()->min('id')
        );
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.product.bundle-table');
    }
}

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
     * When the component mounts, initialize one empty item.
     */
    public function mount($productId, $initialItems = [], $bundleId = null): void
    {
        $this->productId = $productId;
        $this->bundleId = $bundleId;

        // Priority: old input (validation fail) → initialItems (edit) → empty one row (create)
        $old = session()->getOldInput('items', []);
        if (count($old)) {
            $this->items = $old;
            $this->rowKeys = [];
            foreach ($this->items as $_) {
                $this->rowKeys[] = uniqid('item_', true);
            }
        } elseif (!empty($initialItems)) {
            $this->items = [];
            $this->rowKeys = [];
            foreach ($initialItems as $it) {
                $productName = null;
                if (!empty($it['product_id'])) {
                    $p = Product::find($it['product_id']);
                    $productName = $p ? $p->product_name : null;
                }
                $this->items[] = [
                    'product_id' => $it['product_id'] ?? null,
                    'product_name' => $productName ?? '',
                    'quantity' => $it['quantity'] ?? 1,
                    'informational_item_price' => $it['informational_item_price'] ?? null,
                    'search' => '',
                ];
                $this->rowKeys[] = $it['id'] ?? uniqid('item_', true);
            }
        } else {
            $this->items = [['product_id' => null, 'product_name' => '', 'quantity' => 1, 'informational_item_price' => null, 'search' => '']];
            $this->rowKeys = [uniqid('item_', true)];
        }
    }

    /**
     * Add a new bundle item row.
     */
    public function addItem(): void
    {
        $this->items[] = ['product_id' => null, 'product_name' => '', 'quantity' => 1, 'informational_item_price' => null, 'search' => ''];
        $this->rowKeys[] = uniqid('item_', true);
    }

    /**
     * Remove a bundle item row by index.
     */
    public function removeItem($key): void
    {
        $index = array_search($key, $this->rowKeys, true);
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

        if (!$payload && !$maybeProduct) {
            return;
        }

        if (is_array($payload) && array_key_exists('index', $payload)) {
            $key = $payload['index'] ?? null;
            $product = $payload['product'] ?? null;
        } else {
            $key = $payload;
            $product = $maybeProduct;
        }

        if (is_null($key) || !$product) {
            Log::error('updateProductRow missing index or product', compact('payload', 'maybeProduct'));
            return;
        }

        $index = array_search($key, $this->rowKeys, true);
        if ($index === false) {
            Log::error('updateProductRow could not find matching rowKey', compact('key'));
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

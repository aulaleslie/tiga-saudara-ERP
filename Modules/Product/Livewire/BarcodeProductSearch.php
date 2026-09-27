<?php

namespace Modules\Product\Livewire;

use App\Services\EffectiveDocumentBusinessResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Modules\Product\Entities\Product;

/**
 * Product search for the barcode batch workspace. Supports searching by product
 * name, SKU, and barcode. Displays the primary barcode and authorized
 * selected-business non-tier sale price in suggestions.
 */
class BarcodeProductSearch extends Component
{
    public string $query = '';
    public Collection $search_results;
    public int $how_many = 5;

    #[Reactive]
    public ?int $selectedSettingId = null;

    public ?string $authorizationError = null;

    public function mount(?int $selectedSettingId = null): void
    {
        $this->search_results = collect();
    }

    public function updatedSelectedSettingId(): void
    {
        if (!empty(trim($this->query))) {
            $this->updatedQuery();
        }
    }

    public function updatedQuery(): void
    {
        $term = trim($this->query);

        if (mb_strlen($term) < 2) {
            $this->search_results = collect();
            $this->authorizationError = null;

            return;
        }

        try {
            $resolver = app(EffectiveDocumentBusinessResolver::class);
            $resolved = $resolver->resolve($this->selectedSettingId);
            $resolvedSettingId = $resolved['setting_id'];
            $this->authorizationError = null;
        } catch (AuthorizationException $e) {
            $this->authorizationError = 'Perusahaan yang dipilih tidak dapat diakses.';
            $this->search_results = collect();

            return;
        }

        $this->search_results = Product::query()
            ->globalSearch($term)
            ->with(['prices' => fn ($q) => $q->where('setting_id', $resolvedSettingId)])
            ->take($this->how_many)
            ->get(['id', 'product_name', 'product_code', 'product_unit', 'barcode'])
            ->map(function (Product $product) use ($resolvedSettingId) {
                $priceRow = $product->prices->firstWhere('setting_id', $resolvedSettingId);
                $salePrice = ($priceRow && $priceRow->sale_price !== null) ? (float) $priceRow->sale_price : null;

                return [
                    'id' => (int) $product->id,
                    'product_name' => (string) $product->product_name,
                    'product_code' => (string) $product->product_code,
                    'product_unit' => (string) $product->product_unit,
                    'barcode' => (string) ($product->barcode ?? ''),
                    'sale_price' => $salePrice,
                    'formatted_price' => $salePrice !== null ? format_currency($salePrice) : null,
                ];
            });
    }

    public function loadMore(): void
    {
        $this->how_many += 5;
        $this->updatedQuery();
    }

    public function resetQuery(): void
    {
        $this->query = '';
        $this->how_many = 5;
        $this->search_results = collect();
        $this->authorizationError = null;
    }

    public function showSearchResults(): void
    {
        $this->updatedQuery();
    }

    public function handleEnter(): void
    {
        $trimmed = trim($this->query);

        if (empty($trimmed)) {
            return;
        }

        $product = Product::query()->active()
            ->where('barcode', '=', $trimmed)
            ->first(['id', 'product_name', 'product_code', 'product_unit', 'barcode']);

        if ($product) {
            $this->selectProduct([
                'id' => (int) $product->id,
                'product_name' => (string) $product->product_name,
                'product_code' => (string) $product->product_code,
                'product_unit' => (string) $product->product_unit,
                'barcode' => (string) ($product->barcode ?? ''),
            ]);
        } else {
            $this->showSearchResults();
        }
    }

    public function selectProduct($result): void
    {
        $payload = is_array($result) ? $result : (array) $result;

        $this->dispatch('productSelected', $payload);
        $this->resetQuery();
    }

    public function render()
    {
        return view('product::livewire.barcode-product-search');
    }
}

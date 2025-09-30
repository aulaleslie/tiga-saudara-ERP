<?php

namespace App\Livewire\PricePoint;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Livewire\Attributes\Url;

class Browser extends Component
{
    use WithPagination;

    // If you have other Livewire components on the page, give this paginator its own name
    protected string $pageName = 'pp';         // <- custom page param
    protected string $paginationTheme = 'tailwind';

    public Setting $setting;

    #[Url]                     // just bind q to the URL (no except)
    public string $q = '';

    #[Url(as: 'pp')]           // page -> ?pp=
    public int $page = 1;

    public int $perPage = 12;

    public function mount(Setting $setting): void
    {
        $this->setting = $setting;
    }

    public function updatingQ(): void
    {
        // whenever search changes, reset to page 1
        $this->resetPage(pageName: $this->pageName);
    }

    public function searchNow(): void
    {
        $clean = preg_replace("/[\r\n]+/", '', (string) $this->q);
        $this->q = trim($clean);

        $this->resetPage(pageName: $this->pageName);
        $this->dispatch('refocus-search');
    }

    public function render()
    {
        $term = trim($this->q);

        $products = Product::query()
            ->select('products.*')
            ->selectSub(function ($q) {
                $q->from('product_prices as pp')
                    ->select('pp.sale_price')
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id)
                    ->limit(1);
            }, 'display_sale_price')
            ->selectSub(function ($q) {
                $q->from('product_prices as pp')
                    ->select('pp.tier_1_price')
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id)
                    ->limit(1);
            }, 'display_tier_1_price')
            ->selectSub(function ($q) {
                $q->from('product_prices as pp')
                    ->select('pp.tier_2_price')
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id)
                    ->limit(1);
            }, 'display_tier_2_price')
            ->whereExists(function ($q) {
                $q->from('product_prices as pp')
                    ->select(DB::raw(1))
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id);
            })
            ->with([
                'brand:id,name',
                'category:id,category_name',
                'conversions.unit:id,name',
            ])
            ->when($term !== '', function ($q) use ($term) {
                $like = "%{$term}%";
                $q->where(function ($qq) use ($like) {
                    $qq->where('products.product_name', 'like', $like)
                        ->orWhere('products.product_code', 'like', $like)
                        ->orWhere('products.barcode', 'like', $like)
                        ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like))
                        ->orWhereHas('category', fn ($c) => $c->where('category_name', 'like', $like))
                        ->orWhereHas('conversions', fn ($u) => $u->where('barcode', 'like', $like))
                        ->orWhereHas('serialNumbers', fn ($s) => $s->where('serial_number', 'like', $like));
                });
            })
            ->orderBy('products.product_name')
            ->paginate(
                perPage: $this->perPage,
                pageName: $this->pageName // <- IMPORTANT
            );

        return view('livewire.price-point.browser', compact('products'));
    }
}

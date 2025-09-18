<?php

namespace App\Livewire\PricePoint;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;

class Browser extends Component
{
    use WithPagination;

    public Setting $setting;
    public string $q = '';
    public int $perPage = 12;

    protected $queryString = [
        'q' => ['except' => ''],
        'page' => ['except' => 1],
    ];

    public function mount(Setting $setting)
    {
        $this->setting = $setting;
    }

    public function updatingQ() { $this->resetPage(); }

    // When barcode scan (or Enter) is pressed, keep cursor in the search box
    public function searchNow()
    {
        $this->dispatch('refocus-search');
    }

    public function render()
    {
        $term = trim($this->q);

        $products = Product::query()
            ->select('products.*')
            // price per selected setting
            ->selectSub(function ($q) {
                $q->from('product_prices as pp')
                    ->select('pp.sale_price')
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id)
                    ->limit(1);
            }, 'display_sale_price')
            // only show products that have a price for this setting
            ->whereExists(function ($q) {
                $q->from('product_prices as pp')
                    ->select(DB::raw(1))
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id);
            })
            ->with([
                'brand:id,name',
                'category:id,category_name',
                // show conversion units & prices
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
            ->paginate($this->perPage);

        return view('livewire.price-point.browser', [
            'products' => $products,
        ]);
    }
}

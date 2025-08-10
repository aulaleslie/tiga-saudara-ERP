<?php

namespace App\Livewire\Pos;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

class ProductList extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    protected $listeners = [
        'selectedCategory' => 'categoryChanged',
        'showCount'        => 'showCountChanged'
    ];

    public $categories;
    public $category_id;
    public $limit = 9;

    /** POS location id for current setting */
    public ?int $posLocationId = null;

    public function mount($categories)
    {
        $this->categories  = $categories;
        $this->category_id = '';

        $settingId = session('setting_id');
        $this->posLocationId = Location::where('setting_id', $settingId)
            ->where('is_pos', true)
            ->value('id');
    }

    public function render()
    {
        $query = DB::table('products as p')
            ->join('units as u', 'u.id', '=', 'p.base_unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('media as m', function ($join) {
                $join->on('m.model_id', '=', 'p.id')
                    ->where('m.model_type', '=', \Modules\Product\Entities\Product::class)
                    ->where('m.collection_name', '=', 'images');
            })
            ->leftJoinSub(function ($sub) {
                $sub->from('product_stocks')
                    ->selectRaw('product_id,
                        SUM((quantity_non_tax + quantity_tax) - (broken_quantity_non_tax + broken_quantity_tax)) AS stock_qty')
                    ->when($this->posLocationId,
                        fn ($q) => $q->where('location_id', $this->posLocationId),
                        fn ($q) => $q->whereRaw('1=0') // No POS location → no stock
                    )
                    ->groupBy('product_id');
            }, 'st', 'st.product_id', '=', 'p.id')
            ->select([
                'p.id',
                'p.product_name',
                'p.product_code',
                'p.sale_price',
                'p.barcode',
                'p.unit_id',
                DB::raw('COALESCE(st.stock_qty, 0) as product_quantity'),
                'p.base_unit_id',
                DB::raw('1 as conversion_factor'),
                'u.name as unit_name',
                DB::raw("'base' as source"),
                'p.category_id',
                'c.category_name',
                'm.file_name',
                'm.disk',
                'm.id as media_id',
                'm.uuid',
            ])
            ->when($this->category_id, fn ($q) => $q->where('p.category_id', $this->category_id))
            // Ensure stock > 0
            ->whereRaw('COALESCE(st.stock_qty, 0) > 0')
            ->paginate($this->limit);

        $query->getCollection()->transform(function ($item) {
            $model = Product::find($item->id);
            $item->photo_url = $model
                ? ($model->getFirstMediaUrl('images') ?: asset('placeholder.png'))
                : asset('placeholder.png');
            return $item;
        });

        return view('livewire.pos.product-list', [
            'products' => $query,
        ]);
    }

    public function categoryChanged($category_id)
    {
        $this->category_id = $category_id;
        $this->resetPage();
    }

    public function showCountChanged($value)
    {
        $this->limit = $value;
        $this->resetPage();
    }

    public function selectProduct($product)
    {
        $this->dispatch('productSelected', (object) $product);
    }
}

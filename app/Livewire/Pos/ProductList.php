<?php

namespace App\Livewire\Pos;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Product;

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

    public function mount($categories) {
        $this->categories = $categories;
        $this->category_id = '';
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
            ->select([
                'p.id',
                'p.product_name',
                'p.product_code',
                'p.sale_price',
                'p.barcode',
                'p.unit_id',
                'p.product_quantity',
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
            ->when($this->category_id, function ($query) {
                return $query->where('p.category_id', $this->category_id);
            })
            ->paginate($this->limit);

        // Enhance each product row with the correct photo_url from getFirstMediaUrl()
        $query->getCollection()->transform(function ($item) {
            $model = Product::find($item->id);
            $item->photo_url = $model
                ? $model->getFirstMediaUrl('images') ?: asset('placeholder.png')
                : asset('placeholder.png');
            return $item;
        });

        return view('livewire.pos.product-list', [
            'products' => $query,
        ]);
    }

    public function categoryChanged($category_id) {
        $this->category_id = $category_id;
        $this->resetPage();
    }

    public function showCountChanged($value) {
        $this->limit = $value;
        $this->resetPage();
    }

    public function selectProduct($product)
    {
        $this->dispatch('productSelected', (object) $product);
    }
}

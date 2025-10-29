<?php

namespace App\Livewire\Pos;

use Illuminate\Support\Facades\DB;
use App\Support\PosLocationResolver;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Product;

class ProductList extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    protected $listeners = [
        'selectedCategory' => 'categoryChanged',
        'showCount'        => 'showCountChanged',
        'posSearchUpdated' => 'searchChanged',
    ];

    public $categories;
    public $category_id;
    public $limit = 9;

    public array $posLocationIds = [];

    public string $searchTerm = '';

    public function mount($categories)
    {
        $this->categories  = $categories;
        $this->category_id = '';

        $locations = PosLocationResolver::resolveLocationIds();
        $this->posLocationIds = $locations->all();
    }

    public function render()
    {
        $settingId = session('setting_id');

        $query = DB::table('products as p')
            ->join('units as u', 'u.id', '=', 'p.base_unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('media as m', function ($join) {
                $join->on('m.model_id', '=', 'p.id')
                    ->where('m.model_type', '=', \Modules\Product\Entities\Product::class)
                    ->where('m.collection_name', '=', 'images');
            })
            ->leftJoin('product_prices as pp', function ($join) use ($settingId) {
                $join->on('pp.product_id', '=', 'p.id')
                    ->when($settingId,
                        fn ($q) => $q->where('pp.setting_id', '=', $settingId),
                        fn ($q) => $q->whereRaw('1 = 0')
                    );
            })
            ->leftJoinSub(function ($sub) {
                $sub->from('product_stocks')
                    ->selectRaw('product_id,
                        SUM(quantity_non_tax + quantity_tax) AS stock_qty')
                    ->when(!empty($this->posLocationIds),
                        fn ($q) => $q->whereIn('location_id', $this->posLocationIds),
                        fn ($q) => $q->whereRaw('1=0') // No POS locations → no stock
                    )
                    ->groupBy('product_id');
            }, 'st', 'st.product_id', '=', 'p.id')
            ->select([
                'p.id',
                'p.product_name',
                'p.product_code',
                DB::raw('COALESCE(pp.sale_price, p.sale_price) as sale_price'),
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
                DB::raw('COALESCE(pp.tier_1_price, p.tier_1_price) as tier_1_price'),
                DB::raw('COALESCE(pp.tier_2_price, p.tier_2_price) as tier_2_price'),
                DB::raw('COALESCE(pp.last_purchase_price, p.last_purchase_price) as last_purchase_price'),
                DB::raw('COALESCE(pp.average_purchase_price, p.average_purchase_price) as average_purchase_price'),
            ])
            ->when($this->category_id, fn ($q) => $q->where('p.category_id', $this->category_id))
            ->when($this->searchTerm !== '', function ($q) {
                $term = '%' . mb_strtolower($this->searchTerm) . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->whereRaw('LOWER(p.product_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(p.product_code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(p.barcode) LIKE ?', [$term]);
                });
            })
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

    public function searchChanged(string $term)
    {
        $this->searchTerm = trim($term);
        $this->resetPage();
    }

    public function selectProduct($product)
    {
        $this->dispatch('productSelected', (object) $product);
    }
}

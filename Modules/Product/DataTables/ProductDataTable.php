<?php

namespace Modules\Product\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\JsonResponse;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Exceptions\Exception;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;
use App\Services\ProductQuantityProjectionService;

class ProductDataTable extends DataTable
{
    protected $projectedQuantities = null;

    public function ajax(): JsonResponse
    {
        $response = parent::ajax();

        if (!Gate::allows('products.view_prices')) {
            $data = $response->getData(true);
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as &$row) {
                    unset($row['last_purchase_price']);
                    unset($row['average_purchase_price']);
                    unset($row['sale_price']);
                    unset($row['tier_1_price']);
                    unset($row['tier_2_price']);
                    unset($row['pp_last_purchase_price']);
                    unset($row['pp_average_purchase_price']);
                    unset($row['pp_sale_price']);
                    unset($row['pp_tier_1_price']);
                    unset($row['pp_tier_2_price']);
                }
            }
            $response->setData($data);
        }

        return $response;
    }

    /**
     * @throws Exception
     */
    public function dataTable($query): EloquentDataTable
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('action', function ($data) {
                return view('product::products.partials.actions', compact('data'));
            })
            ->editColumn('product_code', function ($data) {
                $link = route('products.show', $data->id);
                return '<a href="' . $link . '" class="text-primary font-weight-bold" style="text-decoration: underline;">' . $data->product_code . '</a>';
            })
            ->addColumn('total_stock', function ($data) {
                return $this->renderStockColumn($data, 'total_stock');
            })
            ->addColumn('good_stock', function ($data) {
                return $this->renderStockColumn($data, 'good_stock', 'text-success');
            })
            ->addColumn('broken_stock', function ($data) {
                return $this->renderStockColumn($data, 'broken_stock', 'text-danger');
            })
            ->addColumn('on_order_stock', function ($data) {
                return $this->renderStockColumn($data, 'on_order_stock', 'text-info');
            })
            ->addColumn('in_return_process_stock', function ($data) {
                return $this->renderStockColumn($data, 'in_return_process_stock', 'text-warning');
            })
            ->addColumn('product_image', function ($data) {
                $url = $data->getFirstMediaUrl('images', 'thumb');
                return '<img src="' . $url . '" border="0" width="50" class="img-thumbnail" align="center"/>';
            })
            ->addColumn('last_purchase_price', function ($data) {
                return $data->pp_last_purchase_price !== null ? format_currency($data->pp_last_purchase_price) : '-';
            })
            ->addColumn('average_purchase_price', function ($data) {
                return $data->pp_average_purchase_price !== null ? format_currency($data->pp_average_purchase_price) : '-';
            })
            ->addColumn('sale_price', function ($data) {
                return $data->pp_sale_price !== null ? format_currency($data->pp_sale_price) : '-';
            })
            ->addColumn('tier_1_price', function ($data) {
                return $data->pp_tier_1_price !== null ? format_currency($data->pp_tier_1_price) : '-';
            })
            ->addColumn('tier_2_price', function ($data) {
                return $data->pp_tier_2_price !== null ? format_currency($data->pp_tier_2_price) : '-';
            })
            ->addColumn('category', function ($data) {
                return optional($data->category)->category_name ?? 'N/A';
            })
            ->addColumn('brand', function ($data) {
                return optional($data->brand)->name ?? 'N/A';
            })
            ->rawColumns(['product_image', 'product_code', 'total_stock', 'good_stock', 'broken_stock', 'on_order_stock', 'in_return_process_stock']);
    }

    protected function renderStockColumn($data, $key, $class = '')
    {
        if (!$data->stock_managed) {
            return '-';
        }

        if (is_null($this->projectedQuantities) || !$this->projectedQuantities->has($data->id)) {
            $user = auth()->user();
            $settingId = (int) (session('setting_id')
                ?? optional($user?->settings()->select('settings.id')->first())->id
                ?? Setting::query()->min('id'));
            
            if (is_null($this->projectedQuantities)) {
                $this->projectedQuantities = collect();
            }

            $currentProjected = ProductQuantityProjectionService::getProjectedQuantities($data->id, $settingId);
            $this->projectedQuantities->put($data->id, $currentProjected);
        }

        $projected = $this->projectedQuantities->get($data->id);
        $qty = $this->resolveDisplayQuantity($projected, $key);
        $formatted = $this->formatQuantityValue($data, $qty);

        return $class ? "<span class=\"font-weight-bold {$class}\">{$formatted}</span>" : "<span class=\"font-weight-bold\">{$formatted}</span>";
    }

    protected function resolveDisplayQuantity(array $projected, string $key)
    {
        if ($key === 'total_stock') {
            return (int) (
                ($projected['total_stock'] ?? 0)
                + ($projected['on_order_stock'] ?? 0)
                + ($projected['in_return_process_stock'] ?? 0)
            );
        }

        return $projected[$key] ?? 0;
    }

    /**
     * Format the quantity (either available or broken) with units.
     */
    protected function formatQuantity($data, string $type): string
    {
        $quantity = $type === 'available'
            ? $data->product_quantity - $data->broken_quantity
            : $data->broken_quantity;

        return $this->formatQuantityValue($data, $quantity);
    }

    protected function formatQuantityValue($data, $quantity): string
    {
        $baseUnit = $data->baseUnit;
        $conversions = $data->conversions;

        if ($baseUnit && $conversions->isNotEmpty()) {
            $biggestConversion = $conversions->sortByDesc('conversion_factor')->first();
            $convertedQuantity = floor($quantity / $biggestConversion->conversion_factor);
            $remainder = $quantity % $biggestConversion->conversion_factor;

            return "{$convertedQuantity} {$biggestConversion->unit->short_name} {$remainder} {$baseUnit->short_name}";
        }

        return $baseUnit ? "{$quantity} {$baseUnit->short_name}" : (string) $quantity;
    }

    public function query(Product $model): Builder
    {
        // Resolve the active setting id (session -> user’s first setting -> first setting in table)
        $user = auth()->user();
        $settingId = session('setting_id')
            ?? optional($user?->settings()->select('settings.id')->first())->id
            ?? Setting::query()->min('id');

        return $model->newQuery()
            ->leftJoin('product_prices as pp', function ($join) use ($settingId) {
                $join->on('pp.product_id', '=', 'products.id')
                    ->where('pp.setting_id', '=', $settingId);
            })
            ->with([
                'category:id,category_name',
                'brand:id,name',
                'baseUnit:id,short_name',
                'conversions.unit:id,short_name',
                'productStocks.location.setting:id,company_name'
            ])
            ->select([
                'products.*',
                'pp.sale_price as pp_sale_price',
                'pp.tier_1_price as pp_tier_1_price',
                'pp.tier_2_price as pp_tier_2_price',
                'pp.last_purchase_price as pp_last_purchase_price',
                'pp.average_purchase_price as pp_average_purchase_price',
            ]);
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('product-table')
            ->columns($this->getColumns())
            ->parameters(['scrollX' => true, 'scrollY' => '70vh', 'scrollCollapse' => true])
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>>" .
                "tr" .
                "<'row'<'col-md-5'i><'col-md-7 mt-2'p>>");
    }

    protected function getColumns(): array
    {
        return array_filter([
            Column::computed('product_image')
                ->title('Gambar')
                ->className('text-center align-middle'),

            Column::make('product_code')
                ->title('Kode Produk')
                ->className('text-center align-middle'),

            Column::make('product_name')
                ->title('Nama Produk')
                ->className('text-center align-middle'),

            Column::make('category')
                ->title('Kategori')
                ->className('text-center align-middle'),

            Column::make('brand')
                ->title('Brand')
                ->className('text-center align-middle'),

            Column::computed('total_stock')
                ->title('Total')
                ->className('text-center align-middle'),

            Column::computed('good_stock')
                ->title('Stok Baik')
                ->className('text-center align-middle'),

            Column::computed('broken_stock')
                ->title('Stok Rusak')
                ->className('text-center align-middle'),

            Column::computed('on_order_stock')
                ->title('Stok Sedang Dipesan')
                ->className('text-center align-middle'),

            Column::computed('in_return_process_stock')
                ->title('Stok Sedang Diretur')
                ->className('text-center align-middle'),

            Gate::allows('products.view_prices') ? Column::computed('last_purchase_price')
                ->title('Beli Akhir')
                ->className('text-center align-middle') : null,

            Gate::allows('products.view_prices') ? Column::computed('average_purchase_price')
                ->title('Beli Rata²')
                ->className('text-center align-middle') : null,

            Gate::allows('products.view_prices') ? Column::computed('sale_price')
                ->title('Jual')
                ->className('text-center align-middle') : null,

            Gate::allows('products.view_prices') ? Column::computed('tier_1_price')
                ->title('Jual Partai')
                ->className('text-center align-middle') : null,

            Gate::allows('products.view_prices') ? Column::computed('tier_2_price')
                ->title('Jual Reseller')
                ->className('text-center align-middle') : null,

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle')
                ->title('Aksi'),

            Column::make('created_at')
                ->visible(false)
                ->title('Tanggal Dibuat')
        ]);
    }

    protected function filename(): string
    {
        return 'Product_' . date('YmdHis');
    }
}

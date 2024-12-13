<?php

namespace Modules\Product\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Product;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Exceptions\Exception;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class ProductDataTable extends DataTable
{
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
            ->addColumn('product_image', function ($data) {
                $url = $data->getFirstMediaUrl('images', 'thumb');
                return '<img src="' . $url . '" border="0" width="50" class="img-thumbnail" align="center"/>';
            })
            // Add columns for Last Purchase Price and Average Purchase Price
            ->addColumn('last_purchase_price', function ($data) {
                return format_currency($data->last_purchase_price);
            })
            ->addColumn('average_purchase_price', function ($data) {
                return format_currency($data->average_purchase_price);
            })
            ->addColumn('sale_price', function ($data) {
                return format_currency($data->sale_price);
            })
            ->addColumn('product_quantity', function ($data) {
                return $this->formatQuantity($data, 'available');
            })
            ->addColumn('broken_quantity', function ($data) {
                return $this->formatQuantity($data, 'broken');
            })
            ->addColumn('category', function ($data) {
                return optional($data->category)->category_name ?? 'N/A';
            })
            ->addColumn('brand', function ($data) {
                return optional($data->brand)->name ?? 'N/A';
            })
            ->rawColumns(['product_image']);
    }

    /**
     * Format the quantity (either available or broken) with units.
     */
    protected function formatQuantity($data, string $type): string
    {
        $quantity = $type === 'available'
            ? $data->product_quantity - $data->broken_quantity
            : $data->broken_quantity;

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
        $currentSettingId = session('setting_id');

        return $model->newQuery()
            ->where('setting_id', $currentSettingId)
            ->with([
                'category:id,category_name',
                'brand:id,name',
                'baseUnit:id,short_name',
                'conversions.unit:id,short_name'
            ]);
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('product-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>>" .
                "tr" .
                "<'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
            ->orderBy(7)
            ->buttons(
                Button::make('excel')
                    ->text('<i class="bi bi-file-earmark-excel-fill"></i> Excel'),
                Button::make('print')
                    ->text('<i class="bi bi-printer-fill"></i> Print'),
                Button::make('reset')
                    ->text('<i class="bi bi-x-circle"></i> Reset'),
                Button::make('reload')
                    ->text('<i class="bi bi-arrow-repeat"></i> Reload')
            );
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

            Column::computed('product_quantity')
                ->title('Stok Tersedia')
                ->className('text-center align-middle'),

            Column::computed('broken_quantity')
                ->title('Stok Rusak')
                ->className('text-center align-middle'),

            Column::make('category')
                ->title('Kategori')
                ->className('text-center align-middle'),

            Column::make('brand')
                ->title('Brand')
                ->className('text-center align-middle'),

            // Add columns for Last Purchase Price and Average Purchase Price
            Gate::allows('view_access_table_product') ? Column::computed('last_purchase_price')
                ->title('Harga Beli Terakhir')
                ->className('text-center align-middle') : null,

            Gate::allows('view_access_table_product') ? Column::computed('average_purchase_price')
                ->title('Harga Beli Rata Rata')
                ->className('text-center align-middle') : null,

            Gate::allows('view_access_table_product') ? Column::computed('sale_price')
                ->title('Harga Jual')
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

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
            ->addColumn('product_price', function ($data) {
                return format_currency($data->product_price);
            })
            ->addColumn('product_cost', function ($data) {
                return format_currency($data->product_cost);
            })
            ->addColumn('product_quantity', function ($data) {
                return $this->calculateAvailableStock($data);
            })
            ->addColumn('broken_quantity', function ($data) {
                return $this->formatBrokenQuantity($data);
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
     * Calculate the available stock.
     */
    protected function calculateAvailableStock($data): string
    {
        $availableStock = $data->product_quantity - $data->broken_quantity;
        $baseUnit = $data->baseUnit;
        $conversions = $data->conversions;

        if ($baseUnit && $conversions->isNotEmpty()) {
            $biggestConversion = $conversions->sortByDesc('conversion_factor')->first();
            $convertedQuantity = floor($availableStock / $biggestConversion->conversion_factor);
            $remainder = $availableStock % $biggestConversion->conversion_factor;

            return "{$convertedQuantity} {$biggestConversion->unit->short_name} {$remainder} {$baseUnit->short_name}";
        }

        return $baseUnit ? "{$availableStock} {$baseUnit->short_name}" : (string) $availableStock;
    }

    /**
     * Format the broken quantity with units.
     */
    protected function formatBrokenQuantity($data): string
    {
        $baseUnit = $data->baseUnit;
        $conversions = $data->conversions;
        $brokenQuantity = $data->broken_quantity;

        if ($baseUnit && $conversions->isNotEmpty()) {
            $biggestConversion = $conversions->sortByDesc('conversion_factor')->first();
            $convertedQuantity = floor($brokenQuantity / $biggestConversion->conversion_factor);
            $remainder = $brokenQuantity % $biggestConversion->conversion_factor;

            return "{$convertedQuantity} {$biggestConversion->unit->short_name} {$remainder} {$baseUnit->short_name}";
        }

        return $baseUnit ? "{$brokenQuantity} {$baseUnit->short_name}" : (string) $brokenQuantity;
    }

    public function query(Product $model): Builder
    {
        $currentSettingId = session('setting_id');

        return $model->newQuery()->where('setting_id', $currentSettingId)
            ->with(['category', 'brand', 'baseUnit', 'conversions.unit']);
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('product-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                'tr' .
                                <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
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
        $columns = [
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

            Gate::allows('view_access_table_product') ? Column::computed('product_cost')
                ->title('Harga Beli')
                ->className('text-center align-middle') : null,

            Gate::allows('view_access_table_product') ? Column::computed('product_price')
                ->title('Harga Jual')
                ->className('text-center align-middle') : null,

            Column::computed('action')  // Updated from 'Aksi' to 'action'
            ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle')
                ->title('Aksi'),  // Display title as 'Aksi'

            Column::make('created_at')
                ->visible(false)
                ->title('Tanggal Dibuat')
        ];

        // Filter out null columns
        return array_filter($columns);
    }

    protected function filename(): string
    {
        return 'Product_' . date('YmdHis');
    }
}

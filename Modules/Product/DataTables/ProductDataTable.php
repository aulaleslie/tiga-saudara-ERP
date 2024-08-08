<?php

namespace Modules\Product\DataTables;

use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Product;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ProductDataTable extends DataTable
{

    public function dataTable($query)
    {
        return datatables()
            ->eloquent($query)->with('category')
            ->addColumn('action', function ($data) {
                return view('product::products.partials.actions', compact('data'));
            })
            ->addColumn('product_image', function ($data) {
                $url = $data->getFirstMediaUrl('images', 'thumb');
                return '<img src="'.$url.'" border="0" width="50" class="img-thumbnail" align="center"/>';
            })
            ->addColumn('product_price', function ($data) {
                return format_currency($data->product_price);
            })
            ->addColumn('product_cost', function ($data) {
                return format_currency($data->product_cost);
            })
            ->addColumn('product_quantity', function ($data) {
                return $data->product_quantity . ' ' . $data->product_unit;
            })
            ->rawColumns(['product_image']);
    }

    public function query(Product $model)
    {
        return $model->newQuery()->with('category');
    }

    public function html()
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

    protected function getColumns()
    {
        $columns= [
            Column::computed('product_image')
                ->title('Gambar')
                ->className('text-center align-middle'),

            Column::make('product_code')
                ->title('Kode Produk')
                ->className('text-center align-middle'),

           Column::make('product_name')
                ->title('Nama Produk')
                ->className('text-center align-middle'),

            Gate::allows('view_aceestable_product') ? Column::computed('product_cost')
                ->title('Harga')
                ->className('text-center align-middle'):null,

            Gate::allows('view_aceestable_product') ? Column::computed('product_price')
                ->title('Harga Produk A')
                ->className('text-center align-middle'):null,

             Gate::allows('view_aceestable_product') ? Column::computed('dummy_column')
                ->title('Harga Produk B')
                ->className('text-center align-middle')
                ->data(''):null,

            Column::computed('dummy_column')
                ->title('Harga Produk C')
                ->className('text-center align-middle')
                ->data(''),

            Column::computed('product_quantity')
                ->title('Stok Tersedia')
                ->className('text-center align-middle'),

            Gate::allows('view_aceestable_product') ? Column::computed('dummy_column')
                ->title('Tipe Produk')
                ->className('text-center align-middle')
                ->data(''):null,

            Column::make('category.category_name')
                ->title('Kategori')
                ->className('text-center align-middle'),

            Gate::allows('view_aceestable_product') ? Column::computed('dummy_column')
                ->title('Brand')
                ->className('text-center align-middle')
                ->data(''):null,

            Gate::allows('view_aceestable_product') ? Column::computed('dummy_column')
                ->title('Tax')
                ->className('text-center align-middle')
                ->data(''):null,

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle')
                ->title('Aksi'),

            Column::make('created_at')
                ->visible(false)
                ->title('Tanggal Dibuat')
        ];

        // Filter kolom null yang mungkin telah ditambahkan
        $columns = array_filter($columns);

        return $columns;
    }

    /**
     * Get filename for export.
     *
     * @return string
     */
    protected function filename(): string
    {
        return 'Product_' . date('YmdHis');
    }
}

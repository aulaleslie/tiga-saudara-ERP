<?php

namespace Modules\Product\DataTables;

use Modules\Product\Entities\Category;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Exceptions\Exception;
use Yajra\DataTables\Html\Builder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class ProductCategoriesDataTable extends DataTable
{

    /**
     * @throws Exception
     */
    public function dataTable($query): EloquentDataTable
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('nama_kategori', function (Category $category) {
                if ($category->parent) {
                    return $category->parent->category_name . '::' . $category->category_name;
                } else {
                    return $category->category_name;
                }
            })
            ->addColumn('action', function ($data) {
                return view('product::categories.partials.actions', compact('data'));
            });
    }

    public function query(Category $model): \Illuminate\Database\Eloquent\Builder
    {
        return $model->newQuery()->with('parent')->withCount('products'); // Eager load the parent category
    }

    public function html(): Builder
    {
        return $this->builder()
            ->setTableId('product_categories-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                'tr' .
                                <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
            ->orderBy(4)
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
        return [
            Column::make('category_code')
                ->addClass('text-center')
                ->title('Kode Kategori'),

            Column::make('nama_kategori')
                ->addClass('text-center')
                ->title('Nama Kategori'),

            Column::make('products_count')
                ->addClass('text-center')
                ->title('Total Produk'),

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->addClass('text-center')
                ->title('Aksi'),

            Column::make('created_at')
                ->visible(false)
        ];
    }

    protected function filename(): string {
        return 'ProductCategories_' . date('YmdHis');
    }
}

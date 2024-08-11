<?php

namespace Modules\Product\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Modules\Product\Entities\Brand;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Exceptions\Exception;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class BrandDataTable extends DataTable
{
    /**
     * @throws Exception
     */
    public function dataTable($query): EloquentDataTable
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('action', function ($data) {
                return view('product::brands.partials.actions', compact('data'));
            })
            ->addColumn('name', function ($data) {
                return $data->name;
            })
            ->addColumn('description', function ($data) {
                return $data->description;
            });
    }

    public function query(Brand $model): Builder
    {
        // Get the current setting ID from the session
        $currentSettingId = session('setting_id');

        // Filter the query by the current setting ID
        return $model->newQuery()->where('setting_id', $currentSettingId);
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('brand-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                'tr' .
                                <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
            ->orderBy(1)
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
            Column::make('id')
                ->title('ID')
                ->className('text-center align-middle'),

            Column::make('name')
                ->title('Brand Name')
                ->className('text-center align-middle'),

            Column::make('description')
                ->title('Description')
                ->className('text-center align-middle'),

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle')
                ->title('Action')
        ];
    }

    protected function filename(): string
    {
        return 'Brand_' . date('YmdHis');
    }
}


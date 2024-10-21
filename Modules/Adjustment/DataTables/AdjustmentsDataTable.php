<?php

namespace Modules\Adjustment\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Modules\Adjustment\Entities\Adjustment;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class AdjustmentsDataTable extends DataTable
{

    public function dataTable($query): EloquentDataTable
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('action', function ($data) {
                return view('adjustment::partials.actions', compact('data'));
            })
            ->editColumn('type', function ($data) {
                return strtoupper($data->type);
            })
            ->editColumn('status', function ($data) {
                return strtoupper($data->status);
            });
    }

    public function query(Adjustment $model): Builder
    {
        return $model->newQuery()->withCount('adjustedProducts');
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('adjustments-table')
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
            Column::make('date')
                ->className('text-center align-middle')
                ->title('Tanggal'),

            Column::make('reference')
                ->className('text-center align-middle')
                ->title('referensi'),

            Column::make('type')
                ->className('text-center align-middle')
                ->title('Tipe'),

            Column::make('status')
                ->className('text-center align-middle'),

            Column::make('adjusted_products_count')
                ->title('Produk')
                ->className('text-center align-middle'),

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle')
                ->title('Aksi'),

            Column::make('created_at')
                ->visible(false)
        ];
    }

    protected function filename(): string {
        return 'Adjustments_' . date('YmdHis');
    }
}

<?php

namespace Modules\Adjustment\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Modules\Adjustment\Entities\Transfer;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Exceptions\Exception;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class StockTransfersDataTable extends DataTable
{
    /**
     * @throws Exception
     */
    public function dataTable($query): EloquentDataTable
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('action', function ($data) {
                return view('adjustment::transfers.partials.actions', compact('data'));
            })
            ->editColumn('status', function ($data) {
                return strtoupper($data->status);
            })
            ->editColumn('created_at', function ($data) {
                return $data->created_at ? $data->created_at->format('Y-m-d H:i:s') : '-';
            })
            ->addColumn('origin_location_name', function ($data) {
                return $data->originLocation ? $data->originLocation->name : '-';
            })
            ->addColumn('destination_location_name', function ($data) {
                return $data->destinationLocation ? $data->destinationLocation->name : '-';
            });
    }


    public function query(Transfer $model): Builder
    {
        return $model->newQuery()->with('originLocation', 'destinationLocation');
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('transfers-table')
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
            Column::make('created_at')
                ->title('Transfer Date')
                ->className('text-center align-middle'),

            Column::make('origin_location_name')
                ->title('Origin Location')
                ->className('text-center align-middle'),

            Column::make('destination_location_name')
                ->title('Destination Location')
                ->className('text-center align-middle'),

            Column::make('status')
                ->className('text-center align-middle'),

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle'),
        ];
    }

    protected function filename(): string
    {
        return 'StockTransfers_' . date('YmdHis');
    }
}

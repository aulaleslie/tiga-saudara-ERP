<?php

namespace Modules\Setting\DataTables;

use Modules\Setting\Entities\Setting;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class BusinessDataTable extends DataTable
{
    public function dataTable($query) {
        return datatables()
            ->eloquent($query)
            ->addColumn('action', function ($data) {
                return view('setting::businesses.partials.actions', compact('data'));
            });
    }

    public function query(Setting $model) {
        return $model->newQuery()->with('currency')->select('id', 'company_name', 'created_at', 'default_currency_id');
    }

    public function html() {
        return $this->builder()
            ->setTableId('businesses-table')
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

    protected function getColumns() {
        return [
            Column::make('id')
                ->addClass('text-center')
                ->addClass('align-middle')
                ->title('No'),

            Column::make('company_name')
                ->addClass('text-center')
                ->addClass('align-middle')
                ->title('Nama Bisnis'),

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->addClass('text-center')
                ->addClass('align-middle')
                ->title('Aksi'),

            Column::make('created_at')
                ->visible(false)
                ->title('Tanggal Dibuat')
        ];
    }

    protected function filename(): string {
        return 'Businesses_' . date('YmdHis');
    }
}

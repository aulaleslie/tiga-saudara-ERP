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
        if (auth()->user()->hasRole('Super Admin')) {
            return $model->newQuery()->with('currency');
        } else {
            return auth()->user()->settings()->with('currency');
        }
    }

    public function html() {
        return $this->builder()
            ->setTableId('businesses-table')
            ->columns($this->getColumns())
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                'tr' .
                                <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
            ->orderBy(0);
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

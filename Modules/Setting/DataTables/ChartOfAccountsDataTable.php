<?php

namespace Modules\Setting\DataTables;

use Modules\Setting\Entities\ChartOfAccount;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class ChartOfAccountsDataTable extends DataTable
{
    public function dataTable($query): EloquentDataTable
    {
        $dataTable = datatables()
            ->eloquent($query)
            ->setRowId('id')
            ->addColumn('nama_akun', function (ChartOfAccount $account) {
                if ($account->parent_account) {
                    return $account->parent_account->name . '::' . $account->name;
                }
                return $account->name;
            })
            ->addColumn('action', function ($data) {
                return view('setting::coa.partials.actions', compact('data'));
            });

        $dataTable->filter(function ($query) {
            if (request()->has('search') && request('search')['value']) {
                $searchValue = strtolower(request('search')['value']);
                $query->where(function ($query) use ($searchValue) {
                    $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchValue}%"])
                        ->orWhereRaw('LOWER(account_number) LIKE ?', ["%{$searchValue}%"]);
                });
            }
        });
        return $dataTable;
    }

    public function query(ChartOfAccount $model)
    {
        return $model->newQuery()->select(['id', 'name', 'account_number', 'category', 'parent_account_id', 'created_at'])->with(['tax'])->orderBy('account_number');
    }

    public function html(): Builder
    {
        return $this->builder()
                ->setTableId('chart-of-account-table')
                ->columns($this->getColumns())
                ->minifiedAjax()
                ->pageLength(25) // Set reasonable page size
                ->responsive(true)
                ->serverSide(true)
                ->processing(true)
                ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                    'tr' .
                                    <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
                ->orderBy(5)
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
            Column::make('account_number')
                ->addClass('text-center')
                ->title('Nomor Akun'),

            Column::make('nama_akun')
                ->addClass('text-center')
                ->title('Nama Akun'),

            Column::make('category')
                ->addClass('text-center')
                ->title('Kategori'),

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->addClass('text-center')
                ->title('Aksi'),

            Column::make('created_at')
                ->visible(false)
        ];
    }

    protected function filename(): string
    {
        return 'ChartOfAccounts_' . date('YmdHis');
    }
}

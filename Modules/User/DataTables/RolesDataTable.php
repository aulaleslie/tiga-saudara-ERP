<?php

namespace Modules\User\DataTables;

use Illuminate\Database\Eloquent\Builder;
use LaravelIdea\Helper\Spatie\Permission\Models\_IH_Role_QB;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Exceptions\Exception;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class RolesDataTable extends DataTable
{

    /**
     * @throws Exception
     */
    public function dataTable($query): EloquentDataTable
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('action', function ($data) {
                return view('user::roles.partials.actions', compact('data'));
            })
            ->addColumn('permissions', function ($data) {
                return view('user::roles.partials.permissions', [
                    'data' => $data
                ]);
            });

    }

    public function query(Role $model): _IH_Role_QB|Builder
    {
        return $model->newQuery()->with(['permissions' => function ($query) {
            $query->select('name')->take(10)->get();
        }])->where('name', '!=', 'Super Admin');
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('roles-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                'tr' .
                                <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
            ->orderBy(3)
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
            Column::make('name')
                ->addClass('text-center')
                ->addClass('align-middle')
                ->title('Nama'), // Mengubah nama kolom

            Column::computed('permissions')
                ->addClass('text-center')
                ->addClass('align-middle')
                ->width('700px')
                ->title('Hak Akses'), // Mengubah nama kolom

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->addClass('text-center')
                ->addClass('align-middle')
                ->title('Aksi'), // Mengubah nama kolom

            Column::make('created_at')
                ->visible(false)
                ->title('Tanggal Dibuat') // Mengubah nama kolom
        ];
    }

    protected function filename(): string {
        return 'Roles_' . date('YmdHis');
    }
}

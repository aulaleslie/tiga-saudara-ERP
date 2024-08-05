<?php

namespace Modules\User\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Exceptions\Exception;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class UsersDataTable extends DataTable
{
    /**
     * @throws Exception
     */
    public function dataTable($query): EloquentDataTable
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('role_setting', function ($data) {
                return view('user::users.partials.role_setting', [
                    'settings' => explode(', ', $data->settings),
                    'roles' => explode(', ', $data->roles)
                ]);
            })
            ->addColumn('action', function ($data) {
                return view('user::users.partials.actions', compact('data'));
            })
            ->addColumn('status', function ($data) {
                return $data->is_active == 1 ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-warning">Deactivated</span>';
            })
            ->addColumn('image', function ($data) {
                $user = User::find($data->user_id);
                $url = $user->getFirstMediaUrl('avatars');
                return '<img src="' . $url . '" style="width:50px;height:50px;" class="img-thumbnail rounded-circle"/>';
            })
            ->rawColumns(['image', 'status']);
    }

    public function query(User $model): Builder
    {
        $query = $model->newQuery()
            ->with(['roles', 'settings' => function ($query) {
                $query->select('settings.id', 'company_name')->withPivot('role_id');
            }])
            ->leftJoin('user_setting', 'users.id', '=', 'user_setting.user_id')
            ->leftJoin('roles', 'user_setting.role_id', '=', 'roles.id')
            ->leftJoin('settings', 'user_setting.setting_id', '=', 'settings.id')
            ->select(
                'users.id as user_id',
                'users.name',
                'users.email',
                'users.is_active',
                'users.created_at',
                \DB::raw('GROUP_CONCAT(DISTINCT settings.company_name ORDER BY settings.id SEPARATOR ", ") as settings'),
                \DB::raw('GROUP_CONCAT(DISTINCT roles.name ORDER BY settings.id SEPARATOR ", ") as roles')
            )
            ->groupBy('users.id', 'users.name', 'users.email', 'users.is_active', 'users.created_at')
            ->where('users.id', '!=', auth()->id());

        if (!auth()->user()->hasRole('Super Admin')) {
            // Non-Super Admin can see only users with settings they have access to
            $accessibleSettings = auth()->user()->settings()->pluck('settings.id');
            $query->whereIn('user_setting.setting_id', $accessibleSettings);
        }

        return $query;
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('users-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                'tr' .
                                <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
            ->orderBy(6)
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
            Column::computed('image')
                ->className('text-center align-middle')
                ->title('Gambar'), // Mengubah nama kolom

            Column::make('name')
                ->className('text-center align-middle')
                ->title('Nama'), // Mengubah nama kolom

            Column::make('email')
                ->className('text-center align-middle')
                ->title('Email'), // Mengubah nama kolom

            Column::computed('role_setting')
                ->className('text-center align-middle')
                ->title('Peran dan Setting'), // Mengubah nama kolom

            Column::computed('status')
                ->className('text-center align-middle')
                ->title('Status'), // Mengubah nama kolom

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle')
                ->title('Aksi'), // Mengubah nama kolom

            Column::make('created_at')
                ->visible(false)
                ->title('Tanggal Dibuat') // Mengubah nama kolom
        ];
    }

    protected function filename(): string
    {
        return 'Users_' . date('YmdHis');
    }
}

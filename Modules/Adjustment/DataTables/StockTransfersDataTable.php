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
            ->editColumn('document_number', function ($data) {
                return $data->document_number ?? '-';
            })
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
                $location = $data->originLocation;

                if (! $location) {
                    return '-';
                }

                $name            = $location->name ?? '-';
                $currentSetting  = session('setting_id');
                $locationSetting = $location->setting_id;

                if ($locationSetting && (string) $locationSetting !== (string) $currentSetting) {
                    $tenant = optional($location->setting)->company_name ?? ('Setting #' . $locationSetting);

                    return sprintf('%s (%s)', $name, $tenant);
                }

                return $name;
            })
            ->addColumn('destination_location_name', function ($data) {
                $location = $data->destinationLocation;

                if (! $location) {
                    return '-';
                }

                $name            = $location->name ?? '-';
                $currentSetting  = session('setting_id');
                $locationSetting = $location->setting_id;

                if ($locationSetting && (string) $locationSetting !== (string) $currentSetting) {
                    $tenant = optional($location->setting)->company_name ?? ('Setting #' . $locationSetting);

                    return sprintf('%s (%s)', $name, $tenant);
                }

                return $name;
            });
    }


    public function query(Transfer $model): Builder
    {
        $settingId = session('setting_id');

        return $model->newQuery()
            ->with(['originLocation.setting', 'destinationLocation.setting'])
            ->where(function($q) use ($settingId) {
                // 1) All transfers where current setting is the ORIGIN
                $q->whereHas('originLocation.setting', function ($q1) use ($settingId) {
                    $q1->where('id', $settingId);
                })
                    // 2) OR: transfers where current setting is the DESTINATION regardless of status
                    ->orWhere(function($q2) use ($settingId) {
                        $q2->whereHas('destinationLocation.setting', function ($q3) use ($settingId) {
                            $q3->where('id', $settingId);
                        });
                    });
            });
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
            ->orderBy(0, 'desc')
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
            Column::make('document_number')
                ->title('No. Dokumen')
                ->className('text-center align-middle'),

            Column::make('created_at')
                ->title('Tanggal Transfer')
                ->className('text-center align-middle'),

            Column::make('origin_location_name')
                ->title('Lokasi Asal')
                ->className('text-center align-middle'),

            Column::make('destination_location_name')
                ->title('Lokasi Tujuan')
                ->className('text-center align-middle'),

            Column::make('status')
                ->className('text-center align-middle'),

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle')
                ->title('Aksi'),
        ];
    }

    protected function filename(): string
    {
        return 'StockTransfers_' . date('YmdHis');
    }
}

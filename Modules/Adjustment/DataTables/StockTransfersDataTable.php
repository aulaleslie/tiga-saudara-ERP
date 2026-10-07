<?php

namespace Modules\Adjustment\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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
            ->filter(function ($query) {
                $term = trim((string) $this->request()->input('search.value', ''));

                if ($term !== '') {
                    $this->applyGlobalSearch($query, $term);
                }
            })
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


    /**
     * Global search: document number, current linked product name / primary
     * barcode, and serial numbers persisted with the transfer at any stage.
     * Uses correlated EXISTS so each transfer is returned once.
     */
    protected function applyGlobalSearch($query, string $term): void
    {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)) . '%';
        $match = fn (string $column) => "LOWER({$column}) LIKE ? ESCAPE '!'";

        $query->where(function ($search) use ($like, $match) {
            $search->whereRaw($match('transfers.document_number'), [$like])
                ->orWhereExists(function ($q) use ($like, $match) {
                    $q->select(DB::raw(1))->from('products')
                        ->whereRaw("(" . $match('products.product_name') . ' OR ' . $match('products.barcode') . ')', [$like, $like])
                        ->where(function ($p) {
                            $p->whereExists(fn ($e) => $e->select(DB::raw(1))->from('transfer_products')
                                    ->whereColumn('transfer_products.transfer_id', 'transfers.id')
                                    ->whereColumn('transfer_products.product_id', 'products.id'))
                                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('transfer_approval_allocations')
                                    ->whereColumn('transfer_approval_allocations.transfer_id', 'transfers.id')
                                    ->whereColumn('transfer_approval_allocations.product_id', 'products.id'))
                                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('transfer_movement_lines')
                                    ->join('transfer_movements', 'transfer_movements.id', '=', 'transfer_movement_lines.transfer_movement_id')
                                    ->whereColumn('transfer_movements.transfer_id', 'transfers.id')
                                    ->whereColumn('transfer_movement_lines.product_id', 'products.id'))
                                // Historical v3 request revisions (e.g. a product removed before allocation).
                                ->orWhereExists(function ($e) {
                                    $e->select(DB::raw(1))->from('transfer_request_revisions')
                                        ->whereColumn('transfer_request_revisions.transfer_id', 'transfers.id')
                                        ->whereRaw(DB::connection()->getDriverName() === 'sqlite'
                                            ? "exists (select 1 from json_each(transfer_request_revisions.lines) j where json_extract(j.value, '\$.product_id') = products.id)"
                                            : "JSON_CONTAINS(JSON_EXTRACT(transfer_request_revisions.lines, '\$[*].product_id'), CAST(products.id AS CHAR))");
                                });
                        });
                })
                ->orWhereExists(function ($q) use ($like, $match) {
                    $q->select(DB::raw(1))->from('transfer_movement_serials')
                        ->join('transfer_movements', 'transfer_movements.id', '=', 'transfer_movement_serials.transfer_movement_id')
                        ->whereColumn('transfer_movements.transfer_id', 'transfers.id')
                        ->whereRaw($match('transfer_movement_serials.serial_number'), [$like]);
                })
                ->orWhereExists(function ($q) use ($like, $match) {
                    $q->select(DB::raw(1))->from('transfer_approval_allocation_serials')
                        ->join('product_serial_numbers', 'product_serial_numbers.id', '=', 'transfer_approval_allocation_serials.product_serial_number_id')
                        ->whereColumn('transfer_approval_allocation_serials.transfer_id', 'transfers.id')
                        ->whereRaw($match('product_serial_numbers.serial_number'), [$like]);
                });

            // Persisted JSON selections (legacy and v3-draft transfer_products,
            // v3 request revisions) hold serial ids, either bare or as
            // {id, serial_number} objects. Correlate to the serial text per
            // transfer so no result set is truncated.
            $search->orWhereExists(function ($q) use ($like, $match) {
                $q->select(DB::raw(1))->from('transfer_products')
                    ->whereColumn('transfer_products.transfer_id', 'transfers.id')
                    ->where(function ($w) use ($like, $match) {
                        foreach (['serial_numbers', 'dispatched_serial_numbers'] as $column) {
                            $w->orWhereExists($this->serialJsonExists("transfer_products.{$column}", false, $like, $match));
                        }
                    });
            })->orWhereExists(function ($q) use ($like, $match) {
                $q->select(DB::raw(1))->from('transfer_request_revisions')
                    ->whereColumn('transfer_request_revisions.transfer_id', 'transfers.id')
                    ->whereExists($this->serialJsonExists('transfer_request_revisions.lines', true, $like, $match));
            });

            // Keep the default header search the list had before.
            $search->orWhereRaw('LOWER(transfers.status) LIKE ? ESCAPE \'!\'', [$like])
                ->orWhereRaw('CAST(transfers.created_at AS CHAR) LIKE ? ESCAPE \'!\'', [$like]);
        });
    }

    /**
     * Correlated EXISTS over a JSON column of serial ids ($nested: revision
     * lines with serials[].id; otherwise a flat list of ids or id objects).
     */
    protected function serialJsonExists(string $column, bool $nested, string $like, \Closure $match): \Closure
    {
        $wrapped = DB::connection()->getQueryGrammar()->wrap($column);
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($sqlite) {
            $condition = $nested
                ? "exists (select 1 from json_tree({$wrapped}) t where t.key = 'id' and t.value = psn.id)"
                : "exists (select 1 from json_each({$wrapped}) j where j.value = psn.id or json_extract(j.value, '\$.id') = psn.id)";
        } else {
            $path = $nested ? '$[*].serials[*].id' : '$[*].id';
            $condition = $nested
                ? "JSON_CONTAINS(JSON_EXTRACT({$wrapped}, '{$path}'), CAST(psn.id AS CHAR))"
                : "(JSON_CONTAINS({$wrapped}, CAST(psn.id AS CHAR)) OR JSON_CONTAINS(JSON_EXTRACT({$wrapped}, '{$path}'), CAST(psn.id AS CHAR)))";
        }

        return function ($e) use ($condition, $like, $match) {
            $e->select(DB::raw(1))->from('product_serial_numbers as psn')
                ->whereRaw($match('psn.serial_number'), [$like])
                ->whereRaw($condition);
        };
    }

    public function query(Transfer $model): Builder
    {
        $settingId = session('setting_id');

        return $model->newQuery()
            ->with(['originLocation.setting', 'destinationLocation.setting'])
            ->where(function ($scope) use ($settingId) {
                // Workflow version 3 documents are discoverable by anyone
                // holding stockTransfers.access in the active business; they
                // carry no single route, and actions stay separately gated.
                $scope->where('workflow_version', Transfer::WORKFLOW_V3)
                    ->orWhere(function ($q) use ($settingId) {
                        // Legacy scope (unchanged): origin OR destination business.
                        $q->whereHas('originLocation.setting', function ($q1) use ($settingId) {
                            $q1->where('id', $settingId);
                        })
                            ->orWhere(function($q2) use ($settingId) {
                                $q2->whereHas('destinationLocation.setting', function ($q3) use ($settingId) {
                                    $q3->where('id', $settingId);
                                });
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

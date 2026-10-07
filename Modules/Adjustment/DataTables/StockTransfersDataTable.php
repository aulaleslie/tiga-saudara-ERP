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
     * Global search: document number, status, creation date, current linked
     * product name / primary barcode, and serial numbers persisted with the
     * transfer at any stage (requests, allocations, movements, drafts).
     *
     * Measured on MySQL, these shapes are slow and are avoided here:
     * correlating the text match per transfer (rescans products/serials for
     * every transfer), `transfers.id IN (... UNION ...)` (MySQL re-runs the
     * union per transfer row), joining the union as a derived table (the
     * planner still scans and sorts every transfer), and JSON scans when
     * nothing can match. Instead the matching product/serial id sets are
     * materialized once (CTE), the association branches are unioned, and the
     * resulting transfer ids are stored once in a temporary table.
     * Branches whose id set is empty are skipped.
     */
    protected function applyGlobalSearch($query, string $term): void
    {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)) . '%';
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        // Columns are case-insensitive under the app's utf8mb4_unicode_ci
        // collation (and SQLite ASCII LIKE), so no LOWER() is applied.
        $productWhere = "product_name LIKE ? ESCAPE '!' or barcode LIKE ? ESCAPE '!'";
        $hasProducts = DB::table('products')->whereRaw($productWhere, [$like, $like])->exists();
        $hasSerials = DB::table('product_serial_numbers')->whereRaw("serial_number LIKE ? ESCAPE '!'", [$like])->exists();

        $ctes = $cteBindings = $branches = $bindings = [];

        $branches[] = ["select id as transfer_id from transfers where document_number LIKE ? ESCAPE '!' or status LIKE ? ESCAPE '!' or CAST(created_at AS CHAR) LIKE ? ESCAPE '!'", [$like, $like, $like]];

        if ($hasProducts) {
            $ctes[] = "p as (select id from products where {$productWhere})";
            $cteBindings = array_merge($cteBindings, [$like, $like]);

            $branches[] = ['select transfer_id from transfer_products where product_id in (select id from p)', []];
            $branches[] = ['select transfer_id from transfer_approval_allocations where product_id in (select id from p)', []];
            $branches[] = ['select m.transfer_id from transfer_movement_lines l join transfer_movements m on m.id = l.transfer_movement_id where l.product_id in (select id from p)', []];
            $branches[] = $sqlite
                ? ["select r.transfer_id from transfer_request_revisions r, json_each(r.`lines`) j where json_extract(j.value, '\$.product_id') in (select id from p)", []]
                : ["select r.transfer_id from transfer_request_revisions r, json_table(r.`lines`, '\$[*]' columns (pid bigint path '\$.product_id')) jt where jt.pid in (select id from p)", []];
        }

        // Movement serial snapshots are matched by text so they survive
        // deleted live serial rows.
        $branches[] = ["select m.transfer_id from transfer_movement_serials s join transfer_movements m on m.id = s.transfer_movement_id where s.serial_number LIKE ? ESCAPE '!'", [$like]];

        if ($hasSerials) {
            $ctes[] = "s as (select id from product_serial_numbers where serial_number LIKE ? ESCAPE '!')";
            $cteBindings[] = $like;

            $branches[] = ['select transfer_id from transfer_approval_allocation_serials where product_serial_number_id in (select id from s)', []];

            if ($sqlite) {
                $branches[] = ["select r.transfer_id from transfer_request_revisions r, json_tree(r.`lines`) t where t.key = 'id' and t.value in (select id from s)", []];
                foreach (['serial_numbers', 'dispatched_serial_numbers'] as $column) {
                    $branches[] = ["select tp.transfer_id from transfer_products tp, json_each(tp.{$column}) j where (j.value in (select id from s) or json_extract(j.value, '\$.id') in (select id from s))", []];
                }
            } else {
                $branches[] = ["select r.transfer_id from transfer_request_revisions r, json_table(r.`lines`, '\$[*].serials[*]' columns (sid bigint path '\$.id')) jt where jt.sid in (select id from s)", []];
                foreach (['serial_numbers', 'dispatched_serial_numbers'] as $column) {
                    // Elements are bare ids (legacy) or {id, serial_number} objects (v3
                    // drafts): read each as a scalar (a) and via $.id (b); a failed read is NULL.
                    $branches[] = ["select tp.transfer_id from transfer_products tp, json_table(tp.{$column}, '\$[*]' columns (a bigint path '\$' null on error null on empty, b bigint path '\$.id' null on error null on empty)) jt where tp.{$column} is not null and (jt.a in (select id from s) or jt.b in (select id from s))", []];
                }
            }
        }

        $union = implode(' union ', array_column($branches, 0));
        $with = $ctes ? 'with ' . implode(', ', $ctes) . ' ' : '';
        $bindings = array_merge($cteBindings, ...array_column($branches, 1));

        // Resolved once per request into a connection-local temporary table,
        // filled server-side so hit ids never pass through PHP and the count
        // and page queries stay small however many transfers match. The
        // table must outlive this method (count and page queries run later),
        // so it is dropped on request termination, and immediately if
        // building it fails. Connections are not persistent in the supported
        // runtimes, so closing the connection also discards it.
        $table = 'tmp_transfer_search_' . bin2hex(random_bytes(6));
        $connection = DB::connection();
        $drop = fn () => $connection->statement('drop ' . ($sqlite ? '' : 'temporary ') . "table if exists {$table}");

        try {
            $connection->statement("create temporary table {$table} (transfer_id bigint not null primary key)");
            app()->terminating($drop);
            $connection->insert("insert into {$table} (transfer_id) select transfer_id from ({$with}{$union}) as hit_ids", $bindings);
        } catch (\Throwable $e) {
            $drop();

            throw $e;
        }

        $query->whereIn('transfers.id', fn ($sub) => $sub->select('transfer_id')->from($table));
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

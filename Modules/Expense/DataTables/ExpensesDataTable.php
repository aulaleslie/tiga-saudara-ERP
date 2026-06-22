<?php

namespace Modules\Expense\DataTables;

use Modules\Expense\Entities\Expense;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ExpensesDataTable extends DataTable
{

    public function dataTable($query) {
        return datatables()
            ->eloquent($query)
            ->addColumn('amount', function ($data) {
                return format_currency($data->amount);
            })
            ->addColumn('supplier', function ($data) {
                return $data->supplier ? $data->supplier->supplier_name : '-';
            })
            ->addColumn('tags', function ($data) {
                if ($data->tags->isEmpty()) return '-';
                $locale = app()->getLocale();
                $badges = [];
                foreach ($data->tags as $tag) {
                    $nameData = is_string($tag->name) ? json_decode($tag->name, true) : $tag->name;
                    $name = is_array($nameData) ? ($nameData[$locale] ?? ($nameData['en'] ?? reset($nameData))) : (string) $tag->name;
                    $badges[] = '<span class="badge badge-secondary">' . e($name) . '</span>';
                }
                return implode(' ', $badges);
            })
            ->addColumn('status', function ($data) {
                $badges = [
                    'DRAFT' => 'badge-secondary',
                    'SUBMITTED' => 'badge-primary',
                    'APPROVED' => 'badge-success',
                    'REJECTED' => 'badge-danger',
                ];
                $labels = [
                    'DRAFT' => 'Draft',
                    'SUBMITTED' => 'Diajukan',
                    'APPROVED' => 'Disetujui',
                    'REJECTED' => 'Ditolak',
                ];
                $badgeClass = $badges[$data->status] ?? 'badge-info';
                $label = $labels[$data->status] ?? $data->status;

                $html = '<span class="badge ' . $badgeClass . '">' . $label . '</span>';
                if ($data->archived_at) {
                    $html .= '<br><span class="badge badge-dark mt-1">Diarsipkan</span>';
                }
                return $html;
            })
            ->addColumn('action', function ($data) {
                return view('expense::expenses.partials.actions', compact('data'));
            })
            ->rawColumns(['status', 'action', 'tags']);
    }

    public function query(Expense $model) {
        $currentSettingId = session('setting_id');
        $query = $model->newQuery()->where('setting_id', $currentSettingId)->with(['category', 'supplier', 'tags']);

        if (request()->has('status') && request('status') != '') {
            $query->where('status', request('status'));
        }

        if (request('archived') == '1') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        return $query;
    }

    public function html() {
        return $this->builder()
            ->setTableId('expenses-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                'tr' .
                                <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
            ->orderBy(9)
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
            Column::make('date')
                ->title('Tanggal')
                ->className('text-center align-middle'),

            Column::make('reference')
                ->title('Referensi')
                ->className('text-center align-middle'),

            Column::make('category.category_name')
                ->title('Kategori')
                ->className('text-center align-middle'),

            Column::computed('supplier')
                ->title('Supplier')
                ->className('text-center align-middle'),

            Column::computed('tags')
                ->title('Tags')
                ->className('text-center align-middle'),

            Column::computed('amount')
                ->title('Jumlah')
                ->className('text-center align-middle'),

            Column::make('details')
                ->title('Rincian')
                ->className('text-center align-middle'),

            Column::computed('status')
                ->title('Status')
                ->className('text-center align-middle'),

            Column::computed('action')
                ->title('Aksi')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle'),

            Column::make('created_at')
                ->visible(false)
        ];
    }

    protected function filename(): string {
        return 'Expenses_' . date('YmdHis');
    }
}

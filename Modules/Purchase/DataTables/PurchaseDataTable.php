<?php

namespace Modules\Purchase\DataTables;

use Modules\Purchase\Entities\Purchase;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class PurchaseDataTable extends DataTable
{

    public function dataTable($query) {
        return datatables()
            ->eloquent($query)
            ->addColumn('reference_hyperlink', function ($data) {
                return '<a href="' . route('purchases.show', $data->id) . '" class="text-primary">' . $data->reference . '</a>';
            })
            ->addColumn('total_amount', function ($data) {
                return format_currency($data->total_amount);
            })
            ->addColumn('paid_amount', function ($data) {
                return format_currency($data->paid_amount);
            })
            ->addColumn('due_amount', function ($data) {
                return format_currency($data->due_amount);
            })
            ->addColumn('status', function ($data) {
                return view('purchase::partials.status', compact('data'));
            })
            ->addColumn('payment_status', function ($data) {
                return view('purchase::partials.payment-status', compact('data'));
            })
            ->addColumn('action', function ($data) {
                return view('purchase::partials.actions', compact('data'));
            })
            ->rawColumns(['reference_hyperlink']);
    }

    public function query(Purchase $model) {
        $query = $model->newQuery()->with('supplier');

        if ($this->request()->has('supplier_id') && $this->request()->get('supplier_id')) {
            $supplier_id = $this->request()->get('supplier_id');
            $query->where('supplier_id', $supplier_id);
        }

        return $query;
    }

    public function html() {
        return $this->builder()
            ->setTableId('purchases-table')
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
            Column::make('reference')
                ->visible(false),

            Column::make('reference_hyperlink')
                ->title('Referensi')
                ->className('text-center align-middle'),

            Column::make('supplier.supplier_name')
                ->title('Pemasok')
                ->className('text-center align-middle'),

            Column::computed('status')
                ->title('Status')
                ->className('text-center align-middle'),

            Column::computed('total_amount')
                ->title('Jumlah Total')
                ->className('text-center align-middle'),

            Column::computed('paid_amount')
                ->title('Jumlah yang Dibayar')
                ->className('text-center align-middle'),

            Column::computed('due_amount')
                ->title('Jumlah Jatuh Tempo')
                ->className('text-center align-middle'),

            Column::computed('payment_status')
                ->title('Status Pembayaran')
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
        return 'Purchase_' . date('YmdHis');
    }
}

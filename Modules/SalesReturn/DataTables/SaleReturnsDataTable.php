<?php

namespace Modules\SalesReturn\DataTables;

use Modules\SalesReturn\Entities\SaleReturn;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class SaleReturnsDataTable extends DataTable
{

    public function dataTable($query) {
        return datatables()
            ->eloquent($query)
            ->filter(function ($query) {
                if ($search = $this->request()->get('search')['value'] ?? null) {
                    $query->where(function ($q) use ($search) {
                        $q->where('reference', 'like', "%{$search}%")
                            ->orWhere('sale_reference', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%")
                            ->orWhereHas('customer', function ($q2) use ($search) {
                                $q2->where('customer_name', 'like', "%{$search}%")
                                   ->orWhere('contact_name', 'like', "%{$search}%");
                            })
                            ->orWhereHas('sale', function ($q2) use ($search) {
                                $q2->where('reference', 'like', "%{$search}%")
                                   ->orWhere('imported_sales_reference_number', 'like', "%{$search}%")
                                   ->orWhere('tax_ref_no', 'like', "%{$search}%");
                            })
                            ->orWhereHas('saleReturnDetails', function ($q2) use ($search) {
                                $q2->where('product_name', 'like', "%{$search}%")
                                   ->orWhere('product_code', 'like', "%{$search}%");
                            });
                    });
                }
            }, false)
            ->editColumn('reference', function ($data) {
                return '<a href="' . route('sale-returns.show', $data->id) . '">' . $data->reference . '</a>';
            })
            ->addColumn('total_amount', fn ($data) => format_currency($data->total_amount))
            ->addColumn('paid_amount', fn ($data) => format_currency($data->paid_amount))
            ->addColumn('due_amount', fn ($data) => format_currency($data->due_amount))
            ->addColumn('status', fn ($data) => view('salesreturn::partials.status', compact('data')))
            ->addColumn('approval_status', fn ($data) => view('salesreturn::partials.approval-status', compact('data')))
            ->addColumn('payment_status', fn ($data) => view('salesreturn::partials.payment-status', compact('data')))
            ->addColumn('settlement_status', fn ($data) => view('salesreturn::partials.settlement-status', compact('data')))
            ->addColumn('action', fn ($data) => view('salesreturn::partials.actions', compact('data')))
            ->rawColumns(['reference', 'status', 'approval_status', 'payment_status', 'settlement_status', 'action']);
    }

    public function query(SaleReturn $model) {
        $query = $model->newQuery()
            ->with(['sale', 'customer', 'saleReturnDetails']);

        return $query;
    }

    public function html() {
        return $this->builder()
            ->setTableId('sale-returns-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                'tr' .
                                <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
            ->orderBy(10)
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
                ->title('Referensi')
                ->className('text-center align-middle'),

            Column::make('sale_reference')
                ->title('Ref Penjualan')
                ->className('text-center align-middle'),

            Column::make('customer_name')
                ->title('Pelanggan')
                ->className('text-center align-middle'),

            Column::computed('status')
                ->title('Status')
                ->className('text-center align-middle'),

            Column::computed('approval_status')
                ->title('Persetujuan')
                ->className('text-center align-middle'),

            Column::computed('total_amount')
                ->title('Total')
                ->className('text-center align-middle'),

            Column::computed('paid_amount')
                ->title('Dibayar')
                ->className('text-center align-middle'),

            Column::computed('due_amount')
                ->title('Kurang')
                ->className('text-center align-middle'),

            Column::computed('payment_status')
                ->title('Status Pembayaran')
                ->className('text-center align-middle'),

            Column::computed('settlement_status')
                ->title('Penyelesaian')
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
        return 'SaleReturns_' . date('YmdHis');
    }
}

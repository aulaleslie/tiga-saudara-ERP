<?php

namespace Modules\Sale\DataTables;

use Illuminate\Support\Facades\Log;
use Modules\Sale\Entities\SalePayment;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class SalePaymentsDataTable extends DataTable
{

    public function dataTable($query)
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('amount', function ($data) {
                return format_currency($data->amount);
            })
            ->addColumn('payment_method', function ($data) {
                // Display the payment method name
                return $data->paymentMethod ? $data->paymentMethod->name : 'N/A';
            })
            ->addColumn('credit_usage', function ($data) {
                $totalCredit = $data->creditApplications->sum('amount');

                return $totalCredit > 0 ? format_currency($totalCredit) : '-';
            })
            ->addColumn('attachment', function ($data) {
                // Check if there is a file attached
                if ($data->getMedia('attachments')->isNotEmpty()) {
                    $media = $data->getFirstMediaUrl('attachments');

                    Log::info('Attachment found for SalePayment', [
                        'sale_payment_id' => $data->id,
                        'media_url' => $media,
                    ]);

                    // Return the HTML link with the full URL
                    return '<a href="' . $media . '" class="text-primary" target="_blank">Lihat Lampiran</a>';
                }
                return 'No Attachment';
            })
            ->addColumn('action', function ($data) {
                return view('sale::payments.partials.actions', compact('data'));
            })
            ->rawColumns(['attachment', 'action']); // Allow raw HTML for the "attachment" and "action" columns
    }

    public function query(SalePayment $model)
    {
        $saleId = $this->sale_id;

        return $model->newQuery()
            ->when($saleId, fn($q) => $q->where('sale_id', $saleId))
            ->with(['sale', 'paymentMethod', 'creditApplications.customerCredit'])
            ->withCount('creditApplications');
    }

    public function html() {
        return $this->builder()
            ->setTableId('sale-payments-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
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

    protected function getColumns() {
        return [
            Column::make('date')
                ->title('Tanggal')
                ->className('align-middle text-center'),

            Column::make('reference')
                ->title('Referensi')
                ->className('align-middle text-center'),

            Column::computed('amount')
                ->title('Jumlah Pembayaran')
                ->className('align-middle text-center'),

            Column::make('payment_method')
                ->data('payment_method')
                ->title('Metode Pembayaran')
                ->className('align-middle text-center'),

            Column::computed('credit_usage')
                ->title('Kredit Terpakai')
                ->className('align-middle text-center'),

            Column::computed('attachment')
                ->title('Lampiran')
                ->exportable(false)
                ->printable(false)
                ->className('align-middle text-center'),

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->className('align-middle text-center'),

            Column::make('created_at')
                ->visible(false),
        ];
    }

    protected function filename(): string {
        return 'SalePayments_' . date('YmdHis');
    }
}

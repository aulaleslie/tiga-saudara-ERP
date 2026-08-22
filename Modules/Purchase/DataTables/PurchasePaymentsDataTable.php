<?php

namespace Modules\Purchase\DataTables;

use Illuminate\Support\Facades\Log;
use Modules\Purchase\Entities\PurchasePayment;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class PurchasePaymentsDataTable extends DataTable
{
    protected $globalMode = false;

    public function with(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            if (isset($key['globalMode'])) {
                $this->globalMode = $key['globalMode'];
                unset($key['globalMode']);
            }
            return parent::with($key);
        }
        return parent::with($key, $value);
    }

    public function dataTable($query)
    {
        return datatables()
            ->eloquent($query)
            ->editColumn('date', function ($data) {
                return $data->date ? $data->date->format('d M, Y') : '';
            })
            ->addColumn('amount', function ($data) {
                return format_currency($data->amount);
            })
            ->addColumn('payment_method', function ($data) {
                // Display the payment method name
                return $data->paymentMethod ? $data->paymentMethod->name : 'N/A';
            })
            ->addColumn('note', function ($data) {
                return $data->note !== null && trim($data->note) !== ''
                    ? (string) $data->note
                    : '-';
            })
            ->addColumn('attachment', function ($data) {
                // Check if there is a file attached
                if ($data->getMedia('attachments')->isNotEmpty()) {
                    $media = $data->getFirstMediaUrl('attachments');

                    Log::info('Attachment found for PurchasePayment', [
                        'purchase_payment_id' => $data->id,
                        'media_url' => $media,
                    ]);

                    // Return the HTML link with the full URL
                    return '<a href="' . $media . '" class="text-primary" target="_blank">Lihat Lampiran</a>';
                }
                return 'No Attachment';
            })
            ->addColumn('action', function ($data) {
                $globalMode = $this->globalMode ?: request()->routeIs('datatable.global_purchase_payments');
                return view('purchase::payments.partials.actions', compact('data', 'globalMode'));
            })
            ->addColumn('status', function ($data) {
                $badgeType = $data->isActive() ? 'success' : 'danger';
                return '<span class="badge badge-' . $badgeType . '">' . $data->status . '</span>';
            })
            ->rawColumns(['attachment', 'action', 'status']); // Allow raw HTML for the "attachment", "action", and "status" columns
    }

    public function query(PurchasePayment $model) {
        $purchaseId = $this->purchase_id ?? request()->route('purchase_id');

        return $model->newQuery()
            ->when($purchaseId, fn($q) => $q->where('purchase_id', $purchaseId))
            ->with(['purchase', 'paymentMethod']);
    }

    public function html() {
        return $this->builder()
            ->setTableId('purchase-payments-table')
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

            Column::computed('note')
                ->title('Catatan')
                ->className('align-middle payment-note'),

            Column::computed('attachment')
                ->title('Lampiran')
                ->exportable(false)
                ->printable(false)
                ->className('align-middle text-center'),

            Column::make('status')
                ->title('Status')
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
        return 'PurchasePayments_' . date('YmdHis');
    }
}

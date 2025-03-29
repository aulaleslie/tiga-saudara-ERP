<?php

namespace Modules\Sale\DataTables;

use Modules\Sale\Entities\Sale;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class SalesDataTable extends DataTable
{
    public function dataTable($query)
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('reference_hyperlink', function ($data) {
                $reference = '<a href="' . route('sales.show', $data->id) . '" class="text-primary">' . $data->reference . '</a>';
                if (!empty($data->note)) {
                    $note = nl2br(e($data->note));
                    $lineCount = substr_count($data->note, "\n") + 1;
                    $characterCount = strlen($data->note);
                    if ($lineCount > 1 || $characterCount > 10) {
                        $noteHtml = '<div class="note-wrapper" style="max-height: 40px; overflow: hidden; transition: max-height 0.3s;">
                            <p class="note-content mb-0">' . $note . '</p>
                        </div>
                        <a href="javascript:void(0);" class="toggle-note" style="color: blue; text-decoration: underline; cursor: pointer;">Lihat selengkapnya</a>';
                    } else {
                        $noteHtml = '<p class="note-content mb-0">' . $note . '</p>';
                    }
                    return $reference . '<br>' . $noteHtml;
                }
                return $reference;
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
                return view('sale::partials.status', compact('data'));
            })
            ->addColumn('payment_status', function ($data) {
                return view('sale::partials.payment-status', compact('data'));
            })
            ->addColumn('action', function ($data) {
                return view('sale::partials.actions', compact('data'));
            })
            ->rawColumns(['reference_hyperlink']);
    }

    public function query(Sale $model)
    {
        // Load customer relationship.
        return $model->newQuery()->with('customer')->orderBy('id', 'desc');
    }

    public function html()
    {
        return $this->builder()
            ->setTableId('sales-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                  'tr' .
                  <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
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

    protected function getColumns()
    {
        return [
            Column::make('reference')
                ->visible(false),
            Column::make('note')
                ->visible(false),
            Column::computed('reference_hyperlink')
                ->title('Referensi')
                ->className('text-center align-middle'),
            // Use the customer relation to display customer name.
            Column::make('customer.contact_name')
                ->title('Customer')
                ->className('text-center align-middle'),
            Column::computed('status')
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
                ->searchable(false)
        ];
    }

    protected function filename(): string
    {
        return 'Sales_' . date('YmdHis');
    }
}

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
                $reference = '<a href="' . route('purchases.show', $data->id) . '" class="text-primary">' . $data->reference . '</a>';

                // Check if the note exists
                if (!empty($data->note)) {
                    $note = nl2br(e($data->note)); // Convert newlines to <br> tags

                    // Count the number of lines in the note
                    $lineCount = substr_count($data->note, "\n") + 1; // Lines are determined by newline characters
                    $characterCount = strlen($data->note);

                    if ($lineCount > 1 || $characterCount > 10) {
                        // HTML structure for collapsible behavior
                        $noteHtml = '<div class="note-wrapper" style="max-height: 40px; overflow: hidden; transition: max-height 0.3s;">
                            <p class="note-content mb-0">' . $note . '</p>
                         </div>
                         <a href="javascript:void(0);" class="toggle-note" style="color: blue; text-decoration: underline; cursor: pointer;">Lihat selengkapnya</a>';
                    } else {
                        // Show the note as is if it only has one line
                        $noteHtml = '<p class="note-content mb-0">' . $note . '</p>';
                    }

                    return $reference . '<br>' . $noteHtml;
                }

                // If no note, just return the reference
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
                return view('purchase::partials.status', compact('data'));
            })
            ->addColumn('payment_status', function ($data) {
                return view('purchase::partials.payment-status', compact('data'));
            })
            ->addColumn('tags', function ($data) {
                return $data->tags->map(function ($tag) {
                    return '<span class="badge bg-info text-white fs-6 me-1">' . e($tag->name) . '</span>';
                })->implode(' ');
            })
            ->addColumn('action', function ($data) {
                return view('purchase::partials.actions', compact('data'));
            })
            ->rawColumns(['reference_hyperlink', 'tags']);
    }

    public function query(Purchase $model)
    {
        $query = $model->newQuery()->with(['supplier', 'tags']);

        if ($this->request()->has('supplier_id') && $this->request()->get('supplier_id')) {
            $supplier_id = $this->request()->get('supplier_id');
            $query->where('supplier_id', $supplier_id);
        }

        // Handle global search on tags (MySQL-compatible)
        if ($search = $this->request()->get('search')['value'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('supplier', function ($q) use ($search) {
                        $q->where('supplier_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('tags', function ($q) use ($search) {
                        $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.\"en\"')) LIKE ?", ["%{$search}%"]);
                    });
            });
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
                ->visible(false),

            Column::make('note')
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

            Column::computed('tags')
                ->title('Tag')
                ->className('text-center align-middle')
                ->orderable(false)
                ->searchable(false),

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

    protected function filename(): string {
        return 'Purchase_' . date('YmdHis');
    }
}

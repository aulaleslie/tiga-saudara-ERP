<?php

namespace Modules\Purchase\DataTables;

use Illuminate\Support\Facades\Log;
use Modules\Purchase\Entities\Purchase;
use Modules\People\Entities\Supplier;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class PurchaseDataTable extends DataTable
{
    public function dataTable($query) {
        return datatables()
            ->eloquent($query)
            ->addColumn('supplier_name', function ($data) {
                return $data->supplier->supplier_name ?? 'N/A';
            })
            ->addColumn('total_amount', function ($data) {
                return $this->formatCurrency($data->total_amount);
            })
            ->addColumn('paid_amount', function ($data) {
                return $this->formatCurrency($data->paid_amount);
            })
            ->addColumn('due_amount', function ($data) {
                return $this->formatCurrency($data->due_amount);
            })
            ->addColumn('status', function ($data) {
                return view('purchase::partials.status', compact('data'));
            })
            ->addColumn('payment_status', function ($data) {
                return view('purchase::partials.payment-status', compact('data'));
            })
            ->addColumn('action', function ($data) {
                return view('purchase::partials.actions', compact('data'));
            });
    }

    public function query(Purchase $model)
    {
        $query = $model->newQuery()
            ->with('supplier') // Include the supplier relationship
            ->select('purchases.*'); // Select all columns from the purchases table

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
            ->orderBy(8)
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
                ->className('text-center align-middle'),

            Column::make('supplier_name') // Supplier name column
            ->title('Supplier')
                ->className('text-center align-middle'),

            Column::computed('status')
                ->className('text-center align-middle'),

            Column::computed('total_amount')
                ->className('text-center align-middle'),

            Column::computed('paid_amount')
                ->className('text-center align-middle'),

            Column::computed('due_amount')
                ->className('text-center align-middle'),

            Column::computed('payment_status')
                ->className('text-center align-middle'),

            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle'),

            Column::make('created_at')
                ->visible(false),
        ];
    }

    protected function filename(): string {
        return 'Purchase_' . date('YmdHis');
    }

    private function formatCurrency($value, $scale = 1) {
        // Fetch settings using the provided setting ID or session('setting_id')
        $settings = settings();

        Log::info('Settings Retrieved:', [
            'position' => $settings->default_currency_position ?? 'prefix',
            'symbol' => $settings->currency->symbol ?? '$',
            'decimal_separator' => $settings->currency->decimal_separator ?? '.',
            'thousand_separator' => $settings->currency->thousand_separator ?? ',',
            'value' => $value
        ]);

        $position = $settings->default_currency_position ?? 'prefix';
        $symbol = $settings->currency->symbol ?? '$';
        $decimal_separator = $settings->currency->decimal_separator ?? '.';
        $thousand_separator = $settings->currency->thousand_separator ?? ',';

        // Adjust for scaling (e.g., converting cents to full value)
        $value = $value / $scale;

        // Format the currency value based on position
        if ($position == 'prefix') {
            return $symbol . number_format($value, 2, $decimal_separator, $thousand_separator);
        } else {
            return number_format($value, 2, $decimal_separator, $thousand_separator) . $symbol;
        }
    }
}

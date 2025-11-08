<?php

namespace Modules\Sale\DataTables;

use Modules\Sale\Entities\Sale;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class GlobalSalesSearchDataTable extends DataTable
{
    protected $searchResults;

    public function __construct($searchResults = null)
    {
        $this->searchResults = $searchResults;
    }

    public function dataTable($query)
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('reference_hyperlink', function ($data) {
                return '<a href="' . route('sales.show', $data->id) . '" class="text-primary" target="_blank">' . $data->reference . '</a>';
            })
            ->addColumn('customer_name', function ($data) {
                return $data->customer->name ?? 'N/A';
            })
            ->addColumn('serial_numbers_count', function ($data) {
                $count = $data->details->sum(function($detail) {
                    return is_array($detail->serial_number_ids) ? count($detail->serial_number_ids) : 0;
                });
                return '<span class="badge badge-info">' . $count . ' serials</span>';
            })
            ->addColumn('tenant_name', function ($data) {
                return $data->setting->name ?? 'N/A';
            })
            ->addColumn('seller_name', function ($data) {
                return $data->user->name ?? 'N/A';
            })
            ->addColumn('status_badge', function ($data) {
                return view('sale::partials.status-badge', compact('data'));
            })
            ->addColumn('formatted_date', function ($data) {
                return $data->created_at->format('M d, Y');
            })
            ->addColumn('action', function ($data) {
                return view('sale::partials.global-sales-search-actions', compact('data'));
            })
            ->rawColumns(['reference_hyperlink', 'serial_numbers_count', 'status_badge', 'action']);
    }

    public function query(Sale $model)
    {
        // If we have pre-filtered results, use them
        if ($this->searchResults) {
            return $this->searchResults;
        }

        // Default query with relationships
        $settingId = session('setting_id');
        return $model->newQuery()
            ->with(['customer', 'details', 'setting', 'user'])
            ->where('setting_id', $settingId)
            ->orderBy('created_at', 'desc');
    }

    public function html()
    {
        return $this->builder()
            ->setTableId('global-sales-search-table')
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
            )
            ->parameters([
                'responsive' => true,
                'autoWidth' => false,
                'scrollX' => true,
            ]);
    }

    protected function getColumns()
    {
        return [
            Column::computed('reference_hyperlink')
                ->title('Reference')
                ->className('text-center align-middle')
                ->searchable(true)
                ->orderable(true),

            Column::computed('customer_name')
                ->title('Customer')
                ->className('text-center align-middle')
                ->searchable(true)
                ->orderable(false),

            Column::computed('serial_numbers_count')
                ->title('Serial Numbers')
                ->className('text-center align-middle')
                ->searchable(false)
                ->orderable(false),

            Column::computed('tenant_name')
                ->title('Tenant')
                ->className('text-center align-middle')
                ->searchable(true)
                ->orderable(false),

            Column::computed('seller_name')
                ->title('Seller')
                ->className('text-center align-middle')
                ->searchable(true)
                ->orderable(false),

            Column::computed('status_badge')
                ->title('Status')
                ->className('text-center align-middle')
                ->searchable(false)
                ->orderable(true),

            Column::computed('formatted_date')
                ->title('Date')
                ->className('text-center align-middle')
                ->searchable(false)
                ->orderable(true),

            Column::computed('action')
                ->title('Actions')
                ->exportable(false)
                ->printable(false)
                ->className('text-center align-middle')
                ->searchable(false)
                ->orderable(false),
        ];
    }

    protected function filename(): string
    {
        return 'Global_Sales_Search_' . date('YmdHis');
    }

    /**
     * Set the search results for this DataTable
     */
    public function setSearchResults($results)
    {
        $this->searchResults = $results;
        return $this;
    }
}
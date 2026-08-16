<?php

namespace Modules\Purchase\DataTables;

use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class PurchaseReceivingsDataTable extends DataTable
{
    public function dataTable($query)
    {
        return datatables()
            ->eloquent($query)
            ->addColumn('expand', function () {
                return '<button class="btn btn-sm btn-outline-primary toggle-details">
                        <i class="bi bi-plus-circle"></i>
                    </button>';
            })
            ->addColumn('received_note_id', function ($data) {
                return $data->id;
            })
            ->addColumn('external_delivery_number', function ($data) {
                return $data->external_delivery_number ?? '-';
            })
            ->addColumn('internal_invoice_number', function ($data) {
                return $data->internal_invoice_number ?? '-';
            })
            ->addColumn('date', function ($data) {
                return optional($data->created_at)->format('Y-m-d');
            })
            ->addColumn('quantity_received', function ($data) {
                return $data->receivedNoteDetails->sum('quantity_received');
            })
            ->addColumn('details', function ($data) {
                $detailIds = $data->receivedNoteDetails->pluck('id');
                $purchaseId = (int) ($data->po_id ?? 0);

                $resolver = new \Modules\Purchase\Services\ReturnedSerialNumberResolver();
                $returnedSerials = $resolver->resolveForPurchase($purchaseId, $detailIds);
                $resolver->mapToDetails($data->receivedNoteDetails, $returnedSerials);

                return view('purchase::receivings.receiving-details', compact('data'))->render();
            })
            ->addColumn('supplier_purchase_number', function ($data) {
                return $data->purchase->supplier_purchase_number ?? '-';
            })
            ->rawColumns(['expand', 'details']);
    }

    public function query(ReceivedNote $model)
    {
        return $model->newQuery()
            ->byPurchase()
            ->with([
                'purchase',
                'receivedNoteDetails.purchaseDetail',
                'receivedNoteDetails.product',
                'receivedNoteDetails.productSerialNumbers',
                'receivedNoteDetails.uomNormalizationLines.batch.oldBaseUnit',
                'receivedNoteDetails.uomNormalizationLines.batch.newBaseUnit',
                'receivedNoteDetails.uomNormalizationLines.batch.legacyBaseUnit',
            ]);
    }

    public function html()
    {
        return $this->builder()
            ->setTableId('purchase-receivings-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->dom("<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4'f>> .
                                'tr' .
                                <'row'<'col-md-5'i><'col-md-7 mt-2'p>>")
            ->orderBy(3)
            ->buttons(
                Button::make('excel')->text('<i class="bi bi-file-earmark-excel-fill"></i> Excel'),
                Button::make('print')->text('<i class="bi bi-printer-fill"></i> Print'),
                Button::make('reset')->text('<i class="bi bi-x-circle"></i> Reset'),
                Button::make('reload')->text('<i class="bi bi-arrow-repeat"></i> Reload')
            );
    }

    protected function getColumns()
    {
        return [
            Column::computed('expand')->title('')->exportable(false)->printable(false)->className('align-middle text-center'),
            Column::make('received_note_id')->title('ID')->className('align-middle text-center'),
            Column::computed('supplier_purchase_number')->title('No. Pembelian Supplier')->className('align-middle text-center'),
            Column::make('external_delivery_number')->title('No. Delivery')->className('align-middle text-center'),
            Column::make('internal_invoice_number')->title('No. Invoice')->className('align-middle text-center'),
            Column::make('date')->title('Tanggal')->className('align-middle text-center'),
            Column::computed('quantity_received')->title('Total Diterima')->className('align-middle text-center'),
        ];
    }

    protected function filename(): string
    {
        return 'PurchaseReceivings_' . date('YmdHis');
    }
}

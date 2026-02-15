<?php

namespace Modules\Purchase\DataTables;

use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
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

                $receivedSerialIds = collect();
                if ($detailIds->isNotEmpty()) {
                    $receivedSerialIds = ProductSerialNumber::query()
                        ->where(function ($query) use ($detailIds) {
                            $query->whereIn('received_note_detail_id', $detailIds)
                                ->orWhereIn('id', function ($subQuery) use ($detailIds) {
                                    $subQuery->select('product_serial_number_id')
                                        ->from('serial_number_histories')
                                        ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
                                        ->where('reference_type', ReceivedNoteDetail::class)
                                        ->whereIn('reference_id', $detailIds);
                                });
                        })
                        ->pluck('id');
                }

                $returnedSerialsByHistory = collect();
                if ($receivedSerialIds->isNotEmpty() && $purchaseId > 0) {
                    $returnedSerialsByHistory = ProductSerialNumber::query()
                        ->whereIn('id', $receivedSerialIds)
                        ->whereIn('id', function ($query) use ($purchaseId) {
                            $query->select('product_serial_number_id')
                                ->from('serial_number_histories')
                                ->where('event_type', SerialNumberHistory::EVENT_PURCHASE_RETURNED)
                                ->where(function ($q) use ($purchaseId) {
                                    $q->where(function ($q1) use ($purchaseId) {
                                        $q1->where('reference_type', PurchaseReturn::class)
                                            ->whereIn('reference_id', function ($sub) use ($purchaseId) {
                                                $sub->select('purchase_return_id')
                                                    ->from('purchase_return_details')
                                                    ->where('po_id', $purchaseId);
                                            });
                                    })
                                    ->orWhere(function ($q2) use ($purchaseId) {
                                        $q2->where('reference_type', PurchaseReturnItemSettlement::class)
                                            ->whereIn('reference_id', function ($sub) use ($purchaseId) {
                                                $sub->select('id')
                                                    ->from('purchase_return_item_settlements')
                                                    ->whereIn('purchase_return_detail_id', function ($sub2) use ($purchaseId) {
                                                        $sub2->select('id')
                                                            ->from('purchase_return_details')
                                                            ->where('po_id', $purchaseId);
                                                    });
                                            });
                                    });
                                });
                        })
                        ->get();
                }

                $returnedSerialsByState = collect();
                if ($receivedSerialIds->isNotEmpty() && $purchaseId > 0) {
                    $fallbackSerialIds = PurchaseReturnItemSettlement::query()
                        ->where('target_purchase_id', $purchaseId)
                        ->whereRaw('UPPER(method) = ?', ['MODIFY_PURCHASE'])
                        ->whereRaw('UPPER(status) = ?', [PurchaseReturnItemSettlement::STATUS_APPROVED])
                        ->whereNotNull('product_serial_number_id')
                        ->pluck('product_serial_number_id')
                        ->unique()
                        ->values();

                    if ($fallbackSerialIds->isNotEmpty()) {
                        $returnedSerialsByState = ProductSerialNumber::query()
                            ->whereIn('id', $fallbackSerialIds)
                            ->whereIn('id', $receivedSerialIds)
                            ->get();
                    }
                }

                $returnedSerials = $returnedSerialsByHistory
                    ->concat($returnedSerialsByState)
                    ->unique('id')
                    ->values();

                if ($returnedSerials->isNotEmpty()) {
                    $histories = SerialNumberHistory::query()
                        ->whereIn('product_serial_number_id', $returnedSerials->pluck('id'))
                        ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
                        ->where('reference_type', ReceivedNoteDetail::class)
                        ->get()
                        ->groupBy('reference_id');

                    $returnedByCurrentLink = $returnedSerials
                        ->filter(fn ($serial) => ! empty($serial->received_note_detail_id) && $detailIds->contains((int) $serial->received_note_detail_id))
                        ->groupBy(fn ($serial) => (int) $serial->received_note_detail_id);

                    foreach ($data->receivedNoteDetails as $detail) {
                        $returnedFromHistory = $histories->get($detail->id)
                            ? $returnedSerials->whereIn('id', $histories->get($detail->id)->pluck('product_serial_number_id'))
                            : collect([]);
                        $returnedFromCurrentLink = $returnedByCurrentLink->get($detail->id, collect([]));

                        $detail->returnedSerialNumbers = $returnedFromHistory
                            ->concat($returnedFromCurrentLink)
                            ->unique('id')
                            ->values();
                    }
                } else {
                    foreach ($data->receivedNoteDetails as $detail) {
                        $detail->returnedSerialNumbers = collect([]);
                    }
                }

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
                'receivedNoteDetails.productSerialNumbers'
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

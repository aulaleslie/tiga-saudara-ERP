<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Sale\Entities\DispatchDetail;

class StockMutationReportExport implements FromCollection, WithHeadings, WithEvents
{
    protected array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function collection(): Collection
    {
        $mutations = collect();
        $settingId = session('setting_id');
        $startDate = $this->filters['startDate'] ?? null;
        $endDate = $this->filters['endDate'] ?? null;
        $productId = $this->filters['productId'] ?? null;
        $locationId = $this->filters['locationId'] ?? null;
        $mutationType = $this->filters['mutationType'] ?? '';
        $isGlobal = $this->filters['isGlobal'] ?? false;

        // 1. Purchase Receivings (IN)
        if (empty($mutationType) || $mutationType === 'IN') {
            $receivings = ReceivedNoteDetail::with(['receivedNote.purchase', 'purchaseDetail.product'])
                ->whereHas('receivedNote', function ($q) use ($settingId, $startDate, $endDate, $isGlobal) {
                    $q->when($startDate, fn($q) => $q->whereDate('created_at', '>=', $startDate))
                        ->when($endDate, fn($q) => $q->whereDate('created_at', '<=', $endDate))
                        ->when(!$isGlobal, fn($q) => $q->whereHas('purchase', fn($q) => $q->where('setting_id', $settingId)));
                })
                ->when($productId, fn($q) => $q->whereHas('purchaseDetail', fn($pq) => $pq->where('product_id', $productId)))
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->receivedNote->created_at->format('d/m/Y'),
                        'product_code' => $item->purchaseDetail->product->product_code ?? '-',
                        'product_name' => $item->purchaseDetail->product->product_name ?? '-',
                        'location' => '-',
                        'type' => 'Penerimaan Pembelian',
                        'qty_in' => $item->quantity_received,
                        'qty_out' => 0,
                        'reference' => $item->receivedNote->purchase->reference ?? '-',
                    ];
                });
            $mutations = $mutations->concat($receivings);
        }

        // 2. Sale Dispatches (OUT)
        if (empty($mutationType) || $mutationType === 'OUT') {
            $dispatches = DispatchDetail::with(['dispatch.sale', 'product', 'location'])
                ->whereHas('dispatch', function ($q) use ($settingId, $startDate, $endDate, $isGlobal) {
                    $q->when($startDate, fn($q) => $q->whereDate('created_at', '>=', $startDate))
                        ->when($endDate, fn($q) => $q->whereDate('created_at', '<=', $endDate))
                        ->when(!$isGlobal, fn($q) => $q->whereHas('sale', fn($q) => $q->where('setting_id', $settingId)));
                })
                ->when($productId, fn($q) => $q->where('product_id', $productId))
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->dispatch->created_at->format('d/m/Y'),
                        'product_code' => $item->product->product_code ?? '-',
                        'product_name' => $item->product->product_name ?? '-',
                        'location' => $item->location->name ?? '-',
                        'type' => 'Pengiriman Penjualan',
                        'qty_in' => 0,
                        'qty_out' => $item->dispatched_quantity,
                        'reference' => $item->dispatch->sale->reference ?? '-',
                    ];
                });
            $mutations = $mutations->concat($dispatches);
        }

        // 3. Stock Transfers - Dispatched (OUT from origin)
        if (empty($mutationType) || $mutationType === 'OUT') {
            $transfersOut = TransferProduct::with(['transfer.originLocation', 'product'])
                ->whereHas('transfer', function ($q) use ($settingId, $startDate, $endDate, $isGlobal) {
                    $q->whereIn('status', ['DISPATCHED', 'RECEIVED', 'RETURN_DISPATCHED', 'RETURN_RECEIVED'])
                        ->when($startDate, fn($q) => $q->whereDate('dispatched_at', '>=', $startDate))
                        ->when($endDate, fn($q) => $q->whereDate('dispatched_at', '<=', $endDate))
                        ->when(!$isGlobal, fn($q) => $q->whereHas('originLocation', fn($q) => $q->where('setting_id', $settingId)));
                })
                ->when($productId, fn($q) => $q->where('product_id', $productId))
                ->when($locationId, fn($q) => $q->whereHas('transfer', fn($tq) => $tq->where('origin_location_id', $locationId)))
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->transfer->dispatched_at?->format('d/m/Y') ?? '-',
                        'product_code' => $item->product->product_code ?? '-',
                        'product_name' => $item->product->product_name ?? '-',
                        'location' => $item->transfer->originLocation->name ?? '-',
                        'type' => 'Transfer Keluar',
                        'qty_in' => 0,
                        'qty_out' => $item->dispatched_quantity ?? $item->quantity,
                        'reference' => $item->transfer->document_number ?? '-',
                    ];
                });
            $mutations = $mutations->concat($transfersOut);
        }

        // 4. Stock Transfers - Received (IN to destination)
        if (empty($mutationType) || $mutationType === 'IN') {
            $transfersIn = TransferProduct::with(['transfer.destinationLocation', 'product'])
                ->whereHas('transfer', function ($q) use ($settingId, $startDate, $endDate, $isGlobal) {
                    $q->whereIn('status', ['RECEIVED', 'RETURN_DISPATCHED', 'RETURN_RECEIVED'])
                        ->when($startDate, fn($q) => $q->whereDate('received_at', '>=', $startDate))
                        ->when($endDate, fn($q) => $q->whereDate('received_at', '<=', $endDate))
                        ->when(!$isGlobal, fn($q) => $q->whereHas('destinationLocation', fn($q) => $q->where('setting_id', $settingId)));
                })
                ->when($productId, fn($q) => $q->where('product_id', $productId))
                ->when($locationId, fn($q) => $q->whereHas('transfer', fn($tq) => $tq->where('destination_location_id', $locationId)))
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->transfer->received_at?->format('d/m/Y') ?? '-',
                        'product_code' => $item->product->product_code ?? '-',
                        'product_name' => $item->product->product_name ?? '-',
                        'location' => $item->transfer->destinationLocation->name ?? '-',
                        'type' => 'Transfer Masuk',
                        'qty_in' => $item->dispatched_quantity ?? $item->quantity,
                        'qty_out' => 0,
                        'reference' => $item->transfer->document_number ?? '-',
                    ];
                });
            $mutations = $mutations->concat($transfersIn);
        }

        // 5. Stock Adjustments
        $adjustments = AdjustedProduct::with(['adjustment', 'product'])
            ->whereHas('adjustment', function ($q) use ($settingId, $startDate, $endDate, $isGlobal) {
                $q->where('status', 'APPROVED')
                    ->when($startDate, fn($q) => $q->whereDate('updated_at', '>=', $startDate))
                    ->when($endDate, fn($q) => $q->whereDate('updated_at', '<=', $endDate))
                    ->when(!$isGlobal, fn($q) => $q->where('setting_id', $settingId));
            })
            ->when($productId, fn($q) => $q->where('product_id', $productId))
            ->get()
            ->filter(function ($item) use ($mutationType) {
                $isIn = ($item->type ?? '') === 'add';
                if ($mutationType === 'IN') return $isIn;
                if ($mutationType === 'OUT') return !$isIn;
                return true;
            })
            ->map(function ($item) {
                $isIn = ($item->type ?? '') === 'add';
                return [
                    'date' => $item->adjustment->updated_at->format('d/m/Y'),
                    'product_code' => $item->product->product_code ?? '-',
                    'product_name' => $item->product->product_name ?? '-',
                    'location' => '-',
                    'type' => $isIn ? 'Penyesuaian Tambah' : 'Penyesuaian Kurang',
                    'qty_in' => $isIn ? $item->quantity : 0,
                    'qty_out' => $isIn ? 0 : $item->quantity,
                    'reference' => $item->adjustment->reference ?? '-',
                ];
            });
        $mutations = $mutations->concat($adjustments);

        return $mutations->sortBy('date')->values();
    }

    public function headings(): array
    {
        return [
            'Tanggal',
            'Kode Produk',
            'Nama Produk',
            'Lokasi',
            'Tipe',
            'Qty Masuk',
            'Qty Keluar',
            'Referensi',
        ];
    }

    public function registerEvents(): array
    {
        $startDate = $this->filters['startDate'] ?? '-';
        $endDate = $this->filters['endDate'] ?? '-';

        return [
            AfterSheet::class => function (AfterSheet $event) use ($startDate, $endDate) {
                $sheet = $event->sheet->getDelegate();

                // Insert header rows at the top
                $sheet->insertNewRowBefore(1, 2);

                // Title row
                $sheet->setCellValue('A1', 'LAPORAN MUTASI STOK');
                $sheet->mergeCells('A1:H1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

                // Period row
                $periodText = sprintf(
                    'Periode: %s s/d %s',
                    $startDate ? date('d/m/Y', strtotime($startDate)) : '-',
                    $endDate ? date('d/m/Y', strtotime($endDate)) : '-'
                );
                $sheet->setCellValue('A2', $periodText);
                $sheet->mergeCells('A2:H2');
                $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

                // Style header row (now row 3)
                $sheet->getStyle('A3:H3')->getFont()->setBold(true);
            },
        ];
    }
}

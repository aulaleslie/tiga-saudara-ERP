<?php

namespace App\Livewire\Reports;

use App\Exports\StockMutationReportExport;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Setting\Entities\Location;
use Illuminate\Support\Collection;

class StockMutationReport extends Component
{
    use WithPagination;

    public $startDate, $endDate, $productId, $locationId, $mutationType;
    public $filterTriggered = false;

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->mutationType = '';
    }

    public function applyFilters()
    {
        $this->filterTriggered = true;
        $this->resetPage();
    }

    public function exportExcel()
    {
        $filters = $this->exportFilters();
        return Excel::download(new StockMutationReportExport($filters), 'laporan-mutasi-stok.xlsx');
    }

    public function exportCsv()
    {
        $filters = $this->exportFilters();
        return Excel::download(new StockMutationReportExport($filters), 'laporan-mutasi-stok.csv', \Maatwebsite\Excel\Excel::CSV);
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'productId' => $this->productId,
            'locationId' => $this->locationId,
            'mutationType' => $this->mutationType,
        ];
    }

    public function getMutationsProperty(): Collection
    {
        if (!$this->filterTriggered) {
            return collect();
        }

        $mutations = collect();
        $settingId = session('setting_id');

        // 1. Purchase Receivings (IN)
        if (empty($this->mutationType) || $this->mutationType === 'IN') {
            $receivings = ReceivedNoteDetail::with(['receivedNote.purchase', 'purchaseDetail.product'])
                ->whereHas('receivedNote', function ($q) use ($settingId) {
                    $q->when($this->startDate, fn($q) => $q->whereDate('created_at', '>=', $this->startDate))
                        ->when($this->endDate, fn($q) => $q->whereDate('created_at', '<=', $this->endDate))
                        ->whereHas('purchase', fn($q) => $q->where('setting_id', $settingId));
                })
                ->when($this->productId, fn($q) => $q->whereHas('purchaseDetail', fn($pq) => $pq->where('product_id', $this->productId)))
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->receivedNote->created_at->format('Y-m-d'),
                        'product_name' => $item->purchaseDetail->product->product_name ?? '-',
                        'product_code' => $item->purchaseDetail->product->product_code ?? '-',
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
        if (empty($this->mutationType) || $this->mutationType === 'OUT') {
            $dispatches = DispatchDetail::with(['dispatch.sale', 'product', 'location'])
                ->whereHas('dispatch', function ($q) use ($settingId) {
                    $q->when($this->startDate, fn($q) => $q->whereDate('created_at', '>=', $this->startDate))
                        ->when($this->endDate, fn($q) => $q->whereDate('created_at', '<=', $this->endDate))
                        ->whereHas('sale', fn($q) => $q->where('setting_id', $settingId));
                })
                ->when($this->productId, fn($q) => $q->where('product_id', $this->productId))
                ->when($this->locationId, fn($q) => $q->where('location_id', $this->locationId))
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->dispatch->created_at->format('Y-m-d'),
                        'product_name' => $item->product->product_name ?? '-',
                        'product_code' => $item->product->product_code ?? '-',
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
        if (empty($this->mutationType) || $this->mutationType === 'OUT') {
            $transfersOut = TransferProduct::with(['transfer.originLocation', 'product'])
                ->whereHas('transfer', function ($q) use ($settingId) {
                    $q->whereIn('status', ['DISPATCHED', 'RECEIVED', 'RETURN_DISPATCHED', 'RETURN_RECEIVED'])
                        ->when($this->startDate, fn($q) => $q->whereDate('dispatched_at', '>=', $this->startDate))
                        ->when($this->endDate, fn($q) => $q->whereDate('dispatched_at', '<=', $this->endDate))
                        ->whereHas('originLocation', fn($q) => $q->where('setting_id', $settingId));
                })
                ->when($this->productId, fn($q) => $q->where('product_id', $this->productId))
                ->when($this->locationId, fn($q) => $q->whereHas('transfer', fn($tq) => $tq->where('origin_location_id', $this->locationId)))
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->transfer->dispatched_at?->format('Y-m-d') ?? '-',
                        'product_name' => $item->product->product_name ?? '-',
                        'product_code' => $item->product->product_code ?? '-',
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
        if (empty($this->mutationType) || $this->mutationType === 'IN') {
            $transfersIn = TransferProduct::with(['transfer.destinationLocation', 'product'])
                ->whereHas('transfer', function ($q) use ($settingId) {
                    $q->whereIn('status', ['RECEIVED', 'RETURN_DISPATCHED', 'RETURN_RECEIVED'])
                        ->when($this->startDate, fn($q) => $q->whereDate('received_at', '>=', $this->startDate))
                        ->when($this->endDate, fn($q) => $q->whereDate('received_at', '<=', $this->endDate))
                        ->whereHas('destinationLocation', fn($q) => $q->where('setting_id', $settingId));
                })
                ->when($this->productId, fn($q) => $q->where('product_id', $this->productId))
                ->when($this->locationId, fn($q) => $q->whereHas('transfer', fn($tq) => $tq->where('destination_location_id', $this->locationId)))
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->transfer->received_at?->format('Y-m-d') ?? '-',
                        'product_name' => $item->product->product_name ?? '-',
                        'product_code' => $item->product->product_code ?? '-',
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
            ->whereHas('adjustment', function ($q) use ($settingId) {
                $q->where('status', 'APPROVED')
                    ->when($this->startDate, fn($q) => $q->whereDate('updated_at', '>=', $this->startDate))
                    ->when($this->endDate, fn($q) => $q->whereDate('updated_at', '<=', $this->endDate))
                    ->where('setting_id', $settingId);
            })
            ->when($this->productId, fn($q) => $q->where('product_id', $this->productId))
            ->get()
            ->filter(function ($item) {
                $isIn = ($item->type ?? '') === 'add';
                if ($this->mutationType === 'IN') return $isIn;
                if ($this->mutationType === 'OUT') return !$isIn;
                return true;
            })
            ->map(function ($item) {
                $isIn = ($item->type ?? '') === 'add';
                return [
                    'date' => $item->adjustment->updated_at->format('Y-m-d'),
                    'product_name' => $item->product->product_name ?? '-',
                    'product_code' => $item->product->product_code ?? '-',
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

    public function render()
    {
        $settingId = session('setting_id');

        return view('livewire.reports.stock-mutation-report', [
            'mutations' => $this->mutations,
            'products' => Product::orderBy('product_name')->get(),
            'locations' => Location::where('setting_id', $settingId)->orderBy('name')->get(),
        ]);
    }
}

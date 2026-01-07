<?php

namespace App\Livewire\Reports;

use App\Exports\InventoryValuationReportExport;
use Carbon\Carbon;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

class InventoryValuationReport extends Component
{
    public $startDate;
    public $endDate;
    public $productId;
    public $locationId;

    protected $rules = [
        'startDate' => 'required|date',
        'endDate' => 'required|date|after_or_equal:startDate',
    ];

    public function mount(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
    }

    public function exportExcel()
    {
        $this->validate();

        $start = Carbon::parse($this->startDate)->format('d-m-Y');
        $end = Carbon::parse($this->endDate)->format('d-m-Y');

        return Excel::download(
            new InventoryValuationReportExport($this->exportFilters()),
            "InventoryValuation_{$start}_{$end}.xlsx"
        );
    }

    public function exportCsv()
    {
        $this->validate();

        $start = Carbon::parse($this->startDate)->format('d-m-Y');
        $end = Carbon::parse($this->endDate)->format('d-m-Y');

        return Excel::download(
            new InventoryValuationReportExport($this->exportFilters()),
            "InventoryValuation_{$start}_{$end}.csv",
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'productId' => $this->productId,
            'locationId' => $this->locationId,
        ];
    }

    public function render()
    {
        $settingId = session('setting_id');

        return view('livewire.reports.inventory-valuation-report', [
            'products' => Product::query()
                ->where('setting_id', $settingId)
                ->whereHas('transactions', function ($query) use ($settingId) {
                    $query->where('setting_id', $settingId)
                        ->whereIn('type', ['BUY', 'DISPATCH', 'SELL']);
                })
                ->orderBy('product_name')
                ->get(),
            'locations' => Location::query()
                ->where('setting_id', $settingId)
                ->orderBy('name')
                ->get(),
        ]);
    }
}

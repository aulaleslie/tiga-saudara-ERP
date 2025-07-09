<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Modules\Purchase\Entities\Purchase;

class PurchaseReportExport implements FromCollection, WithHeadings
{
    protected $filters;

    public function __construct(array $filters) { $this->filters = $filters; }

    public function collection()
    {
        return Purchase::with('supplier')
            ->when($this->filters['startDate'], fn($q) => $q->where('date', '>=', $this->filters['startDate']))
            ->when($this->filters['endDate'], fn($q) => $q->where('date', '<=', $this->filters['endDate']))
            ->when($this->filters['supplierId'], fn($q) => $q->where('supplier_id', $this->filters['supplierId']))
            ->when($this->filters['withTax'] !== null && $this->filters['withTax'] !== '', fn($q) => $q->where('is_tax_included', $this->filters['withTax']))
            ->when($this->filters['selectedTag'], fn($q) => $q->whereHas('tags', fn($tq) => $tq->where('tags.id', $this->filters['selectedTag'])))
            ->get()
            ->map(fn($p) => [
                'Date' => $p->date,
                'Reference' => $p->reference,
                'Supplier' => $p->supplier->supplier_name ?? '-',
                'Total Amount' => $p->total_amount,
                'Tax Amount' => $p->tax_amount,
                'Tax Included' => $p->is_tax_included ? 'Yes' : 'No',
            ]);
    }

    public function headings(): array
    {
        return ['Date', 'Reference', 'Supplier', 'Total Amount', 'Tax Amount', 'Tax Included'];
    }
}

<?php

namespace App\Exports;

use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use App\Services\Reports\PurchaseDeliveryReportFilterData;

class PurchaseDeliveryReportExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function __construct(
        private Builder $query,
        private PurchaseDeliveryReportFilterData $filter,
        private bool $isCsv = false
    ) {
    }

    public function collection()
    {
        $data = collect($this->query->get());
        $rows = collect();
        $grandTotal = 0;
        
        $grouped = $data->groupBy('supplier_id');
        
        foreach ($grouped as $supplierId => $group) {
            $supplierTotal = 0;
            
            foreach ($group as $item) {
                $item->is_subtotal = false;
                $item->is_grand_total = false;
                $rows->push($item);
                $supplierTotal += $item->delivered_amount;
            }
            
            $grandTotal += $supplierTotal;
            
            // Subtotal row
            $subtotalRow = new \stdClass();
            $subtotalRow->is_subtotal = true;
            $subtotalRow->is_grand_total = false;
            $subtotalRow->supplier_name = "Subtotal " . ($group->first()->supplier_name ?? '-');
            $subtotalRow->product_code = '';
            $subtotalRow->product_name = '';
            $subtotalRow->unit_name = '';
            $subtotalRow->delivered_quantity = null;
            $subtotalRow->delivered_amount = $supplierTotal;
            $rows->push($subtotalRow);
        }
        
        // Grand total row
        $grandTotalRow = new \stdClass();
        $grandTotalRow->is_subtotal = false;
        $grandTotalRow->is_grand_total = true;
        $grandTotalRow->supplier_name = "Total Keseluruhan";
        $grandTotalRow->product_code = '';
        $grandTotalRow->product_name = '';
        $grandTotalRow->unit_name = '';
        $grandTotalRow->delivered_quantity = null;
        $grandTotalRow->delivered_amount = $grandTotal;
        $rows->push($grandTotalRow);
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Supplier & Kode produk / SKU',
            'Nama produk',
            'Unit',
            'Qty',
            'Jumlah',
        ];
    }

    public function map($row): array
    {
        if ($row->is_subtotal || $row->is_grand_total) {
            return [
                'Supplier & Kode produk / SKU' => $row->supplier_name,
                'Nama produk'                  => '',
                'Unit'                         => '',
                'Qty'                          => '',
                'Jumlah'                       => $this->isCsv ? number_format((float) $row->delivered_amount, 2, '.', '') : round((float) $row->delivered_amount, 2),
            ];
        }

        return [
            'Supplier & Kode produk / SKU' => ($row->supplier_name ?: '-') . ' - ' . ($row->product_code ?: '-'),
            'Nama produk'                  => $row->product_name ?: '-',
            'Unit'                         => $row->unit_name ?: '-',
            'Qty'                          => $this->isCsv ? number_format((float) $row->delivered_quantity, 2, '.', '') : (float) $row->delivered_quantity,
            'Jumlah'                       => $this->isCsv ? number_format((float) $row->delivered_amount, 2, '.', '') : round((float) $row->delivered_amount, 2),
        ];
    }

    public function columnFormats(): array
    {
        if ($this->isCsv) {
            return [];
        }

        return [
            'D' => NumberFormat::FORMAT_NUMBER_00, // Qty
            'E' => '#,##0.00', // Jumlah
        ];
    }
}

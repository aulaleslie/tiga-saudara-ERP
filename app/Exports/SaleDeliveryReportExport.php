<?php

namespace App\Exports;

use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use App\Services\Reports\SaleDeliveryReportFilterData;

class SaleDeliveryReportExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function __construct(
        private Builder $query,
        private SaleDeliveryReportFilterData $filter,
        private bool $isCsv = false
    ) {
    }

    public function collection()
    {
        $data = collect($this->query->get());
        $rows = collect();
        $grandTotal = 0;
        
        $grouped = $data->groupBy('customer_id');
        
        foreach ($grouped as $customerId => $group) {
            $customerTotal = 0;
            
            foreach ($group as $item) {
                $item->is_subtotal = false;
                $item->is_grand_total = false;
                $rows->push($item);
                $customerTotal += $item->delivered_amount;
            }
            
            $grandTotal += $customerTotal;
            
            // Subtotal row
            $subtotalRow = new \stdClass();
            $subtotalRow->is_subtotal = true;
            $subtotalRow->is_grand_total = false;
            $subtotalRow->customer_name = "Subtotal " . ($group->first()->customer_name ?? '-');
            $subtotalRow->dispatch_date = '';
            $subtotalRow->dispatch_reference = '';
            $subtotalRow->product_code = '';
            $subtotalRow->product_name = '';
            $subtotalRow->delivered_quantity = null;
            $subtotalRow->unit_name = '';
            $subtotalRow->unit_amount = null;
            $subtotalRow->delivered_amount = $customerTotal;
            $rows->push($subtotalRow);
        }
        
        // Grand total row
        $grandTotalRow = new \stdClass();
        $grandTotalRow->is_subtotal = false;
        $grandTotalRow->is_grand_total = true;
        $grandTotalRow->customer_name = "Total Keseluruhan";
        $grandTotalRow->dispatch_date = '';
        $grandTotalRow->dispatch_reference = '';
        $grandTotalRow->product_code = '';
        $grandTotalRow->product_name = '';
        $grandTotalRow->delivered_quantity = null;
        $grandTotalRow->unit_name = '';
        $grandTotalRow->unit_amount = null;
        $grandTotalRow->delivered_amount = $grandTotal;
        $rows->push($grandTotalRow);
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Customer',
            'Tanggal',
            'No. Pengiriman',
            'Kode / SKU',
            'Nama Produk',
            'Qty Dikirim',
            'Satuan',
            'Harga per unit',
            'Total Nominal',
        ];
    }

    public function map($row): array
    {
        if ($row->is_subtotal || $row->is_grand_total) {
            return [
                'Customer'       => $row->customer_name,
                'Tanggal'        => '',
                'No. Pengiriman' => '',
                'Kode / SKU'     => '',
                'Nama Produk'    => '',
                'Qty Dikirim'    => '',
                'Satuan'         => '',
                'Harga per unit' => '',
                'Total Nominal'  => $this->isCsv ? number_format((float) $row->delivered_amount, 2, '.', '') : (float) $row->delivered_amount,
            ];
        }

        return [
            'Customer'       => $row->customer_name ?: '-',
            'Tanggal'        => $row->dispatch_date ?: '-',
            'No. Pengiriman' => $row->dispatch_reference ?: '-',
            'Kode / SKU'     => $row->product_code ?: '-',
            'Nama Produk'    => $row->product_name ?: '-',
            'Qty Dikirim'    => $this->isCsv ? number_format((float) $row->delivered_quantity, 2, '.', '') : (float) $row->delivered_quantity,
            'Satuan'         => $row->unit_name ?: '-',
            'Harga per unit' => $this->isCsv ? number_format((float) $row->unit_amount, 2, '.', '') : (float) $row->unit_amount,
            'Total Nominal'  => $this->isCsv ? number_format((float) $row->delivered_amount, 2, '.', '') : (float) $row->delivered_amount,
        ];
    }

    public function columnFormats(): array
    {
        if ($this->isCsv) {
            return [];
        }

        return [
            'F' => NumberFormat::FORMAT_NUMBER_00, // Qty Dikirim
            'H' => '#,##0', // Harga per unit
            'I' => '#,##0', // Total Nominal
        ];
    }
}

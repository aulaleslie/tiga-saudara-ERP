<?php

namespace App\Exports;

use App\Services\Reports\SaleByCustomerReportFilterData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;

class SaleByCustomerReportExport implements FromArray, WithHeadings, WithEvents, WithColumnFormatting
{
    private Builder $query;
    private SaleByCustomerReportFilterData $filterData;
    private bool $isCsv;

    public function __construct(Builder $query, SaleByCustomerReportFilterData $filterData, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }



    public function array(): array
    {
        $rows = [];
        $queryResults = $this->query->get();
        $currentCustomerId = null;
        $runningTotal = 0.0;
        $grandTotal = 0.0;
        $prevCustomerName = '';
        
        $saleDetailsCount = [];
        $saleDetailsProcessed = [];
        foreach ($queryResults as $detail) {
            $saleId = $detail->sale_id;
            $saleDetailsCount[$saleId] = ($saleDetailsCount[$saleId] ?? 0) + 1;
        }

        foreach ($queryResults as $detail) {
            $customerId = $detail->sale?->customer_id ?? 0;
            $customerName = $detail->customer_name ?: ($detail->sale?->customer?->customer_name ?? '-');
            
            if ($customerId !== $currentCustomerId) {
                if ($currentCustomerId !== null) {
                    // Subtotal row for previous customer
                    $rows[] = [
                        '', '', '', '', '', '', '', '',
                        $prevCustomerName . ' | Total Penjualan',
                        $runningTotal,
                    ];
                }
                
                // Group Header row for NEW customer
                $rows[] = [
                    $customerName,
                    '', '', '', '', '', '', '', '', ''
                ];
                
                $currentCustomerId = $customerId;
                $prevCustomerName = $customerName;
                $runningTotal = 0.0;
            }
            
            $saleId = $detail->sale_id;
            $saleDetailsProcessed[$saleId] = ($saleDetailsProcessed[$saleId] ?? 0) + 1;
            $isLastDetailInInvoice = $saleDetailsProcessed[$saleId] === $saleDetailsCount[$saleId];

            $mappedRows = \App\Services\Reports\SaleByCustomerReportQueryService::mapRowsForExport($detail, $runningTotal, $isLastDetailInInvoice);
            foreach ($mappedRows as $mapped) {
                $rows[] = [
                    $mapped['Customer'],
                    Carbon::parse($mapped['Tanggal'])->format('d/m/Y'),
                    $mapped['Tipe transaksi'],
                    $mapped['No. transaksi'],
                    $mapped['Nama produk'],
                    $mapped['Qty'] !== '' ? (float) $mapped['Qty'] : '',
                    $mapped['Unit'],
                    $mapped['is_tax_row'] ? '' : (float) $mapped['Harga per unit'],
                    (float) $mapped['Nominal tagihan'],
                    (float) $mapped['Total nominal tagihan'],
                ];
                $runningTotal = $mapped['Total nominal tagihan'];
            }
            $tax = $detail->sale?->is_tax_included ? 0 : (float) ($detail->product_tax_amount ?? 0);
            $discount = $isLastDetailInInvoice ? (float) ($detail->sale->discount_amount ?? 0) : 0;
            $grandTotal += (float) $detail->sub_total + $tax - $discount;
        }
        
        // Push subtotal for the very last customer
        if ($currentCustomerId !== null) {
            $rows[] = [
                '', '', '', '', '', '', '', '',
                $prevCustomerName . ' | Total Penjualan',
                $runningTotal,
            ];
            
            // Grand Total row
            $rows[] = [
                'Grand Total', '', '', '', '', '', '', '', '', $grandTotal
            ];
        }
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Customer',
            'Tanggal',
            'Transaksi',
            'No',
            'Produk',
            'Kuantitas',
            'Satuan',
            'Harga Satuan',
            'Jumlah Tagihan',
            'Total',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'H' => '#,##0.00',
            'I' => '#,##0.00',
            'J' => '#,##0.00',
        ];
    }

    public function registerEvents(): array
    {
        if ($this->isCsv) {
            return [];
        }

        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                
                $sheet->insertNewRowBefore(1, 5);

                $setting = \Modules\Setting\Entities\Setting::find($this->filterData->scopeSettingId) 
                           ?? \Modules\Setting\Entities\Setting::first();
                $companyName = $setting ? $setting->company_name : 'COMPANY NAME';

                $startDate = Carbon::parse($this->filterData->startDate)->format('d/m/Y');
                $endDate = Carbon::parse($this->filterData->endDate)->format('d/m/Y');
                
                // Row 1
                $sheet->setCellValue('A1', $companyName);
                $sheet->mergeCells('A1:J1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                
                // Row 2
                $sheet->setCellValue('A2', 'Penjualan per Customer');
                $sheet->mergeCells('A2:J2');
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                
                // Row 3
                $sheet->setCellValue('A3', "{$startDate} - {$endDate}");
                $sheet->mergeCells('A3:J3');
                
                // Row 4
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->mergeCells('A4:J4');
                
                // Bold headers (now row 6)
                $sheet->getStyle('A6:J6')->getFont()->setBold(true);
                
                // Specific styling for group headers, subtotals, and grand total
                $highestRow = $sheet->getHighestRow();
                for ($row = 7; $row <= $highestRow; $row++) {
                    $aVal = $sheet->getCell("A{$row}")->getValue();
                    $bVal = $sheet->getCell("B{$row}")->getValue();
                    $iVal = $sheet->getCell("I{$row}")->getValue();
                    $jVal = $sheet->getCell("J{$row}")->getValue();
                    
                    if (empty($bVal)) {
                        if ($aVal === 'Grand Total') {
                            // Grand Total Row
                            $sheet->getStyle("J{$row}")->getFont()->setBold(true);
                        } elseif (!empty($aVal) && empty($jVal)) {
                            // Group Header Row
                            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
                        } elseif (empty($aVal) && !empty($iVal)) {
                            // Subtotal Row
                            $sheet->getStyle("J{$row}")->getFont()->setBold(true);
                        }
                    }
                }
            },
        ];
    }
}

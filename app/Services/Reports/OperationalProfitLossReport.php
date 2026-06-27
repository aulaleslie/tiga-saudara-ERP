<?php

namespace App\Services\Reports;

class OperationalProfitLossReport
{
    public string $currencyCode;
    public string $periodLabel;

    public float $penjualan;
    public float $diskonPenjualan;
    public float $totalPendapatan;

    public float $bebanPokokPendapatan;
    public float $labaKotor;

    public float $bebanOperasional;
    public float $totalBebanOperasional;

    public float $labaOperasional;
    public float $pendapatanBebanLainLain;

    public float $labaRugi;

    public function __construct(
        string $currencyCode,
        string $periodLabel,
        float $penjualan,
        float $diskonPenjualan,
        float $bebanPokokPendapatan,
        float $bebanOperasional
    ) {
        $this->currencyCode = $currencyCode;
        $this->periodLabel = $periodLabel;

        $this->penjualan = $penjualan;
        $this->diskonPenjualan = $diskonPenjualan;
        $this->totalPendapatan = $this->penjualan + $this->diskonPenjualan;

        $this->bebanPokokPendapatan = $bebanPokokPendapatan;
        
        $this->labaKotor = $this->totalPendapatan - $this->bebanPokokPendapatan;

        $this->bebanOperasional = $bebanOperasional;
        $this->totalBebanOperasional = $this->bebanOperasional;

        $this->labaOperasional = $this->labaKotor - $this->totalBebanOperasional;
        $this->pendapatanBebanLainLain = 0;

        $this->labaRugi = $this->labaOperasional + $this->pendapatanBebanLainLain;
    }

    public function getRows(): array
    {
        return [
            ['type' => 'header', 'label' => 'Pendapatan'],
            ['type' => 'row', 'label' => 'Penjualan', 'value' => $this->penjualan],
            ['type' => 'row', 'label' => 'Diskon Penjualan', 'value' => $this->diskonPenjualan],
            ['type' => 'subtotal', 'label' => 'Total dari Pendapatan', 'value' => $this->totalPendapatan],
            
            ['type' => 'empty'],
            
            ['type' => 'header', 'label' => 'Beban Pokok Pendapatan'],
            ['type' => 'row', 'label' => 'Beban Pokok Pendapatan', 'value' => $this->bebanPokokPendapatan],
            ['type' => 'subtotal', 'label' => 'Total dari Beban Pokok Pendapatan', 'value' => $this->bebanPokokPendapatan],
            ['type' => 'subtotal', 'label' => 'Laba Kotor', 'value' => $this->labaKotor],
            
            ['type' => 'empty'],

            ['type' => 'header', 'label' => 'Beban Operasional'],
            ['type' => 'row', 'label' => 'Beban Operasional', 'value' => $this->bebanOperasional],
            ['type' => 'subtotal', 'label' => 'Total dari Beban Operasional', 'value' => $this->totalBebanOperasional],
            ['type' => 'subtotal', 'label' => 'Laba Operasional', 'value' => $this->labaOperasional],

            ['type' => 'empty'],

            ['type' => 'header', 'label' => 'Pendapatan (Beban Lain-lain)'],
            ['type' => 'subtotal', 'label' => 'Total dari Pendapatan (Beban Lain-lain)', 'value' => $this->pendapatanBebanLainLain],

            ['type' => 'empty'],

            ['type' => 'total', 'label' => 'Laba (Rugi)', 'value' => $this->labaRugi],
        ];
    }
}

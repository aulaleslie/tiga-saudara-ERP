<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $permissions = [
            'reports.access',
            'saleReports.access',
            'saleReports.global.access',
            'purchaseReports.access',
            'purchaseReports.global.access',
            'stockMutationReports.access',
            'stockMutationReports.global.access',
            'inventoryValuationReports.access'
        ];

        abort_unless(Gate::any($permissions), 403);

        $config = [
            [
                'slug' => 'sekilas-bisnis',
                'label' => 'Sekilas bisnis',
                'icon' => 'bi bi-graph-up',
                'cards' => [
                    [
                        'label' => 'Laporan Laba Rugi',
                        'icon' => 'bi bi-wallet2',
                        'description' => 'Menampilkan ringkasan pendapatan, biaya, dan laba/rugi dalam periode tertentu.',
                        'route' => 'profit-loss-report.index',
                        'permission' => 'reports.access'
                    ],
                    [
                        'label' => 'Neraca',
                        'icon' => 'bi bi-bank',
                        'description' => 'Menampilkan apa yang dimiliki (aset), apa saja utangnya (liabilitas), dan apa yang sudah diinvestasikan ke perusahaan ini (ekuitas) pada tanggal tertentu.',
                        'route' => 'operational-balance-sheet-report.index',
                        'permission' => 'reports.access'
                    ],
                    [
                        'label' => 'Buku besar',
                        'icon' => 'bi bi-journal-text',
                        'description' => 'Menampilkan semua transaksi berdasarkan akun dalam periode tertentu, termasuk kronologi pergerakan transaksinya selama periode berlangsung.',
                        'route' => 'operational-general-ledger-report.index',
                        'permission' => 'reports.access'
                    ],
                    [
                        'label' => 'Arus kas',
                        'icon' => 'bi bi-cash-stack',
                        'description' => 'Menampilkan pergerakan uang masuk dan keluar dari transaksi dalam periode tertentu. Template laporan ini bisa Anda custom sesuai kebutuhan.',
                        'route' => 'operational-cash-flow-report.index',
                        'permission' => 'reports.access'
                    ],
                    [
                        'label' => 'Neraca saldo',
                        'icon' => 'bi bi-file-earmark-spreadsheet',
                        'description' => 'Menampilkan saldo dari setiap akun, termasuk saldo awal, pergerakan, dan saldo akhir dalam periode tertentu.',
                        'route' => 'operational-trial-balance-report.index',
                        'permission' => 'reports.access'
                    ]
                ]
            ],
            [
                'slug' => 'penjualan',
                'label' => 'Penjualan',
                'icon' => 'bi bi-cart',
                'cards' => [
                    [
                        'label' => 'Daftar Penjualan',
                        'icon' => 'bi bi-cart-check',
                        'description' => 'Menampilkan daftar transaksi penjualan beserta total nilainya dalam periode tertentu.',
                        'route' => 'reports.sale-report.index',
                        'permission' => 'saleReports.access'
                    ],
                    [
                        'label' => 'Penjualan Per Customer',
                        'icon' => 'bi bi-people',
                        'description' => 'Menampilkan rekap nilai penjualan yang dikelompokkan per customer.',
                        'route' => 'reports.sale-by-customer.index',
                        'permission' => 'saleReports.access'
                    ],
                    [
                        'label' => 'Penjualan Global',
                        'icon' => 'bi bi-globe',
                        'description' => 'Menampilkan data penjualan dari semua setting/cabang dalam satu laporan.',
                        'route' => 'reports.sale-report.global',
                        'permission' => 'saleReports.global.access'
                    ],
                    [
                        'label' => 'Piutang pelanggan',
                        'icon' => 'bi bi-person-lines-fill',
                        'description' => 'Menampilkan semua faktur yang belum dibayar dan saldo memo kredit pelanggan pada tanggal tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'saleReports.access'
                    ],
                    [
                        'label' => 'Usia piutang',
                        'icon' => 'bi bi-calendar-range',
                        'description' => 'Menampilkan total piutang dari setiap pelanggan berdasarkan usianya (30, 60, 90, dan setelah 90 hari).',
                        'is_placeholder' => true,
                        'permission' => 'saleReports.access'
                    ],
                    [
                        'label' => 'Pengiriman penjualan',
                        'icon' => 'bi bi-truck',
                        'description' => 'Menampilkan semua produk yang dikirim untuk transaksi penjualan dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'saleReports.access'
                    ],
                    [
                        'label' => 'Penjualan per produk',
                        'icon' => 'bi bi-box',
                        'description' => 'Menampilkan semua kuantitas produk yang terjual, kuantitas retur, penjualan bersih, dan harga penjualan rata-rata dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'saleReports.access'
                    ],
                    [
                        'label' => 'Penyelesaian pesanan penjualan',
                        'icon' => 'bi bi-check2-square',
                        'description' => 'Menampilkan ringkasan proses bisnis perusahaan ini. Anda dapat mengidentifikasi setiap penyelesaian penawaran dan pesanan penjualan hingga penagihan dan pembayarannya dilakukan.',
                        'is_placeholder' => true,
                        'permission' => 'saleReports.access'
                    ],
                    [
                        'label' => 'Daftar faktur proforma',
                        'icon' => 'bi bi-file-earmark-text',
                        'description' => 'Menampilkan semua faktur proforma yang dibuat dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'saleReports.access'
                    ],
                    [
                        'label' => 'Daftar tukar faktur',
                        'icon' => 'bi bi-arrow-left-right',
                        'description' => 'Menampilkan semua tukar faktur dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'saleReports.access'
                    ],
                ]
            ],
            [
                'slug' => 'pembelian',
                'label' => 'Pembelian',
                'icon' => 'bi bi-bag',
                'cards' => [
                    [
                        'label' => 'Daftar Pembelian',
                        'icon' => 'bi bi-bag-check',
                        'description' => 'Menampilkan daftar transaksi pembelian beserta total nilainya dalam periode tertentu.',
                        'route' => 'reports.purchase-report.index',
                        'permission' => 'purchaseReports.access'
                    ],
                    [
                        'label' => 'Pembelian Per Supplier',
                        'icon' => 'bi bi-truck',
                        'description' => 'Menampilkan rekap nilai pembelian yang dikelompokkan per supplier.',
                        'route' => 'reports.purchase-by-supplier.index',
                        'permission' => 'purchaseReports.access'
                    ],
                    [
                        'label' => 'Pembelian Global',
                        'icon' => 'bi bi-globe',
                        'description' => 'Menampilkan data pembelian dari semua setting/cabang dalam satu laporan.',
                        'route' => 'reports.purchase-report.global',
                        'permission' => 'purchaseReports.global.access'
                    ],
                    [
                        'label' => 'Utang supplier',
                        'icon' => 'bi bi-building',
                        'description' => 'Menampilkan semua faktur yang belum dibayar dan saldo memo debit supplier pada tanggal tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'purchaseReports.access'
                    ],
                    [
                        'label' => 'Daftar pengeluaran',
                        'icon' => 'bi bi-receipt',
                        'description' => 'Menampilkan semua transaksi pengeluaran dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'purchaseReports.access'
                    ],
                    [
                        'label' => 'Detail pengeluaran',
                        'icon' => 'bi bi-card-list',
                        'description' => 'Menampilkan semua transaksi pengeluaran berdasarkan akun dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'purchaseReports.access'
                    ],
                    [
                        'label' => 'Usia utang',
                        'icon' => 'bi bi-calendar-range',
                        'description' => 'Menampilkan total utang kepada setiap supplier berdasarkan usianya (30, 60, 90, dan setelah 90 hari).',
                        'is_placeholder' => true,
                        'permission' => 'purchaseReports.access'
                    ],
                    [
                        'label' => 'Pengiriman pembelian',
                        'icon' => 'bi bi-truck',
                        'description' => 'Menampilkan semua produk yang dikirim untuk transaksi pembelian dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'purchaseReports.access'
                    ],
                    [
                        'label' => 'Pembelian per produk',
                        'icon' => 'bi bi-box',
                        'description' => 'Menampilkan semua kuantitas produk yang dibeli, kuantitas retur, pembelian bersih, dan harga pembelian rata-rata dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'purchaseReports.access'
                    ],
                    [
                        'label' => 'Penyelesaian pesanan pembelian',
                        'icon' => 'bi bi-check2-square',
                        'description' => 'Menampilkan ringkasan proses bisnis perusahaan ini. Anda dapat mengidentifikasi setiap penyelesaian penawaran dan pesanan pembelian hingga penagihan dan pembayarannya dilakukan.',
                        'is_placeholder' => true,
                        'permission' => 'purchaseReports.access'
                    ],
                ]
            ],
            [
                'slug' => 'produk',
                'label' => 'Produk',
                'icon' => 'bi bi-box-seam',
                'cards' => [
                    [
                        'label' => 'Mutasi Stok',
                        'icon' => 'bi bi-arrow-left-right',
                        'description' => 'Menampilkan pergerakan masuk dan keluar stok per produk dalam periode tertentu.',
                        'route' => 'reports.stock-mutation-report.index',
                        'permission' => 'stockMutationReports.access'
                    ],
                    [
                        'label' => 'Mutasi Stok Global',
                        'icon' => 'bi bi-globe',
                        'description' => 'Menampilkan pergerakan stok dari semua setting/cabang dalam satu laporan.',
                        'route' => 'reports.stock-mutation-report.global',
                        'permission' => 'stockMutationReports.global.access'
                    ],
                    [
                        'label' => 'Valuasi Stok',
                        'icon' => 'bi bi-calculator',
                        'description' => 'Menampilkan nilai persediaan barang berdasarkan kuantitas dan harga rata-rata.',
                        'route' => 'reports.inventory-valuation-report.index',
                        'permission' => 'inventoryValuationReports.access'
                    ],
                    [
                        'label' => 'Ringkasan persediaan barang',
                        'icon' => 'bi bi-box-seam',
                        'description' => 'Menampilkan kuantitas stok yang tersedia dengan harga rata-rata per unit dan total nilainya pada tanggal tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'inventoryValuationReports.access'
                    ],
                    [
                        'label' => 'Kuantitas stok gudang',
                        'icon' => 'bi bi-building',
                        'description' => 'Menampilkan setiap kuantitas produk berdasarkan gudang yang dipilih pada tanggal tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'stockMutationReports.access'
                    ],
                    [
                        'label' => 'Nilai persediaan barang',
                        'icon' => 'bi bi-currency-dollar',
                        'description' => 'Menampilkan pergerakan stok per produk berdasarkan stok yang tersedia dan nilai stoknya dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'inventoryValuationReports.access'
                    ],
                    [
                        'label' => 'Nilai stok gudang',
                        'icon' => 'bi bi-building',
                        'description' => 'Menampilkan nilai persediaan barang per gudang dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'inventoryValuationReports.access'
                    ],
                    [
                        'label' => 'Detail persediaan barang',
                        'icon' => 'bi bi-card-list',
                        'description' => 'Menampilkan daftar produk dengan mutasi dan kuantitas akhirnya.',
                        'is_placeholder' => true,
                        'permission' => 'stockMutationReports.access'
                    ],
                    [
                        'label' => 'Pergerakan barang gudang',
                        'icon' => 'bi bi-arrow-left-right',
                        'description' => 'Menampilkan pergerakan stok per gudang dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'stockMutationReports.access'
                    ],
                ]
            ],
            [
                'slug' => 'aset',
                'label' => 'Aset',
                'icon' => 'bi bi-building',
                'cards' => []
            ],
            [
                'slug' => 'bank',
                'label' => 'Bank',
                'icon' => 'bi bi-bank',
                'cards' => []
            ],
            [
                'slug' => 'pajak',
                'label' => 'Pajak',
                'icon' => 'bi bi-receipt',
                'cards' => [
                    [
                        'label' => 'Pajak pemotongan',
                        'icon' => 'bi bi-scissors',
                        'description' => 'Menampilkan dasar pengenaan pajak (DPP), tarif pajak, dan jumlah pajak dengan tipe pemotongan yang digunakan di transaksi dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'reports.access'
                    ],
                    [
                        'label' => 'Pajak penjualan',
                        'icon' => 'bi bi-receipt',
                        'description' => 'Menampilkan dasar pengenaan pajak (DPP), tarif pajak, dan jumlah pajak dengan pajak pertambahan nilai (PPN) yang digunakan di transaksi dalam periode tertentu.',
                        'is_placeholder' => true,
                        'permission' => 'reports.access'
                    ]
                ]
            ],
            [
                'slug' => 'produksi',
                'label' => 'Produksi',
                'icon' => 'bi bi-gear',
                'cards' => []
            ],
            [
                'slug' => 'lainnya',
                'label' => 'Lainnya',
                'icon' => 'bi bi-three-dots',
                'cards' => [
                    [
                        'label' => 'Mekari Converter',
                        'icon' => 'bi bi-file-earmark-excel',
                        'description' => 'Mengonversi laporan Mekari ke format yang siap diproses.',
                        'route' => 'reports.mekari-converter.index',
                        'permission' => 'reports.access'
                    ],
                    [
                        'label' => 'Mekari Invoice Generator',
                        'icon' => 'bi bi-file-earmark-pdf',
                        'description' => 'Membuat dokumen invoice PDF dari data Mekari.',
                        'route' => 'reports.mekari-invoice-generator.index',
                        'permission' => 'reports.access'
                    ],
                ]
            ],
        ];

        $tabs = [];
        foreach ($config as $tab) {
            $filteredCards = [];
            foreach ($tab['cards'] as $card) {
                if (Gate::allows($card['permission'])) {
                    if (isset($card['is_placeholder']) && $card['is_placeholder']) {
                        $filteredCards[] = $card;
                    } elseif (Route::has($card['route'])) {
                        $filteredCards[] = $card;
                    }
                }
            }
            if (count($filteredCards) > 0) {
                $tab['cards'] = $filteredCards;
                $tabs[] = $tab;
            }
        }

        if (count($tabs) === 0) {
            abort(403);
        }

        $activeSlug = $request->query('tab');
        $validSlugs = array_column($tabs, 'slug');

        if (!in_array($activeSlug, $validSlugs)) {
            $activeSlug = $validSlugs[0] ?? null;
        }

        $activeTab = collect($tabs)->firstWhere('slug', $activeSlug);

        return view('reports::index', compact('tabs', 'activeTab', 'activeSlug'));
    }

    public function profitLossReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::profit-loss.index');
    }

    public function operationalBalanceSheetReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::operational-balance-sheet.index');
    }

    public function operationalCashFlowReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::operational-cash-flow.index');
    }

    public function operationalGeneralLedgerReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::operational-general-ledger.index');
    }

    public function operationalTrialBalanceReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::operational-trial-balance.index');
    }

    public function paymentsReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::payments.index');
    }

    public function salesReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::sales.index');
    }

    public function purchasesReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::purchases.index');
    }

    public function salesReturnReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::sales-return.index');
    }

    public function purchasesReturnReport() {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::purchases-return.index');
    }
}

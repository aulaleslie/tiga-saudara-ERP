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
                'cards' => []
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
                if (Gate::allows($card['permission']) && Route::has($card['route'])) {
                    $filteredCards[] = $card;
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

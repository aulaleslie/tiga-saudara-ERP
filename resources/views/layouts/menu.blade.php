<li class="c-sidebar-nav-item {{ request()->routeIs('home') ? 'c-active' : '' }}">
    <a class="c-sidebar-nav-link" href="{{ route('home') }}">
        <i class="c-sidebar-nav-icon bbi bi-houses-fill" style="line-height: 1;"></i> Beranda
    </a>
</li>

<li class="c-sidebar-nav-item">
    <a class="c-sidebar-nav-link" href="#">
        <i class="c-sidebar-nav-icon bbi bi-display" style="line-height: 1;"></i> Dashboard
    </a>
</li>

@can('globalPurchaseAndSalesSearch.access')
    <li class="c-sidebar-nav-item {{ request()->routeIs('global-purchase-and-sales-search.*') ? 'c-active' : '' }}">
        <a class="c-sidebar-nav-link" href="{{ route('global-purchase-and-sales-search.index') }}">
            <i class="c-sidebar-nav-icon bi bi-search" style="line-height: 1;"></i> Pencarian Penjualan dan Pembelian Global
        </a>
    </li>
@endcan

<li class="c-sidebar-nav-divider"></li>

@can('reports.access')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('reports.mekari-converter.*') || request()->routeIs('reports.mekari-invoice-generator.*') || request()->routeIs('profit-loss-report.index') || request()->routeIs('reports.purchase-report.index') || request()->routeIs('reports.sale-report.index') || request()->routeIs('reports.stock-mutation-report.index') || request()->routeIs('reports.inventory-valuation-report.index') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-file-earmark-spreadsheet" style="line-height: 1;"></i> Laporan
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.mekari-converter.*') ? 'c-active' : '' }}"
                   href="{{ route('reports.mekari-converter.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-arrow-repeat" style="line-height: 1;"></i> Mekari Converter
                </a>
            </li>
        </ul>
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.mekari-invoice-generator.*') ? 'c-active' : '' }}"
                   href="{{ route('reports.mekari-invoice-generator.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-arrow-repeat" style="line-height: 1;"></i> Mekari Invoice Generator
                </a>
            </li>
        </ul>
        @if(Route::has('profit-loss-report.index'))
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('profit-loss-report.index') ? 'c-active' : '' }}"
                   href="{{ route('profit-loss-report.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-cash-coin" style="line-height: 1;"></i> Laporan Laba Rugi
                </a>
            </li>
        </ul>
        @endif
        @can('purchaseReports.access')
        @if(Route::has('reports.purchase-report.index'))
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.purchase-report.index') ? 'c-active' : '' }}"
                   href="{{ route('reports.purchase-report.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-clipboard-data" style="line-height: 1;"></i> Laporan Pembelian
                </a>
            </li>
        </ul>
        @endif
        @endcan
        @can('purchaseReports.global.access')
        @if(Route::has('reports.purchase-report.global'))
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.purchase-report.global') ? 'c-active' : '' }}"
                   href="{{ route('reports.purchase-report.global') }}">
                    <i class="c-sidebar-nav-icon bi bi-globe" style="line-height: 1;"></i> Laporan Pembelian Global
                </a>
            </li>
        </ul>
        @endif
        @endcan
        @can('saleReports.access')
        @if(Route::has('reports.sale-report.index'))
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.sale-report.index') ? 'c-active' : '' }}"
                   href="{{ route('reports.sale-report.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-graph-up-arrow" style="line-height: 1;"></i> Laporan Penjualan
                </a>
            </li>
        </ul>
        @endif
        @endcan
        @can('saleReports.global.access')
        @if(Route::has('reports.sale-report.global'))
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.sale-report.global') ? 'c-active' : '' }}"
                   href="{{ route('reports.sale-report.global') }}">
                    <i class="c-sidebar-nav-icon bi bi-globe" style="line-height: 1;"></i> Laporan Penjualan Global
                </a>
            </li>
        </ul>
        @endif
        @endcan
        @can('stockMutationReports.access')
        @if(Route::has('reports.stock-mutation-report.index'))
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.stock-mutation-report.index') ? 'c-active' : '' }}"
                   href="{{ route('reports.stock-mutation-report.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-arrow-left-right" style="line-height: 1;"></i> Mutasi Stok
                </a>
            </li>
        </ul>
        @endif
        @endcan
        @can('stockMutationReports.global.access')
        @if(Route::has('reports.stock-mutation-report.global'))
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.stock-mutation-report.global') ? 'c-active' : '' }}"
                   href="{{ route('reports.stock-mutation-report.global') }}">
                    <i class="c-sidebar-nav-icon bi bi-globe" style="line-height: 1;"></i> Mutasi Stok Global
                </a>
            </li>
        </ul>
        @endif
        @endcan
        @can('inventoryValuationReports.access')
        @if(Route::has('reports.inventory-valuation-report.index'))
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.inventory-valuation-report.index') ? 'c-active' : '' }}"
                   href="{{ route('reports.inventory-valuation-report.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-calculator" style="line-height: 1;"></i> Valuasi Stok
                </a>
            </li>
        </ul>
        @endif
        @endcan
    </li>
@endcan

@php
    $currentSetting = settings();
    $posEnabledForCurrentSetting = (bool) ($currentSetting->pos_enabled ?? false);
    $posTransactionsEnabledForCurrentSetting = (bool) ($currentSetting->pos_transactions_enabled ?? false);
    $canAccessPosTransactions = $posEnabledForCurrentSetting
        && $posTransactionsEnabledForCurrentSetting
        && auth()->user()->can('pos.access')
        && auth()->user()->can('pos.transactions.view');
    $canAccessPosOperations = $posEnabledForCurrentSetting
        && auth()->user()->can('pos.access')
        && (
            auth()->user()->canAny(['pos.sell', 'pos.sessions.view', 'pos.reports.access', 'pos.reconciliation.access', 'pos.supervisor.approval'])
            || $canAccessPosTransactions
        );
    $canAccessPosTerminals = $posEnabledForCurrentSetting && auth()->user()->can('pos.terminals.access');
@endphp

@if($canAccessPosOperations || $canAccessPosTerminals)
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('pos.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-upc-scan" style="line-height: 1;"></i> POS
        </a>

        @if($posEnabledForCurrentSetting && auth()->user()->can('pos.access') && auth()->user()->can('pos.sell'))
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('pos.sell') || request()->routeIs('pos.sell.*') ? 'c-active' : '' }}"
                       href="{{ route('pos.sell') }}">
                        <i class="c-sidebar-nav-icon bi bi-cash-stack" style="line-height: 1;"></i> POS Kasir
                    </a>
                </li>
            </ul>
        @endif

        @if($posEnabledForCurrentSetting && auth()->user()->can('pos.access') && auth()->user()->can('pos.sessions.view'))
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('pos.sessions.*') ? 'c-active' : '' }}"
                       href="{{ route('pos.sessions.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-clock-history" style="line-height: 1;"></i> Sesi POS
                    </a>
                </li>
            </ul>
        @endif

        @if($canAccessPosTransactions)
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('pos.transactions.*') ? 'c-active' : '' }}"
                       href="{{ route('pos.transactions.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-text" style="line-height: 1;"></i> Transaksi POS
                    </a>
                </li>
            </ul>
        @endif

        @if($posEnabledForCurrentSetting && auth()->user()->can('pos.access') && auth()->user()->can('pos.reports.access'))
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('pos.reports.*') ? 'c-active' : '' }}"
                       href="{{ route('pos.reports.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-clipboard-data" style="line-height: 1;"></i> Laporan POS
                    </a>
                </li>
            </ul>
        @endif

        @if($posEnabledForCurrentSetting && auth()->user()->can('pos.access') && auth()->user()->can('pos.reconciliation.access'))
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('pos.reconciliation.*') ? 'c-active' : '' }}"
                       href="{{ route('pos.reconciliation.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-wallet2" style="line-height: 1;"></i> Rekonsiliasi POS
                    </a>
                </li>
            </ul>
        @endif

        @if($canAccessPosTerminals)
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('pos.terminals.*') ? 'c-active' : '' }}"
                       href="{{ route('pos.terminals.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-pc-display" style="line-height: 1;"></i> Terminal POS
                    </a>
                </li>
            </ul>
        @endif
        @if($posEnabledForCurrentSetting && auth()->user()->can('pos.supervisor.approval'))
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('pos.supervisor.approval-requests.*') ? 'c-active' : '' }}"
                       href="{{ route('pos.supervisor.approval-requests.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-check-circle" style="line-height: 1;"></i> Antrian Persetujuan
                    </a>
                </li>
            </ul>
        @endif
    </li>
@endif

@canany(['sales.access', 'saleReturns.access', 'salesDispatches.access', 'sales.dispatch'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('sales.*') || request()->routeIs('sale-payments*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-receipt" style="line-height: 1;"></i> Penjualan
        </a>
        @can('sales.create')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('sales.create') ? 'c-active' : '' }}"
                       href="{{ route('sales.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Penjualan
                    </a>
                </li>
            </ul>
        @endcan

        @can('sales.access')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('sales.index') ? 'c-active' : '' }}"
                       href="{{ route('sales.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Penjualan
                    </a>
                </li>
            </ul>
        @endcan

        @canany(['salesDispatches.access', 'sales.dispatch'])
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('sales.dispatches.index') ? 'c-active' : '' }}"
                       href="{{ route('sales.dispatches.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-truck" style="line-height: 1;"></i> Pengiriman Barang
                    </a>
                </li>
            </ul>
        @endcanany

        @can('saleReturns.create')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('sale-returns.create') ? 'c-active' : '' }}"
                       href="{{ route('sale-returns.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Retur
                        Penjualan
                    </a>
                </li>
            </ul>
        @endcan

        @can('saleReturns.access')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('sale-returns.index') ? 'c-active' : '' }}"
                       href="{{ route('sale-returns.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Retur Penjualan
                    </a>
                </li>
            </ul>
        @endcan

        @can('globalSalesSearch.access')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('global-sales-search.*') ? 'c-active' : '' }}"
                       href="{{ route('global-sales-search.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-search" style="line-height: 1;"></i> Pencarian Penjualan Global
                    </a>
                </li>
            </ul>
        @endcan

    </li>
@endcanany

@canany(['purchases.access', 'purchases.create', 'purchases.receive', 'purchaseReturns.access', 'purchaseReturns.create', 'purchaseReceivings.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('purchases.*') || request()->routeIs('purchase-payments*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-bag" style="line-height: 1;"></i> Pembelian
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('purchases.create')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchases.create') ? 'c-active' : '' }}"
                       href="{{ route('purchases.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Pembelian
                    </a>
                </li>
            @endcan

            @can('purchases.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchases.index') ? 'c-active' : '' }}"
                       href="{{ route('purchases.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Semua Pembelian
                    </a>
                </li>
            @endcan

            @canany(['purchaseReceivings.access', 'purchases.receive'])
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchases.receiving.*') ? 'c-active' : '' }}"
                       href="{{ route('purchases.receiving.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-box-seam" style="line-height: 1;"></i> Penerimaan Barang
                    </a>
                </li>
            @endcan

            @canany(['purchaseReceivings.access', 'purchases.receive'])
                @if(Route::has('receivings.list'))
                    <li class="c-sidebar-nav-item">
                        <a class="c-sidebar-nav-link {{ request()->routeIs('receivings.list') ? 'c-active' : '' }}"
                           href="{{ route('receivings.list') }}">
                            <i class="c-sidebar-nav-icon bi bi-clipboard-check" style="line-height: 1;"></i> Daftar Penerimaan
                        </a>
                    </li>
                @endif
            @endcan

            @can('purchaseReturns.create')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchase-returns.create') ? 'c-active' : '' }}"
                       href="{{ route('purchase-returns.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Retur
                        Pembelian
                    </a>
                </li>
            @endcan

            @can('purchaseReturns.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchase-returns.index') ? 'c-active' : '' }}"
                       href="{{ route('purchase-returns.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Retur Pembelian
                    </a>
                </li>
            @endcan
        </ul>
    </li>
@endcanany

@canany(['expenses.access', 'expenseCategories.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('expenses.*') || request()->routeIs('expense-categories.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-wallet2" style="line-height: 1;"></i> Biaya
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('expenseCategories.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('expense-categories.*') ? 'c-active' : '' }}" href="{{ route('expense-categories.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-collection" style="line-height: 1;"></i> Kategori Biaya
                    </a>
                </li>
            @endcan
            @can('expenses.create')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('expenses.create') ? 'c-active' : '' }}" href="{{ route('expenses.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Biaya
                    </a>
                </li>
            @endcan
            @can('expenses.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('expenses.index') ? 'c-active' : '' }}" href="{{ route('expenses.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Semua Biaya
                    </a>
                </li>
            @endcan
        </ul>
    </li>
@endcanany

<li class="c-sidebar-nav-divider"></li>

@canany(['customers.access', 'suppliers.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('customers.*') || request()->routeIs('suppliers.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-book" style="line-height: 1;"></i> Kontak
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('customers.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('customers.*') ? 'c-active' : '' }}"
                       href="{{ route('customers.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-people-fill" style="line-height: 1;"></i> Pelanggan
                    </a>
                </li>
            @endcan
            @can('suppliers.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('suppliers.*') ? 'c-active' : '' }}"
                       href="{{ route('suppliers.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-truck" style="line-height: 1;"></i> Pemasok
                    </a>
                </li>
            @endcan
        </ul>
    </li>
@endcanany

@canany(['products.access', 'categories.access', 'barcodes.print', 'units.access', 'brands.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('products.*') || request()->routeIs('product-categories.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bbi bi-box2-fill" style="line-height: 1;"></i> Produk
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('categories.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('product-categories.*') ? 'c-active' : '' }}"
                       href="{{ route('product-categories.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-collection" style="line-height: 1;"></i> Kategori Produk
                    </a>
                </li>
            @endcan

            @can('products.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('products.index') ? 'c-active' : '' }}"
                       href="{{ route('products.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-box-seam" style="line-height: 1;"></i> Semua Produk
                    </a>
                </li>
            @endcan

            @can('barcodes.print')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('barcode.print') ? 'c-active' : '' }}"
                       href="{{ route('barcode.print') }}">
                        <i class="c-sidebar-nav-icon bi bi-upc-scan" style="line-height: 1;"></i> Print Barcode
                    </a>
                </li>
            @endcan

            @can('units.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('units*') ? 'c-active' : '' }}"
                       href="{{ route('units.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-file-binary" style="line-height: 1;"></i> Units
                    </a>
                </li>
            @endcan

            @can('brands.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('brands*') ? 'c-active' : '' }}"
                       href="{{ route('brands.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-nvidia" style="line-height: 1;"></i> Merek
                    </a>
                </li>
            @endcan

        </ul>
    </li>
@endcanany

@can('stockTransfers.access')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('transfers.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-journal-arrow-up" style="line-height: 1;"></i> Transfer Stock
        </a>
        @can("stockTransfers.create")
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('transfers.create') ? 'c-active' : '' }}"
                   href="{{ route('transfers.create') }}">
                    <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Transfer Stock
                </a>
            </li>
        </ul>
        @endcan
        @can('stockTransfers.access')
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('transfers.index') ? 'c-active' : '' }}"
                   href="{{ route('transfers.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i>Daftar Transfer Stock
                </a>
            </li>
        </ul>
        @endcan
    </li>
@endcan

@can('adjustments.access')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('adjustments.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-clipboard-check" style="line-height: 1;"></i> Stock Adjustments
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('adjustments.create')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('adjustments.create') ? 'c-active' : '' }}"
                       href="{{ route('adjustments.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Penyesuain
                    </a>
                </li>
            @endcan
            @can("adjustments.breakage.create")
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('break.create') ? 'c-active' : '' }}"
                   href="{{ route('adjustments.createBreakage') }}">
                    <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Daftar Barang Rusak
                </a>
            @endcan
            </li>
            @can('adjustments.access')
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('adjustments.index') ? 'c-active' : '' }}"
                   href="{{ route('adjustments.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Semua Penyesuaian
                </a>
            </li>
            @endcan
        </ul>
    </li>
@endcan

@canany(['users.access', 'roles.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('users*') || request()->routeIs('roles*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-person-fill-gear" style="line-height: 1;"></i> Daftar Akun
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can("users.access")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('users*') ? 'c-active' : '' }}"
                       href="{{ route('users.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-people" style="line-height: 1;"></i> Semua Akun
                    </a>
                </li>
            @endcan
            @can('roles.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('roles*') ? 'c-active' : '' }}"
                       href="{{ route('roles.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-person-workspace" style="line-height: 1;"></i> Peran & Izin
                    </a>
                </li>
            @endcan
        </ul>
    </li>
@endcan

<li class="c-sidebar-nav-divider"></li>

@canany(['settings.access', 'businesses.access', 'journals.access', 'taxes.access', 'paymentMethods.access', 'paymentTerms.access', 'saleLocations.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('settings*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-gear-fill" style="line-height: 1;"></i> Pengaturan
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('settings.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('settings*') ? 'c-active' : '' }}"
                       href="{{ route('settings.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-briefcase-fill" style="line-height: 1;"></i>
                        Pengaturan Bisnis
                    </a>
                </li>
            @endcan

            @can('businesses.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('businesses*') ? 'c-active' : '' }}"
                       href="{{ route('businesses.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i>
                        Daftar Bisnis
                    </a>
                </li>
            @endcan

            @can('taxes.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('taxes*') ? 'c-active' : '' }}"
                       href="{{ route('taxes.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-percent" style="line-height: 1;"></i>
                        Daftar Pajak
                    </a>
                </li>
            @endcan

            @can('locations.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('locations*') ? 'c-active' : '' }}"
                       href="{{ route('locations.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-geo-alt-fill" style="line-height: 1;"></i>
                        Daftar Lokasi
                    </a>
                </li>
            @endcan

            @can('saleLocations.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('sales-location-configurations*') ? 'c-active' : '' }}"
                       href="{{ route('sales-location-configurations.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-diagram-3" style="line-height: 1;"></i>
                        Konfigurasi Lokasi Penjualan POS
                    </a>
                </li>
            @endcan

            @can('paymentMethods.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('pos-payment-configurations*') ? 'c-active' : '' }}"
                       href="{{ route('pos-payment-configurations.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-credit-card" style="line-height: 1;"></i>
                        Konfigurasi Pembayaran POS
                    </a>
                </li>
            @endcan

            @can('paymentTerms.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('payment-terms*') ? 'c-active' : '' }}"
                       href="{{ route('payment-terms.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-calendar2-check-fill" style="line-height: 1;"></i>
                        Term Pembayaran
                    </a>
                </li>
            @endcan

            @can('paymentMethods.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('payment-methods*') ? 'c-active' : '' }}"
                       href="{{ route('payment-methods.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-credit-card-fill" style="line-height: 1;"></i>
                        Metode Pembayaran
                    </a>
                </li>
            @endcan

            @can('chartOfAccounts.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('chart-of-account*') ? 'c-active' : '' }}"
                       href="{{ route('chart-of-account.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-bookmark-fill" style="line-height: 1;"></i>
                        Daftar Nomor Akun
                    </a>
                </li>
            @endcan

            @can('journals.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('journals*') ? 'c-active' : '' }}"
                       href="{{ route('journals.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-text" style="line-height: 1;"></i>
                        Daftar Jurnal
                    </a>
                </li>
            @endcan
        </ul>
    </li>
@endcanany


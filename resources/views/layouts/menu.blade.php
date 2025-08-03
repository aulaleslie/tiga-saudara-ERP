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

<li class="c-sidebar-nav-divider"></li>

@can('reports.access')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('reports.mekari-converter.*') ? 'c-show' : '' }}">
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
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('reports.purchase-report.index') ? 'c-active' : '' }}"
                   href="{{ route('reports.purchase-report.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-clipboard-data" style="line-height: 1;"></i> Laporan Pembelian
                </a>
            </li>
        </ul>
    </li>
@endcan

@canany(['sales.access', 'saleReturns.access'])
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
    </li>
@endcanany

@canany(['purchases.access', 'purchaseReturns.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('purchases.*') || request()->routeIs('purchase-payments*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-bag" style="line-height: 1;"></i> Pembelian
        </a>
        @can('purchases.create')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchases.create') ? 'c-active' : '' }}"
                       href="{{ route('purchases.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Pembelian
                    </a>
                </li>
            </ul>
        @endcan

        @can('purchases.create')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchases.index') ? 'c-active' : '' }}"
                       href="{{ route('purchases.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Semua Pembelian
                    </a>
                </li>
            </ul>
        @endcan

        @can('purchaseReturns.create')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchase-returns.create') ? 'c-active' : '' }}"
                       href="{{ route('purchase-returns.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Retur
                        Pembelian
                    </a>
                </li>
            </ul>
        @endcan

        @can('purchaseReturns.access')
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('purchase-returns.index') ? 'c-active' : '' }}"
                   href="{{ route('purchase-returns.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Retur Pembelian
                </a>
            </li>
        </ul>
        @endcan
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
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('transfers.index') ? 'c-active' : '' }}"
                   href="{{ route('transfers.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i>Daftar Transfer Stock
                </a>
            </li>
        </ul>
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
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('adjustments.index') ? 'c-active' : '' }}"
                   href="{{ route('adjustments.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Semua Penyesuaian
                </a>
            </li>
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

@canany(['settings.access', 'businesses.access', 'journals.access', 'taxes.access', 'paymentMethods.access', 'paymentTerms.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('settings*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-wrench-adjustable" style="line-height: 1;"></i> Pengaturan
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('settings.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('settings*') ? 'c-active' : '' }}"
                       href="{{ route('settings.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-building-fill-gear" style="line-height: 1;"></i> Pengaturan
                        Bisnis
                    </a>
                </li>
            @endcan
            @can('businesses.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('businesses*') ? 'c-active' : '' }}"
                       href="{{ route('businesses.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Daftar Bisnis
                    </a>
                </li>
            @endcan
            @can("taxes.access")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('taxes*') ? 'c-active' : '' }}"
                       href="{{ route('taxes.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Daftar Pajak
                    </a>
                </li>
            @endcan
            @can("locations.access")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('locations*') ? 'c-active' : '' }}"
                       href="{{ route('locations.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Daftar Lokasi
                    </a>
                </li>
            @endcan
            @can("paymentTerms.access")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('payment-terms*') ? 'c-active' : '' }}"
                       href="{{ route('payment-terms.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Term Pembayaran
                    </a>
                </li>
            @endcan
            @can("paymentMethods.access")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('payment-methods*') ? 'c-active' : '' }}"
                       href="{{ route('payment-methods.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Metode Pembayaran
                    </a>
                </li>
            @endcan
            @can('chartOfAccounts.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('chart-of-account*') ? 'c-active' : '' }}"
                       href="{{ route('chart-of-account.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Daftar Nomor
                        Akun
                    </a>
                </li>
            @endcan
            @can('journals.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('journals*') ? 'c-active' : '' }}"
                       href="{{ route('journals.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Daftar Jurnal
                    </a>
                </li>
            @endcan

        </ul>
    </li>
@endcanany

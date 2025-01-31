<li class="c-sidebar-nav-item {{ request()->routeIs('home') ? 'c-active' : '' }}">
    <a class="c-sidebar-nav-link" href="{{ route('home') }}">
        <i class="c-sidebar-nav-icon bbi bi-houses-fill" style="line-height: 1;"></i> Beranda
    </a>
</li>

<li class="c-sidebar-nav-item">
    <a class="c-sidebar-nav-link" href="#">
        <i class="c-sidebar-nav-icon bbi bi-display" style="line-height: 1;"></i> Dasbor
    </a>
</li>

<li class="c-sidebar-nav-item">
    <a class="c-sidebar-nav-link" href="#">
        <i class="c-sidebar-nav-icon bbi bi-pie-chart" style="line-height: 1;"></i> Laporan
    </a>
</li>

<li class="c-sidebar-nav-divider"></li>

<li class="c-sidebar-nav-item">
    <a class="c-sidebar-nav-link" href="#">
        <i class="c-sidebar-nav-icon bbi bi-bank" style="line-height: 1;"></i> Kas & Bank
    </a>
</li>

@can('sale.access')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('sales.*') || request()->routeIs('sale-payments*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-receipt" style="line-height: 1;"></i> Penjualan
        </a>
        @can('sale.create')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('sales.create') ? 'c-active' : '' }}"
                       href="{{ route('sales.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Penjualan
                    </a>
                </li>
            </ul>
        @endcan

        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('sales.index') ? 'c-active' : '' }}"
                   href="{{ route('sales.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Penjualan
                </a>
            </li>
        </ul>

        @can('rsale.create')
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
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('sale-returns.index') ? 'c-active' : '' }}"
                   href="{{ route('sale-returns.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Retur Penjualan
                </a>
            </li>
        </ul>
    </li>
@endcan

@can('purchase.access')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('purchases.*') || request()->routeIs('purchase-payments*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-bag" style="line-height: 1;"></i> Pembelian
        </a>
        @can('purchase.create')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchases.create') ? 'c-active' : '' }}"
                       href="{{ route('purchases.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Pembelian
                    </a>
                </li>
            </ul>
        @endcan

        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('purchases.index') ? 'c-active' : '' }}"
                   href="{{ route('purchases.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Semua Pembelian
                </a>
            </li>
        </ul>

        @can('rpurchase.create')
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
        @can("rpurchase.access")
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
@endcan

<li class="c-sidebar-nav-item">
    <a class="c-sidebar-nav-link" href="#">
        <i class="c-sidebar-nav-icon bbi bi-receipt-cutoff" style="line-height: 1;"></i> Biaya
    </a>
</li>

<li class="c-sidebar-nav-divider"></li>

    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('customers.*') || request()->routeIs('suppliers.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-book" style="line-height: 1;"></i> Kontak
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('customer.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('customers.*') ? 'c-active' : '' }}"
                       href="{{ route('customers.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-people-fill" style="line-height: 1;"></i> Pelanggan
                    </a>
                </li>
            @endcan
            @can('supplier.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('suppliers.*') ? 'c-active' : '' }}"
                       href="{{ route('suppliers.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-truck" style="line-height: 1;"></i> Pemasok
                    </a>
                </li>
            @endcan
        </ul>
    </li>

@can('product.access')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('products.*') || request()->routeIs('product-categories.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bbi bi-box2-fill" style="line-height: 1;"></i> Produk
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('cproduct.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('product-categories.*') ? 'c-active' : '' }}"
                       href="{{ route('product-categories.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-collection" style="line-height: 1;"></i> Kategori Produk
                    </a>
                </li>
            @endcan

            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('products.index') ? 'c-active' : '' }}"
                   href="{{ route('products.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-box-seam" style="line-height: 1;"></i> Semua Produk
                </a>
            </li>
            @can('print_barcodes')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('barcode.print') ? 'c-active' : '' }}"
                       href="{{ route('barcode.print') }}">
                        <i class="c-sidebar-nav-icon bi bi-upc-scan" style="line-height: 1;"></i> Print Barcode
                    </a>
                </li>
            @endcan

            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('units*') ? 'c-active' : '' }}"
                   href="{{ route('units.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-file-binary" style="line-height: 1;"></i> Units
                </a>
            </li>

            @can('brand.access')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('brands*') ? 'c-active' : '' }}"
                       href="{{ route('brands.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-nvidia" style="line-height: 1;"></i> Merek
                    </a>
                </li>
            @endcan

        </ul>
    </li>
@endcan

@can('tfstock.access')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('transfers.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-journal-arrow-up" style="line-height: 1;"></i> Transfer Stock
        </a>
        @can("tfstock.create")
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

@canany(['adjustment.access','adjustment.create'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('adjustments.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-clipboard-check" style="line-height: 1;"></i> Stock Adjustments
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('adjustment.create')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('adjustments.create') ? 'c-active' : '' }}"
                       href="{{ route('adjustments.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Penyesuain
                    </a>
                </li>
            @endcan
            @can("break.access")
             @can("break.create")
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
                @endcan
        </ul>
    </li>
@endcan

@canany(['access_user_management','users.access','role.access'])
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
            @can('role.access')
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

@canany(['access_settings','access_account','tax.access','payment_method.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('settings*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-wrench-adjustable" style="line-height: 1;"></i> Pengaturan
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('bussines_setting')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('settings*') ? 'c-active' : '' }}"
                       href="{{ route('settings.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-building-fill-gear" style="line-height: 1;"></i> Pengaturan
                        Bisnis
                    </a>
                </li>
            @endcan
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('businesses*') ? 'c-active' : '' }}"
                   href="{{ route('businesses.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Daftar Bisnis
                </a>
            </li>
            @can("tax.access")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('taxes*') ? 'c-active' : '' }}"
                       href="{{ route('taxes.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Daftar Pajak
                    </a>
                </li>
            @endcan
            @can("location.accces")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('locations*') ? 'c-active' : '' }}"
                       href="{{ route('locations.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Daftar Lokasi
                    </a>
                </li>
            @endcan
            @can("payment_term.access")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('payment-terms*') ? 'c-active' : '' }}"
                       href="{{ route('payment-terms.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Term Pembayaran
                    </a>
                </li>
            @endcan
            @can("payment_method.access")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('payment-methods*') ? 'c-active' : '' }}"
                       href="{{ route('payment-methods.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Metode Pembayaran
                    </a>
                </li>
            @endcan
            @can('access_account')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('chart-of-account*') ? 'c-active' : '' }}"
                       href="{{ route('chart-of-account.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-buildings-fill" style="line-height: 1;"></i> Daftar Nomor
                        Akun
                    </a>
                </li>
            @endcan
            {{--access_currencies|acces_setting--}}
            {{--            <li class="c-sidebar-nav-item">--}}
            {{--                <a class="c-sidebar-nav-link {{ request()->routeIs('units*') ? 'c-active' : '' }}" href="{{ route('units.index') }}">--}}
            {{--                    <i class="c-sidebar-nav-icon bi bi-calculator" style="line-height: 1;"></i> Units--}}
            {{--                </a>--}}
            {{--            </li>--}}
            {{--            <li class="c-sidebar-nav-item">--}}
            {{--                <a class="c-sidebar-nav-link {{ request()->routeIs('currencies*') ? 'c-active' : '' }}" href="{{ route('currencies.index') }}">--}}
            {{--                    <i class="c-sidebar-nav-icon bi bi-cash-stack" style="line-height: 1;"></i> Currencies--}}
            {{--                </a>--}}
            {{--            </li>--}}

        </ul>
    </li>
@endcan
{{--@can('access_currencies|access_settings')--}}
{{--    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('currencies*') || request()->routeIs('units*') ? 'c-show' : '' }}">--}}
{{--        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">--}}
{{--            <i class="c-sidebar-nav-icon bi bi-gear" style="line-height: 1;"></i> Settings--}}
{{--        </a>--}}
{{--        @can('access_units')--}}
{{--            <ul class="c-sidebar-nav-dropdown-items">--}}
{{--                <li class="c-sidebar-nav-item">--}}
{{--                    <a class="c-sidebar-nav-link {{ request()->routeIs('units*') ? 'c-active' : '' }}" href="{{ route('units.index') }}">--}}
{{--                        <i class="c-sidebar-nav-icon bi bi-calculator" style="line-height: 1;"></i> Units--}}
{{--                    </a>--}}
{{--                </li>--}}
{{--            </ul>--}}
{{--        @endcan--}}
{{--        @can('access_currencies')--}}
{{--        <ul class="c-sidebar-nav-dropdown-items">--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('currencies*') ? 'c-active' : '' }}" href="{{ route('currencies.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-cash-stack" style="line-height: 1;"></i> Currencies--}}
{{--                </a>--}}
{{--            </li>--}}
{{--        </ul>--}}
{{--        @endcan--}}
{{--        @can('access_settings')--}}
{{--        <ul class="c-sidebar-nav-dropdown-items">--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('settings*') ? 'c-active' : '' }}" href="{{ route('settings.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-sliders" style="line-height: 1;"></i> System Settings--}}
{{--                </a>--}}
{{--            </li>--}}
{{--        </ul>--}}
{{--        @endcan--}}
{{--    </li>--}}
{{--@endcan--}}


{{--@can('access_quotations')--}}
{{--    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('quotations.*') ? 'c-show' : '' }}">--}}
{{--        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">--}}
{{--            <i class="c-sidebar-nav-icon bi bi-cart-check" style="line-height: 1;"></i> Quotations--}}
{{--        </a>--}}
{{--        <ul class="c-sidebar-nav-dropdown-items">--}}
{{--            @can('create_adjustments')--}}
{{--                <li class="c-sidebar-nav-item">--}}
{{--                    <a class="c-sidebar-nav-link {{ request()->routeIs('quotations.create') ? 'c-active' : '' }}" href="{{ route('quotations.create') }}">--}}
{{--                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Create Quotation--}}
{{--                    </a>--}}
{{--                </li>--}}
{{--            @endcan--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('quotations.index') ? 'c-active' : '' }}" href="{{ route('quotations.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> All Quotations--}}
{{--                </a>--}}
{{--            </li>--}}
{{--        </ul>--}}
{{--    </li>--}}
{{--@endcan--}}

{{--@can('access_expenses')--}}
{{--    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('expenses.*') || request()->routeIs('expense-categories.*') ? 'c-show' : '' }}">--}}
{{--        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">--}}
{{--            <i class="c-sidebar-nav-icon bi bi-wallet2" style="line-height: 1;"></i> Expenses--}}
{{--        </a>--}}
{{--        <ul class="c-sidebar-nav-dropdown-items">--}}
{{--            @can('access_expense_categories')--}}
{{--                <li class="c-sidebar-nav-item">--}}
{{--                    <a class="c-sidebar-nav-link {{ request()->routeIs('expense-categories.*') ? 'c-active' : '' }}" href="{{ route('expense-categories.index') }}">--}}
{{--                        <i class="c-sidebar-nav-icon bi bi-collection" style="line-height: 1;"></i> Categories--}}
{{--                    </a>--}}
{{--                </li>--}}
{{--            @endcan--}}
{{--            @can('create_expenses')--}}
{{--                <li class="c-sidebar-nav-item">--}}
{{--                    <a class="c-sidebar-nav-link {{ request()->routeIs('expenses.create') ? 'c-active' : '' }}" href="{{ route('expenses.create') }}">--}}
{{--                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Create Expense--}}
{{--                    </a>--}}
{{--                </li>--}}
{{--            @endcan--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('expenses.index') ? 'c-active' : '' }}" href="{{ route('expenses.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> All Expenses--}}
{{--                </a>--}}
{{--            </li>--}}
{{--        </ul>--}}
{{--    </li>--}}
{{--@endcan--}}

{{--@can('access_reports')--}}
{{--    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('*-report.index') ? 'c-show' : '' }}">--}}
{{--        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">--}}
{{--            <i class="c-sidebar-nav-icon bi bi-graph-up" style="line-height: 1;"></i> Reports--}}
{{--        </a>--}}
{{--        <ul class="c-sidebar-nav-dropdown-items">--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('profit-loss-report.index') ? 'c-active' : '' }}" href="{{ route('profit-loss-report.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-clipboard-data" style="line-height: 1;"></i> Profit / Loss Report--}}
{{--                </a>--}}
{{--            </li>--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('payments-report.index') ? 'c-active' : '' }}" href="{{ route('payments-report.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-clipboard-data" style="line-height: 1;"></i> Payments Report--}}
{{--                </a>--}}
{{--            </li>--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('sales-report.index') ? 'c-active' : '' }}" href="{{ route('sales-report.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-clipboard-data" style="line-height: 1;"></i> Sales Report--}}
{{--                </a>--}}
{{--            </li>--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('purchases-report.index') ? 'c-active' : '' }}" href="{{ route('purchases-report.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-clipboard-data" style="line-height: 1;"></i> Purchases Report--}}
{{--                </a>--}}
{{--            </li>--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('sales-return-report.index') ? 'c-active' : '' }}" href="{{ route('sales-return-report.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-clipboard-data" style="line-height: 1;"></i> Sales Return Report--}}
{{--                </a>--}}
{{--            </li>--}}
{{--            <li class="c-sidebar-nav-item">--}}
{{--                <a class="c-sidebar-nav-link {{ request()->routeIs('purchases-return-report.index') ? 'c-active' : '' }}" href="{{ route('purchases-return-report.index') }}">--}}
{{--                    <i class="c-sidebar-nav-icon bi bi-clipboard-data" style="line-height: 1;"></i> Purchases Return Report--}}
{{--                </a>--}}
{{--            </li>--}}
{{--        </ul>--}}
{{--    </li>--}}
{{--@endcan--}}

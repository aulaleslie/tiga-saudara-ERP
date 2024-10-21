<li class="c-sidebar-nav-item {{ request()->routeIs('home') ? 'c-active' : '' }}">
    <a class="c-sidebar-nav-link" href="{{ route('home') }}">
        <i class="c-sidebar-nav-icon bbi bi-houses-fill" style="line-height: 1;"></i> Beranda
    </a>
</li>

@can('access_products')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('products.*') || request()->routeIs('product-categories.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#" style="margin: 2px 0;">
            <i class="c-sidebar-nav-icon bbi bi-box2-fill" style="line-height: 1;"></i> Produk
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('access_product_categories')
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

@canany(['adjustment.access','adjustment.create'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('adjustments.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#" style="margin: 2px 0;">
            <i class="c-sidebar-nav-icon bi bi-clipboard-check" style="line-height: 1;"></i> Penyesuaian Stock
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('adjustment.create')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('adjustments.create') ? 'c-active' : '' }}"
                       href="{{ route('adjustments.create') }}" style="margin: 2px 0;">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Penyesuaian
                    </a>
                </li>
            @endcan
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('adjustments.createBreakage') ? 'c-active' : '' }}"
                   href="{{ route('adjustments.createBreakage') }}" style="margin: 2px 0;">
                    <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Penyesuaian Barang Rusak
                </a>
            </li>
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('adjustments.index') ? 'c-active' : '' }}"
                   href="{{ route('adjustments.index') }}" style="margin: 2px 0;">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Semua Penyesuaian
                </a>
            </li>
        </ul>
    </li>
@endcan

@can('access_adjustments')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('transfers.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#" style="margin: 2px 0;">
            <i class="c-sidebar-nav-icon bi bi-clipboard-check" style="line-height: 1;"></i> Transfer Stock
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('transfers.create') ? 'c-active' : '' }}"
                   href="{{ route('transfers.create') }}">
                    <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Transfer Stock
                </a>
            </li>
        </ul>
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('transfers.index') ? 'c-active' : '' }}"
                   href="{{ route('transfers.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i>List Transfer Stock
                </a>
            </li>
        </ul>
    </li>
@endcan

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

@can('access_purchases')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('purchases.*') || request()->routeIs('purchase-payments*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-bag" style="line-height: 1;"></i> Pembelian
        </a>
        @can('create_purchase')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchases.create') ? 'c-active' : '' }}" href="{{ route('purchases.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Tambahkan Pembelian
                    </a>
                </li>
            </ul>
        @endcan
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('purchases.index') ? 'c-active' : '' }}" href="{{ route('purchases.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Pembelian
                </a>
            </li>
        </ul>
    </li>
@endcan

@can('access_purchase_returns')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('purchase-returns.*') || request()->routeIs('purchase-return-payments.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-arrow-return-right" style="line-height: 1;"></i> Pengembalian Pembelian
        </a>
        @can('create_purchase_returns')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('purchase-returns.create') ? 'c-active' : '' }}" href="{{ route('purchase-returns.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Buat Pengembalian Pembelian
                    </a>
                </li>
            </ul>
        @endcan
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('purchase-returns.index') ? 'c-active' : '' }}" href="{{ route('purchase-returns.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Pengembalian Pembelian
                </a>
            </li>
        </ul>
    </li>
@endcan

@can('access_sales')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('sales.*') || request()->routeIs('sale-payments*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-receipt" style="line-height: 1;"></i> Penjualan
        </a>
        @can('create_sales')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('sales.create') ? 'c-active' : '' }}" href="{{ route('sales.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Tambahkan Penjualan
                    </a>
                </li>
            </ul>
        @endcan
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('sales.index') ? 'c-active' : '' }}" href="{{ route('sales.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Penjualan
                </a>
            </li>
        </ul>
    </li>
@endcan

@can('access_sale_returns')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('sale-returns.*') || request()->routeIs('sale-return-payments.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#">
            <i class="c-sidebar-nav-icon bi bi-arrow-return-left" style="line-height: 1;"></i> Pengembalian Penjualan
        </a>
        @can('create_sale_returns')
            <ul class="c-sidebar-nav-dropdown-items">
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('sale-returns.create') ? 'c-active' : '' }}" href="{{ route('sale-returns.create') }}">
                        <i class="c-sidebar-nav-icon bi bi-journal-plus" style="line-height: 1;"></i> Tambah Pengembalian Penjualan
                    </a>
                </li>
            </ul>
        @endcan
        <ul class="c-sidebar-nav-dropdown-items">
            <li class="c-sidebar-nav-item">
                <a class="c-sidebar-nav-link {{ request()->routeIs('sale-returns.index') ? 'c-active' : '' }}" href="{{ route('sale-returns.index') }}">
                    <i class="c-sidebar-nav-icon bi bi-journals" style="line-height: 1;"></i> Daftar Pengembalian Penjualan
                </a>
            </li>
        </ul>
    </li>
@endcan

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

@can('access_customers|access_suppliers')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('customers.*') || request()->routeIs('suppliers.*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#" style="margin: 2px 0;">
            <i class="c-sidebar-nav-icon bi bi-people" style="line-height: 1;"></i> Parties
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can('access_customers')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('customers.*') ? 'c-active' : '' }}"
                       href="{{ route('customers.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-people-fill" style="line-height: 1;"></i> Pelanggan
                    </a>
                </li>
            @endcan
            @can('access_suppliers')
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('suppliers.*') ? 'c-active' : '' }}"
                       href="{{ route('suppliers.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-people-fill" style="line-height: 1;"></i> Pemasok
                    </a>
                </li>
            @endcan
        </ul>
    </li>
@endcan

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
@canany(['access_user_management','users.access','role.access'])
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('users*') || request()->routeIs('roles*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#" style="margin: 2px 0;">
            <i class="c-sidebar-nav-icon bi bi-person-fill-gear" style="line-height: 1;"></i> Pengelolaan Pengguna
        </a>
        <ul class="c-sidebar-nav-dropdown-items">
            @can("users.access")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('users*') ? 'c-active' : '' }}"
                       href="{{ route('users.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-people" style="line-height: 1;"></i> Semua Pengguna
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

@can('access_settings')
    <li class="c-sidebar-nav-item c-sidebar-nav-dropdown {{ request()->routeIs('settings*') ? 'c-show' : '' }}">
        <a class="c-sidebar-nav-link c-sidebar-nav-dropdown-toggle" href="#" style="margin: 2px 0;">
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
            @can("location.accces")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('locations*') ? 'c-active' : '' }}"
                       href="{{ route('locations.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-shop" style="line-height: 1;"></i> Daftar Lokasi
                    </a>
                </li>
            @endcan
            @can("tax.accces")
                <li class="c-sidebar-nav-item">
                    <a class="c-sidebar-nav-link {{ request()->routeIs('taxes*') ? 'c-active' : '' }}"
                       href="{{ route('taxes.index') }}">
                        <i class="c-sidebar-nav-icon bi bi-bank2" style="line-height: 1;"></i> Daftar Pajak
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

@extends('layouts.app')

@section('title', 'Buat Role')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('roles.index') }}">Peran</a></li>
        <li class="breadcrumb-item active">Buat</li>
    </ol>
@endsection

@push('page_css')
    <style>
        .custom-control-label {
            cursor: pointer;
        }
    </style>
@endpush

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                @include('utils.alerts')
                <form action="{{ route('roles.store') }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Buat Peran <i class="bi bi-check"></i></button>
                        <a href="{{ route('roles.index') }}" class="btn btn-secondary">Kembali</a>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <div class="form-group">
                                <label for="name">Role Name <span class="text-danger">*</span></label>
                                <input class="form-control" type="text" name="name" required>
                            </div>

                            <hr>

                            <div class="form-group">
                                <label for="permissions">Permissions <span class="text-danger">*</span></label>
                            </div>

                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="select-all">
                                    <label class="custom-control-label" for="select-all">Beri Semua Hak Akses</label>
                                </div>
                            </div>

                            <div class="row">
                                <!-- Dashboard Permissions -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Dashboard
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-dashboard">
                                                <label class="custom-control-label" for="select-all-dashboard">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="dashboard" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_total_stats" name="permissions[]"
                                                               value="show_total_stats" {{ old('show_total_stats') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_total_stats">Total Stats</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_total_income" name="permissions[]"
                                                               value="show_total_income" {{ old('show_total_income') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_total_income">Total Pendapatan</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_total_sales" name="permissions[]"
                                                               value="show_total_sales" {{ old('show_total_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_total_sales">Total Penjualan</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_total_purchase" name="permissions[]"
                                                               value="show_total_purchase" {{ old('show_total_purchase') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_total_purchase">Total Pembelian</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_notifications" name="permissions[]"
                                                               value="show_notifications" {{ old('show_notifications') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_notifications">Notifications</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_month_overview" name="permissions[]"
                                                               value="show_month_overview" {{ old('show_month_overview') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_month_overview">Month Overview</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_weekly_sales_purchases" name="permissions[]"
                                                               value="show_weekly_sales_purchases" {{ old('show_weekly_sales_purchases') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_weekly_sales_purchases">Weekly Sales & Purchases</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_monthly_cashflow" name="permissions[]"
                                                               value="show_monthly_cashflow" {{ old('show_monthly_cashflow') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_monthly_cashflow">Monthly Cashflow</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sale -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Penjualan
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-sale">
                                                <label class="custom-control-label" for="select-all-sale">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="sale" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="sale.access" name="permissions[]"
                                                               value="sale.access" {{ old('sale.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="sale.create" name="permissions[]"
                                                               value="sale.create" {{ old('sale.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="sale.edit" name="permissions[]"
                                                               value="sale.edit" {{ old('sale.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="sale.delete" name="permissions[]"
                                                               value="sale.delete" {{ old('sale.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sale Retur -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Retur Penjualan
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-rsale">
                                                <label class="custom-control-label" for="select-all-rsale">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="rsale" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rsale.access" name="permissions[]"
                                                               value="rsale.access" {{ old('rsale.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rsale.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rsale.create" name="permissions[]"
                                                               value="rsale.create" {{ old('rsale.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rsale.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rsale.edit" name="permissions[]"
                                                               value="rsale.edit" {{ old('rsale.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rsale.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rsale.delete" name="permissions[]"
                                                               value="rsale.delete" {{ old('rsale.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rsale.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Purchase -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Pembelian
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-purchase">
                                                <label class="custom-control-label" for="select-all-purchase">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="purchase" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.access" name="permissions[]"
                                                               value="purchase.access" {{ old('purchase.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.create" name="permissions[]"
                                                               value="purchase.create" {{ old('purchase.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.edit" name="permissions[]"
                                                               value="purchase.edit" {{ old('purchase.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.delete" name="permissions[]"
                                                               value="purchase.delete" {{ old('purchase.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Purchase Retur -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Pembelian Retur
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-rpurchase">
                                                <label class="custom-control-label" for="select-all-rpurchase">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="rpurchase" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rpurchase.access" name="permissions[]"
                                                               value="rpurchase.access" {{ old('rpurchase.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rpurchase.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rpurchase.create" name="permissions[]"
                                                               value="rpurchase.create" {{ old('rpurchase.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rpurchase.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rpurchase.edit" name="permissions[]"
                                                               value="rpurchase.edit" {{ old('rpurchase.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rpurchase.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rpurchase.delete" name="permissions[]"
                                                               value="rpurchase.delete" {{ old('rpurchase.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rpurchase.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Cost -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Biaya
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-cost">
                                                <label class="custom-control-label" for="select-all-cost">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="cost" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cost.access" name="permissions[]"
                                                               value="cost.access" {{ old('cost.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cost.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cost.create" name="permissions[]"
                                                               value="cost.create" {{ old('cost.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cost.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cost.edit" name="permissions[]"
                                                               value="cost.edit" {{ old('cost.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cost.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cost.delete" name="permissions[]"
                                                               value="cost.delete" {{ old('cost.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cost.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- pelanggan -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Pelanggan
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-pelanggan">
                                                <label class="custom-control-label" for="select-all-pelanggan">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="pelanggan" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.access" name="permissions[]"
                                                               value="customer.access" {{ old('customer.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="customer.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.create" name="permissions[]"
                                                               value="customer.create" {{ old('customer.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="customer.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.edit" name="permissions[]"
                                                               value="customer.edit" {{ old('customer.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="customer.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.delete" name="permissions[]"
                                                               value="customer.delete" {{ old('customer.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="customer.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- pemasok -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Pemasok
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-pemasok">
                                                <label class="custom-control-label" for="select-all-pemasok">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="pemasok" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.access" name="permissions[]"
                                                               value="supplier.access" {{ old('supplier.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="supplier.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.create" name="permissions[]"
                                                               value="supplier.create" {{ old('supplier.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="supplier.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.edit" name="permissions[]"
                                                               value="supplier.edit" {{ old('supplier.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="supplier.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.delete" name="permissions[]"
                                                               value="supplier.delete" {{ old('supplier.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="supplier.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Peran dan Ijin -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Peran dan Ijin
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-ijin">
                                                <label class="custom-control-label" for="select-all-ijin">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="ijin" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="role.access" name="permissions[]"
                                                               value="role.access" {{ old('role.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="role.accces">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="role.create" name="permissions[]"
                                                               value="role.create" {{ old('role.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="role.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="role.edit" name="permissions[]"
                                                               value="role.edit" {{ old('role.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="role.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="role.delete" name="permissions[]"
                                                               value="role.delete" {{ old('role.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="role.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Products Permission -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Products
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-product">
                                                <label class="custom-control-label" for="select-all-product">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="product" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_products" name="permissions[]"
                                                               value="access_products" {{ old('access_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_products">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_products" name="permissions[]"
                                                               value="show_products" {{ old('show_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_products">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_products" name="permissions[]"
                                                               value="create_products" {{ old('create_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_products">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_products" name="permissions[]"
                                                               value="edit_products" {{ old('edit_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_products">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_products" name="permissions[]"
                                                               value="delete_products" {{ old('delete_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_products">Delete</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_product_categories" name="permissions[]"
                                                               value="access_product_categories" {{ old('access_product_categories') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_product_categories">Category</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="print_barcodes" name="permissions[]"
                                                               value="print_barcodes" {{ old('print_barcodes') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="print_barcodes">Print Barcodes</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="view_access_table_product" name="permissions[]"
                                                               value="view_access_table_product" {{ old('view_access_table_product') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="view_access_table_product">Akses Tabel</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Merek -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Merek
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-merek">
                                                <label class="custom-control-label" for="select-all-merek">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="merek" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="brand.access" name="permissions[]"
                                                               value="brand.access" {{ old('brand.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="brand.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="brand.create" name="permissions[]"
                                                               value="brand.create" {{ old('brand.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="brand.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="brand.edit" name="permissions[]"
                                                               value="brand.edit" {{ old('brand.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="brand.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="brand.delete" name="permissions[]"
                                                               value="delete_products" {{ old('brand.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="brand.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Transfer Stock -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Transfer Stok
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-tfstock">
                                                <label class="custom-control-label" for="select-all-tfstock">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="tfstock" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tfstock.access" name="permissions[]"
                                                               value="tfstock.access" {{ old('tfstock.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tfstock.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tfstock.create" name="permissions[]"
                                                               value="tfstock.create" {{ old('tfstock.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tfstock.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tfstock.edit" name="permissions[]"
                                                               value="tfstock.edit" {{ old('tfstock.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tfstock.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tfstock.delete" name="permissions[]"
                                                               value="tfstock.delete" {{ old('tfstock.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tfstock.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Stock Adjustments -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Penyesuaian Stok
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-adjustment">
                                                <label class="custom-control-label" for="select-all-adjustment">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="adjustment" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="adjustment.access" name="permissions[]"
                                                               value="adjustment.access" {{ old('adjustment.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="adjustment.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="adjustment.create" name="permissions[]"
                                                               value="adjustment.create" {{ old('adjustment.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="adjustment.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="adjustment.edit" name="permissions[]"
                                                               value="adjustment.edit" {{ old('adjustment.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="adjustment.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="adjustment.delete" name="permissions[]"
                                                               value="adjustment.delete" {{ old('adjustment.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="adjustment.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Breakage -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Breakage
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-break">
                                                <label class="custom-control-label" for="select-all-break">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="break" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="break.access" name="permissions[]"
                                                               value="break.access" {{ old('break.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="break.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="break.create" name="permissions[]"
                                                               value="break.create" {{ old('break.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="break.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="break.edit" name="permissions[]"
                                                               value="break.edit" {{ old('break.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="break.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="break.delete" name="permissions[]"
                                                               value="break.delete" {{ old('break.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="break.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pengaturan Pengguna Permission -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Manajemen Pengguna
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-user-settings">
                                                <label class="custom-control-label" for="select-all-user-settings">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="user-settings" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.access" name="permissions[]"
                                                               value="users.access" {{ old('users.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.create" name="permissions[]"
                                                               value="users.create" {{ old('users.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.edit" name="permissions[]"
                                                               value="users.edit" {{ old('users.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.delete" name="permissions[]"
                                                               value="users.delete" {{ old('users.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Settings -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Pengaturan
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-pengaturan">
                                                <label class="custom-control-label" for="select-all-pengaturan">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="pengaturan" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_settings" name="permissions[]"
                                                               value="access_settings" {{ old('access_settings') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_settings">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="bussines_setting" name="permissions[]"
                                                               value="bussines_setting" {{ old('bussines_setting') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="bussines_setting">Pengaturan Bisnis</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="crud_bussiness" name="permissions[]"
                                                               value="crud_bussiness" {{ old('crud_bussiness') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="crud_bussiness">Hak Akses Bisnis</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="view_bussiness" name="permissions[]"
                                                               value="view_bussiness" {{ old('view_bussiness') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="view_bussiness">Daftar Bisnis</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Lokasi -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Lokasi
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-lokasi">
                                                <label class="custom-control-label" for="select-all-lokasi">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="lokasi" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="location.access" name="permissions[]"
                                                               value="location.access" {{ old('location.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="location.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="location.create" name="permissions[]"
                                                               value="location.create" {{ old('location.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="location.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="location.edit" name="permissions[]"
                                                               value="location.edit" {{ old('location.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="location.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="location.delete" name="permissions[]"
                                                               value="location.delete" {{ old('location.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="location.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Account -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Nomor Akun
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-account">
                                                <label class="custom-control-label" for="select-all-account">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="account" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_account" name="permissions[]"
                                                               value="access_account" {{ old('access_account') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_account">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_account" name="permissions[]"
                                                               value="create_account" {{ old('create_account') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_account">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_account" name="permissions[]"
                                                               value="edit_account" {{ old('edit_account') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_account">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_account" name="permissions[]"
                                                               value="delete_account" {{ old('delete_account') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_account">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>


                                <!-- Adjustments Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Adjustments--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_adjustments" name="permissions[]"--}}
{{--                                                               value="access_adjustments" {{ old('access_adjustments') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_adjustments">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_adjustments" name="permissions[]"--}}
{{--                                                               value="create_adjustments" {{ old('create_adjustments') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_adjustments">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="show_adjustments" name="permissions[]"--}}
{{--                                                               value="show_adjustments" {{ old('show_adjustments') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="show_adjustments">View</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_adjustments" name="permissions[]"--}}
{{--                                                               value="edit_adjustments" {{ old('edit_adjustments') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_adjustments">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_adjustments" name="permissions[]"--}}
{{--                                                               value="delete_adjustments" {{ old('delete_adjustments') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_adjustments">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Quotations Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Quotaions--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_quotations" name="permissions[]"--}}
{{--                                                               value="access_quotations" {{ old('access_quotations') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_quotations">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_quotations" name="permissions[]"--}}
{{--                                                               value="create_quotations" {{ old('create_quotations') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_quotations">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="show_quotations" name="permissions[]"--}}
{{--                                                               value="show_quotations" {{ old('show_quotations') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="show_quotations">View</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_quotations" name="permissions[]"--}}
{{--                                                               value="edit_quotations" {{ old('edit_quotations') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_quotations">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_quotations" name="permissions[]"--}}
{{--                                                               value="delete_quotations" {{ old('delete_quotations') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_quotations">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="send_quotation_mails" name="permissions[]"--}}
{{--                                                               value="send_quotation_mails" {{ old('send_quotation_mails') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="send_quotation_mails">Send Email</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-12">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_quotation_sales" name="permissions[]"--}}
{{--                                                               value="create_quotation_sales" {{ old('create_quotation_sales') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_quotation_sales">Sale From Quotation</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Expenses Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Expenses--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_expenses" name="permissions[]"--}}
{{--                                                               value="access_expenses" {{ old('access_expenses') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_expenses">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_expenses" name="permissions[]"--}}
{{--                                                               value="create_expenses" {{ old('create_expenses') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_expenses">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_expenses" name="permissions[]"--}}
{{--                                                               value="edit_expenses" {{ old('edit_expenses') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_expenses">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_expenses" name="permissions[]"--}}
{{--                                                               value="delete_expenses" {{ old('delete_expenses') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_expenses">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_expense_categories" name="permissions[]"--}}
{{--                                                               value="access_expense_categories" {{ old('access_expense_categories') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_expense_categories">Category</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Customers Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Customers--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_customers" name="permissions[]"--}}
{{--                                                               value="access_customers" {{ old('access_customers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_customers">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_customers" name="permissions[]"--}}
{{--                                                               value="create_customers" {{ old('create_customers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_customers">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="show_customers" name="permissions[]"--}}
{{--                                                               value="show_customers" {{ old('show_customers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="show_customers">View</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_customers" name="permissions[]"--}}
{{--                                                               value="edit_customers" {{ old('edit_customers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_customers">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_customers" name="permissions[]"--}}
{{--                                                               value="delete_customers" {{ old('delete_customers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_customers">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Suppliers Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Suppliers--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_suppliers" name="permissions[]"--}}
{{--                                                               value="access_suppliers" {{ old('access_suppliers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_suppliers">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_suppliers" name="permissions[]"--}}
{{--                                                               value="create_suppliers" {{ old('create_suppliers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_suppliers">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="show_suppliers" name="permissions[]"--}}
{{--                                                               value="show_suppliers" {{ old('show_suppliers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="show_suppliers">View</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_suppliers" name="permissions[]"--}}
{{--                                                               value="edit_suppliers" {{ old('edit_suppliers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_suppliers">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_customers" name="permissions[]"--}}
{{--                                                               value="delete_customers" {{ old('delete_customers') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_customers">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Sales Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Sales--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_sales" name="permissions[]"--}}
{{--                                                               value="access_sales" {{ old('access_sales') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_sales">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_sales" name="permissions[]"--}}
{{--                                                               value="create_sales" {{ old('create_sales') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_sales">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="show_sales" name="permissions[]"--}}
{{--                                                               value="show_suppliers" {{ old('show_sales') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="show_sales">View</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_sales" name="permissions[]"--}}
{{--                                                               value="edit_sales" {{ old('edit_sales') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_sales">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_sales" name="permissions[]"--}}
{{--                                                               value="delete_sales" {{ old('delete_sales') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_sales">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_pos_sales" name="permissions[]"--}}
{{--                                                               value="create_pos_sales" {{ old('create_pos_sales') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_pos_sales">POS System</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_sale_payments" name="permissions[]"--}}
{{--                                                               value="access_sale_payments" {{ old('access_sale_payments') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_sale_payments">Payments</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Sale Returns Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Sale Returns--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_sale_returns" name="permissions[]"--}}
{{--                                                               value="access_sale_returns" {{ old('access_sale_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_sale_returns">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_sale_returns" name="permissions[]"--}}
{{--                                                               value="create_sale_returns" {{ old('create_sale_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_sale_returns">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="show_sale_returns" name="permissions[]"--}}
{{--                                                               value="show_sale_returns" {{ old('show_sale_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="show_sale_returns">View</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_sale_returns" name="permissions[]"--}}
{{--                                                               value="edit_sale_returns" {{ old('edit_sale_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_sale_returns">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_sale_returns" name="permissions[]"--}}
{{--                                                               value="delete_sale_returns" {{ old('delete_sale_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_sale_returns">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_sale_return_payments" name="permissions[]"--}}
{{--                                                               value="access_sale_return_payments" {{ old('access_sale_return_payments') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_sale_return_payments">Payments</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Purchases Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Purchases--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_purchases" name="permissions[]"--}}
{{--                                                               value="access_purchases" {{ old('access_purchases') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_purchases">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_purchases" name="permissions[]"--}}
{{--                                                               value="create_purchases" {{ old('create_purchases') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_purchases">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="show_purchases" name="permissions[]"--}}
{{--                                                               value="show_purchases" {{ old('show_purchases') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="show_purchases">View</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_purchases" name="permissions[]"--}}
{{--                                                               value="edit_purchases" {{ old('edit_purchases') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_purchases">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_purchases" name="permissions[]"--}}
{{--                                                               value="delete_purchases" {{ old('delete_purchases') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_purchases">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_purchase_payments" name="permissions[]"--}}
{{--                                                               value="access_purchase_payments" {{ old('access_purchase_payments') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_purchase_payments">Payments</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Purchases Returns Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Purchase Returns--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_purchase_returns" name="permissions[]"--}}
{{--                                                               value="access_purchase_returns" {{ old('access_purchase_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_purchase_returns">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_purchase_returns" name="permissions[]"--}}
{{--                                                               value="create_purchase_returns" {{ old('create_purchase_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_purchase_returns">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="show_purchase_returns" name="permissions[]"--}}
{{--                                                               value="show_purchase_returns" {{ old('show_purchase_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="show_purchase_returns">View</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_purchase_returns" name="permissions[]"--}}
{{--                                                               value="edit_purchase_returns" {{ old('edit_purchase_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_purchase_returns">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_purchase_returns" name="permissions[]"--}}
{{--                                                               value="delete_purchase_returns" {{ old('delete_purchase_returns') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_purchase_returns">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_purchase_return_payments" name="permissions[]"--}}
{{--                                                               value="access_purchase_return_payments" {{ old('access_purchase_return_payments') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_purchase_return_payments">Payments</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Currencies Permission -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Currencies--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_currencies" name="permissions[]"--}}
{{--                                                               value="access_currencies" {{ old('access_currencies') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_currencies">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="create_currencies" name="permissions[]"--}}
{{--                                                               value="create_currencies" {{ old('create_currencies') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="create_currencies">Buat</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="edit_currencies" name="permissions[]"--}}
{{--                                                               value="edit_currencies" {{ old('edit_currencies') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="edit_currencies">Ubah</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="delete_currencies" name="permissions[]"--}}
{{--                                                               value="delete_currencies" {{ old('delete_currencies') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="delete_currencies">Hapus</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}

                                <!-- Reports -->
{{--                                <div class="col-lg-4 col-md-6 mb-3">--}}
{{--                                    <div class="card h-100 border-0 shadow">--}}
{{--                                        <div class="card-header">--}}
{{--                                            Reports--}}
{{--                                        </div>--}}
{{--                                        <div class="card-body">--}}
{{--                                            <div class="row">--}}
{{--                                                <div class="col-6">--}}
{{--                                                    <div class="custom-control custom-switch">--}}
{{--                                                        <input type="checkbox" class="custom-control-input"--}}
{{--                                                               id="access_reports" name="permissions[]"--}}
{{--                                                               value="access_reports" {{ old('access_reports') ? 'checked' : '' }}>--}}
{{--                                                        <label class="custom-control-label" for="access_reports">Access</label>--}}
{{--                                                    </div>--}}
{{--                                                </div>--}}
{{--                                            </div>--}}
{{--                                        </div>--}}
{{--                                    </div>--}}
{{--                                </div>--}}
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        $(document).ready(function() {
            $('#select-all').click(function() {
                var checked = this.checked;
                $('input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-dashboard').click(function() {
                var checked = this.checked;
                $('#dashboard input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-user-settings').click(function() {
                var checked = this.checked;
                $('#user-settings input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-sale').click(function() {
                var checked = this.checked;
                $('#sale input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-rsale').click(function() {
                var checked = this.checked;
                $('#rsale input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-purchase').click(function() {
                var checked = this.checked;
                $('#purchase input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-rpurchase').click(function() {
                var checked = this.checked;
                $('#rpurchase input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-cost').click(function() {
                var checked = this.checked;
                $('#cost input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-account').click(function() {
                var checked = this.checked;
                $('#account input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-break').click(function() {
                var checked = this.checked;
                $('#break input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-break').click(function() {
                var checked = this.checked;
                $('#break input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-tfstock').click(function() {
                var checked = this.checked;
                $('#tfstock input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-product').click(function() {
                var checked = this.checked;
                $('#product input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-adjustment').click(function() {
                var checked = this.checked;
                $('#adjustment input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-pengaturan').click(function() {
                var checked = this.checked;
                $('#pengaturan input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-merek').click(function() {
                var checked = this.checked;
                $('#merek input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-lokasi').click(function() {
                var checked = this.checked;
                $('#lokasi input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-pelanggan').click(function() {
                var checked = this.checked;
                $('#pelanggan input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-pemasok').click(function() {
                var checked = this.checked;
                $('#pemasok input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-ijin').click(function() {
                var checked = this.checked;
                $('#ijin input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-tax').click(function() {
                var checked = this.checked;
                $('#tax input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });

        });
    </script>
@endpush

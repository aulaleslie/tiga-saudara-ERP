@extends('layouts.app')

@section('title', 'Ubah Role')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('roles.index') }}">Peran</a></li>
        <li class="breadcrumb-item active">Ubah</li>
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
                <form action="{{ route('roles.update', $role->id) }}" method="POST">
                    @csrf
                    @method('patch')
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Perbaharui Peran <i class="bi bi-check"></i>
                        </button>
                        <a href="{{ route('roles.index') }}" class="btn btn-secondary">Kembali</a>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <div class="form-group">
                                <label for="name">Nama Pengguna <span class="text-danger">*</span></label>
                                <input class="form-control" type="text" name="name" required value="{{ $role->name }}">
                            </div>

                            <hr>

                            <div class="form-group">
                                <label for="permissions">
                                    Hak Hak Akses <span class="text-danger">*</span>
                                </label>
                            </div>

                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="select-all">
                                    <label class="custom-control-label" for="select-all">Beri Semua Hak Hak Akses</label>
                                </div>
                            </div>

                            <div class="row">
                                <!-- Dashboard Permissions -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Dashboard
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-dashboard">
                                                <label class="custom-control-label" for="select-all-dashboard">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="dashboard" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_total_stats" name="permissions[]"
                                                               value="show_total_stats" {{ $role->hasPermissionTo('show_total_stats') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_total_stats">Total
                                                            Stats</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_total_income" name="permissions[]"
                                                               value="show_total_income" {{ $role->hasPermissionTo('show_total_income') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_total_income">Total
                                                            Pendapatan</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_total_sales" name="permissions[]"
                                                               value="show_total_sales" {{ $role->hasPermissionTo('show_total_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_total_sales">Total
                                                            Penjualan</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_total_purchase" name="permissions[]"
                                                               value="show_total_purchase" {{ $role->hasPermissionTo('show_total_purchase') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_total_purchase">Total
                                                            Pembelian</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_notifications" name="permissions[]"
                                                               value="show_notifications" {{ $role->hasPermissionTo('show_notifications') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_notifications">Notifications</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_month_overview" name="permissions[]"
                                                               value="show_month_overview" {{ $role->hasPermissionTo('show_month_overview') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_month_overview">Month
                                                            Overview</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_weekly_sales_purchases" name="permissions[]"
                                                               value="show_weekly_sales_purchases" {{ $role->hasPermissionTo('show_weekly_sales_purchases') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="show_weekly_sales_purchases">Weekly Sales &
                                                            Purchases</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_monthly_cashflow" name="permissions[]"
                                                               value="show_monthly_cashflow" {{ $role->hasPermissionTo('show_monthly_cashflow') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_monthly_cashflow">Monthly
                                                            Cashflow</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Penjualan -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Penjualan
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-sale">
                                                <label class="custom-control-label" for="select-all-sale">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="sale" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="sale.access" name="permissions[]"
                                                               value="sale.access" {{ $role->hasPermissionTo('sale.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.access">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="sale.create" name="permissions[]"
                                                               value="sale.create" {{ $role->hasPermissionTo('sale.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="sale.edit" name="permissions[]"
                                                               value="sale.edit" {{ $role->hasPermissionTo('sale.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="sale.delete" name="permissions[]"
                                                               value="sale.delete" {{ $role->hasPermissionTo('sale.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="sale.view" name="permissions[]"
                                                               value="sale.view" {{ $role->hasPermissionTo('sale.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.view">Lihat</label>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Retur Penjualan -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Retur Penjualan
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-rsale">
                                                <label class="custom-control-label" for="select-all-rsale">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="rsale" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rsale.access" name="permissions[]"
                                                               value="rsale.access" {{ $role->hasPermissionTo('rsale.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rsale.access">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rsale.create" name="permissions[]"
                                                               value="rsale.create" {{ $role->hasPermissionTo('rsale.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rsale.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rsale.edit" name="permissions[]"
                                                               value="rsale.edit" {{ $role->hasPermissionTo('rsale.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rsale.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rsale.delete" name="permissions[]"
                                                               value="rsale.delete" {{ $role->hasPermissionTo('rsale.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rsale.view" name="permissions[]"
                                                               value="rsale.view" {{ $role->hasPermissionTo('rsale.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="sale.view">Lihat</label>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!--  Pembelian -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                             Pembelian
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-purchase">
                                                <label class="custom-control-label" for="select-all-purchase">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="purchase" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.access" name="permissions[]"
                                                               value="purchase.access" {{ $role->hasPermissionTo('purchase.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.access">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.create" name="permissions[]"
                                                               value="purchase.create" {{ $role->hasPermissionTo('purchase.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.view" name="permissions[]"
                                                               value="purchase.view" {{ $role->hasPermissionTo('purchase.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.view">Lihat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.edit" name="permissions[]"
                                                               value="purchase.edit" {{ $role->hasPermissionTo('purchase.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.delete" name="permissions[]"
                                                               value="purchase.delete" {{ $role->hasPermissionTo('purchase.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="purchase.view" name="permissions[]"
                                                               value="purchase.view" {{ $role->hasPermissionTo('purchase.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="purchase.view">Lihat</label>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Retur Pembelian -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Retur Pembelian
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-rpurchase">
                                                <label class="custom-control-label" for="select-all-rpurchase">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="rpurchase" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rpurchase.access" name="permissions[]"
                                                               value="rpurchase.access" {{ $role->hasPermissionTo('rpurchase.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rpurchase.access">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rpurchase.create" name="permissions[]"
                                                               value="rpurchase.create" {{ $role->hasPermissionTo('rpurchase.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rpurchase.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rpurchase.edit" name="permissions[]"
                                                               value="rpurchase.edit" {{ $role->hasPermissionTo('rpurchase.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rpurchase.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rpurchase.delete" name="permissions[]"
                                                               value="rpurchase.delete" {{ $role->hasPermissionTo('rpurchase.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rpurchase.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="rpurchase.view" name="permissions[]"
                                                               value="rpurchase.view" {{ $role->hasPermissionTo('rpurchase.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="rpurchase.view">Lihat</label>
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
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-cost">
                                                <label class="custom-control-label" for="select-all-cost">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="cost" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cost.access" name="permissions[]"
                                                               value="cost.access" {{ $role->hasPermissionTo('cost.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cost.access">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cost.create" name="permissions[]"
                                                               value="cost.create" {{ $role->hasPermissionTo('cost.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cost.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cost.edit" name="permissions[]"
                                                               value="cost.edit" {{ $role->hasPermissionTo('cost.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cost.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cost.delete" name="permissions[]"
                                                               value="cost.delete" {{ $role->hasPermissionTo('cost.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cost.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cost.view" name="permissions[]"
                                                               value="cost.view" {{ $role->hasPermissionTo('cost.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cost.view">Lihat</label>
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
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-pelanggan">
                                                <label class="custom-control-label" for="select-all-pelanggan">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="pelanggan" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.access" name="permissions[]"
                                                               value="customer.access" {{ $role->hasPermissionTo('customer.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="customer.access">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.create" name="permissions[]"
                                                               value="customer.create" {{ $role->hasPermissionTo('customer.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="customer.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.edit" name="permissions[]"
                                                               value="customer.edit" {{ $role->hasPermissionTo('customer.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="customer.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.delete" name="permissions[]"
                                                               value="customer.delete" {{ $role->hasPermissionTo('customer.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="customer.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.view" name="permissions[]"
                                                               value="customer.view" {{ $role->hasPermissionTo('customer.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="customer.view">Lihat</label>
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
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-pemasok">
                                                <label class="custom-control-label" for="select-all-pemasok">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="pemasok" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.access" name="permissions[]"
                                                               value="supplier.access" {{ $role->hasPermissionTo('supplier.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="supplier.access">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.create" name="permissions[]"
                                                               value="supplier.create" {{ $role->hasPermissionTo('supplier.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="supplier.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.edit" name="permissions[]"
                                                               value="supplier.edit" {{ $role->hasPermissionTo('supplier.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="supplier.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.delete" name="permissions[]"
                                                               value="supplier.delete" {{ $role->hasPermissionTo('supplier.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="supplier.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.view" name="permissions[]"
                                                               value="supplier.view" {{ $role->hasPermissionTo('supplier.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="supplier.view">Lihat</label>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Roles Pengguna -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Peran dan Ijin
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-ijin">
                                                <label class="custom-control-label" for="select-all-ijin">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="ijin" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="role.access" name="permissions[]"
                                                               value="role.access" {{ $role->hasPermissionTo('role.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="role.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="role.create" name="permissions[]"
                                                               value="role.create" {{ $role->hasPermissionTo('role.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="role.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="role.edit" name="permissions[]"
                                                               value="role.edit" {{ $role->hasPermissionTo('role.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="role.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="role.delete" name="permissions[]"
                                                               value="role.delete" {{ $role->hasPermissionTo('role.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="role.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="role.view" name="permissions[]"
                                                               value="role.view" {{ $role->hasPermissionTo('role.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="role.view">Lihat</label>
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
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-product">
                                                <label class="custom-control-label" for="select-all-product">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="product" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="product.access" name="permissions[]"
                                                               value="product.access" {{ $role->hasPermissionTo('product.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="product.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="product.view" name="permissions[]"
                                                               value="product.view" {{ $role->hasPermissionTo('product.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="product.view">Lihat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="product.create" name="permissions[]"
                                                               value="product.create" {{ $role->hasPermissionTo('product.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="product.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="product.edit" name="permissions[]"
                                                               value="product.edit" {{ $role->hasPermissionTo('product.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="product.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_products" name="permissions[]"
                                                               value="delete_products" {{ $role->hasPermissionTo('delete_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_products">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="cproduct.access" name="permissions[]"
                                                               value="cproduct.access" {{ $role->hasPermissionTo('cproduct.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="cproduct.access">Daftar Kategori</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="print_barcodes" name="permissions[]"
                                                               value="print_barcodes" {{ $role->hasPermissionTo('print_barcodes') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="print_barcodes">Print Barcodes</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="view_access_table_product" name="permissions[]"
                                                               value="view_access_table_product" {{ $role->hasPermissionTo('view_access_table_product') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="view_access_table_product">Hak Akses Tabel</label>
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
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-merek">
                                                <label class="custom-control-label" for="select-all-merek">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="merek" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="brand.access" name="permissions[]"
                                                               value="brand.access" {{ $role->hasPermissionTo('brand.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="brand.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="brand.create" name="permissions[]"
                                                               value="brand.create" {{ $role->hasPermissionTo('brand.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="brand.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="brand.edit" name="permissions[]"
                                                               value="brand.edit" {{ $role->hasPermissionTo('brand.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="brand.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="brand.delete" name="permissions[]"
                                                               value="brand.delete" {{ $role->hasPermissionTo('brand.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="brand.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="brand.view" name="permissions[]"
                                                               value="brand.view" {{ $role->hasPermissionTo('brand.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="brand.view">Lihat</label>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Transfer Stok -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Transfer Stok
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-tfstock">
                                                <label class="custom-control-label" for="select-all-tfstock">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="tfstock" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tfstock.access" name="permissions[]"
                                                               value="tfstock.access" {{ $role->hasPermissionTo('tfstock.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tfstock.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tfstock.create" name="permissions[]"
                                                               value="tfstock.create" {{ $role->hasPermissionTo('tfstock.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tfstock.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tfstock.edit" name="permissions[]"
                                                               value="tfstock.edit" {{ $role->hasPermissionTo('tfstock.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tfstock.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tfstock.delete" name="permissions[]"
                                                               value="tfstock.delete" {{ $role->hasPermissionTo('tfstock.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tfstock.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tfstock.view" name="permissions[]"
                                                               value="tfstock.view" {{ $role->hasPermissionTo('tfstock.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tfstock.view">Lihat</label>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Stock Adjustment -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Penyesuaian Stok
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-adjustment">
                                                <label class="custom-control-label" for="select-all-adjustment">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="adjustment" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="adjustment.access" name="permissions[]"
                                                               value="adjustment.access" {{ $role->hasPermissionTo('adjustment.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="adjustment.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="adjustment.create" name="permissions[]"
                                                               value="adjustment.create" {{ $role->hasPermissionTo('adjustment.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="adjustment.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="adjustment.edit" name="permissions[]"
                                                               value="adjustment.edit" {{ $role->hasPermissionTo('adjustment.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="adjustment.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="adjustment.delete" name="permissions[]"
                                                               value="adjustment.delete" {{ $role->hasPermissionTo('adjustment.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="adjustment.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="adjustment.view" name="permissions[]"
                                                               value="adjustment.view" {{ $role->hasPermissionTo('adjustment.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="adjustment.view">Lihat</label>
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
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-break">
                                                <label class="custom-control-label" for="select-all-break">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="break" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="break.access" name="permissions[]"
                                                               value="break.access" {{ $role->hasPermissionTo('break.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="break.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="break.create" name="permissions[]"
                                                               value="break.create" {{ $role->hasPermissionTo('break.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="break.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="break.edit" name="permissions[]"
                                                               value="break.edit" {{ $role->hasPermissionTo('break.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="break.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="break.delete" name="permissions[]"
                                                               value="break.delete" {{ $role->hasPermissionTo('break.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="break.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="break.view" name="permissions[]"
                                                               value="break.view" {{ $role->hasPermissionTo('break.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="break.view">Lihat</label>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- User Management Permission -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Manajemen Pengguna
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-user-settings">
                                                <label class="custom-control-label" for="select-all-user-settings">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="user-settings" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.access" name="permissions[]"
                                                               value="users.access" {{ $role->hasPermissionTo('users.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.access">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.create" name="permissions[]"
                                                               value="users.create" {{ $role->hasPermissionTo('users.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.edit" name="permissions[]"
                                                               value="users.edit" {{ $role->hasPermissionTo('users.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.delete" name="permissions[]"
                                                               value="users.delete" {{ $role->hasPermissionTo('users.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.view" name="permissions[]"
                                                               value="users.view" {{ $role->hasPermissionTo('users.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.view">Lihat</label>
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
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-pengaturan">
                                                <label class="custom-control-label" for="select-all-pengaturan">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="pengaturan" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_settings" name="permissions[]"
                                                               value="access_settings" {{ $role->hasPermissionTo('access_settings') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="access_settings">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="bussines_setting" name="permissions[]"
                                                               value="bussines_setting" {{ $role->hasPermissionTo('bussines_setting') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="bussines_setting">Pengaturan Bisnis</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="crud_bussiness" name="permissions[]"
                                                               value="crud_bussiness" {{ $role->hasPermissionTo('crud_bussiness') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="crud_bussiness">Hak Hak Akses Bisnis</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="view_bussiness" name="permissions[]"
                                                               value="view_bussiness" {{ $role->hasPermissionTo('view_bussiness') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="view_bussiness">Daftar Bisnis</label>
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
                                                <input type="checkbox" class="custom-control-input"
                                                       id="select-all-lokasi">
                                                <label class="custom-control-label" for="select-all-lokasi">Pilih
                                                    Semua</label>
                                            </div>
                                        </div>
                                        <div id="lokasi" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="location.access" name="permissions[]"
                                                               value="location.access" {{ $role->hasPermissionTo('location.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="location.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="location.create" name="permissions[]"
                                                               value="location.create" {{ $role->hasPermissionTo('location.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="location.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="location.edit" name="permissions[]"
                                                               value="location.edit" {{ $role->hasPermissionTo('location.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="location.edit">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="location.delete" name="permissions[]"
                                                               value="location.delete" {{ $role->hasPermissionTo('location.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="location.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="location.view" name="permissions[]"
                                                               value="location.view" {{ $role->hasPermissionTo('location.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="location.view">Lihat</label>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Term Pembayaran -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Term Pembayaran
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-payment-term">
                                                <label class="custom-control-label" for="select-all-payment-term">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="paymentTerm" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.access" name="permissions[]"
                                                               value="payment_term.access" {{ $role->hasPermissionTo('payment_term.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.create" name="permissions[]"
                                                               value="payment_term.create" {{ $role->hasPermissionTo('payment_term.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.update" name="permissions[]"
                                                               value="payment_term.update" {{ $role->hasPermissionTo('payment_term.update') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.update">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.delete" name="permissions[]"
                                                               value="payment_term.delete" {{ $role->hasPermissionTo('payment_term.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.delete">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.show" name="permissions[]"
                                                               value="payment_term.show" {{ $role->hasPermissionTo('payment_term.show') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.show">Lihat</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Nomor Akun -->
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
                                                               value="access_account" {{ $role->hasPermissionTo('access_account') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_account">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_account" name="permissions[]"
                                                               value="create_account" {{ $role->hasPermissionTo('create_account') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_account">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_account" name="permissions[]"
                                                               value="edit_account" {{ $role->hasPermissionTo('edit_account') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_account">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_account" name="permissions[]"
                                                               value="delete_account" {{ $role->hasPermissionTo('delete_account') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_account">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_account" name="permissions[]"
                                                               value="show_account" {{ $role->hasPermissionTo('show_account') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_account">Lihat</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Metode Pembayaran -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Metode Pembayaran
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-payment-method">
                                                <label class="custom-control-label" for="select-all-payment-method">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="payment-method" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.access" name="permissions[]"
                                                               value="payment_term.access" {{ $role->hasPermissionTo('payment_term.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.access">Hak Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.show" name="permissions[]"
                                                               value="payment_term.show" {{ $role->hasPermissionTo('payment_term.show') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.show">Lihat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.create" name="permissions[]"
                                                               value="payment_term.create" {{ $role->hasPermissionTo('payment_term.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.update" name="permissions[]"
                                                               value="payment_term.update" {{ $role->hasPermissionTo('payment_term.update') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.update">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="payment_term.delete" name="permissions[]"
                                                               value="payment_term.delete" {{ $role->hasPermissionTo('payment_term.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="payment_term.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pajak -->



                                <!-- Adjustments Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Adjustments
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_adjustments" name="permissions[]"
                                                               value="access_adjustments" {{ $role->hasPermissionTo('adjustment.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_adjustments">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_adjustments" name="permissions[]"
                                                               value="create_adjustments" {{ $role->hasPermissionTo('adjustment.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_adjustments">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_adjustments" name="permissions[]"
                                                               value="show_adjustments" {{ $role->hasPermissionTo('adjustment.view') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_adjustments">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_adjustments" name="permissions[]"
                                                               value="edit_adjustments" {{ $role->hasPermissionTo('adjustment.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_adjustments">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_adjustments" name="permissions[]"
                                                               value="delete_adjustments" {{ $role->hasPermissionTo('adjustment.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_adjustments">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Quotations Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Quotaions
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_quotations" name="permissions[]"
                                                               value="access_quotations" {{ $role->hasPermissionTo('access_quotations') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_quotations">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_quotations" name="permissions[]"
                                                               value="create_quotations" {{ $role->hasPermissionTo('create_quotations') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_quotations">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_quotations" name="permissions[]"
                                                               value="show_quotations" {{ $role->hasPermissionTo('show_quotations') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_quotations">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_quotations" name="permissions[]"
                                                               value="edit_quotations" {{ $role->hasPermissionTo('edit_quotations') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_quotations">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_quotations" name="permissions[]"
                                                               value="delete_quotations" {{ $role->hasPermissionTo('delete_quotations') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_quotations">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="send_quotation_mails" name="permissions[]"
                                                               value="send_quotation_mails" {{ $role->hasPermissionTo('send_quotation_mails') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="send_quotation_mails">Send Email</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_quotation_sales" name="permissions[]"
                                                               value="create_quotation_sales" {{ $role->hasPermissionTo('create_quotation_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_quotation_sales">Sale From Quotation</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Expenses Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Expenses
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_expenses" name="permissions[]"
                                                               value="access_expenses" {{ $role->hasPermissionTo('access_expenses') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_expenses">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_expenses" name="permissions[]"
                                                               value="create_expenses" {{ $role->hasPermissionTo('create_expenses') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_expenses">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_expenses" name="permissions[]"
                                                               value="edit_expenses" {{ $role->hasPermissionTo('edit_expenses') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_expenses">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_expenses" name="permissions[]"
                                                               value="delete_expenses" {{ $role->hasPermissionTo('delete_expenses') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_expenses">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_expense_categories" name="permissions[]"
                                                               value="access_expense_categories" {{ $role->hasPermissionTo('access_expense_categories') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_expense_categories">Category</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Customers Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Customers
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_customers" name="permissions[]"
                                                               value="access_customers" {{ $role->hasPermissionTo('access_customers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_customers">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_customers" name="permissions[]"
                                                               value="create_customers" {{ $role->hasPermissionTo('create_customers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_customers">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_customers" name="permissions[]"
                                                               value="show_customers" {{ $role->hasPermissionTo('show_customers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_customers">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_customers" name="permissions[]"
                                                               value="edit_customers" {{ $role->hasPermissionTo('edit_customers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_customers">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_customers" name="permissions[]"
                                                               value="delete_customers" {{ $role->hasPermissionTo('delete_customers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_customers">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Suppliers Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Suppliers
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_suppliers" name="permissions[]"
                                                               value="access_suppliers" {{ $role->hasPermissionTo('access_suppliers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_suppliers">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_suppliers" name="permissions[]"
                                                               value="create_suppliers" {{ $role->hasPermissionTo('create_suppliers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_suppliers">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_suppliers" name="permissions[]"
                                                               value="show_suppliers" {{ $role->hasPermissionTo('show_suppliers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_suppliers">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_suppliers" name="permissions[]"
                                                               value="edit_suppliers" {{ $role->hasPermissionTo('edit_suppliers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_suppliers">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_customers" name="permissions[]"
                                                               value="delete_customers" {{ $role->hasPermissionTo('delete_customers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_customers">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Sales Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Sales
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_sales" name="permissions[]"
                                                               value="access_sales" {{ $role->hasPermissionTo('access_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_sales">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_sales" name="permissions[]"
                                                               value="create_sales" {{ $role->hasPermissionTo('create_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_sales">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_sales" name="permissions[]"
                                                               value="show_suppliers" {{ $role->hasPermissionTo('show_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_sales">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_sales" name="permissions[]"
                                                               value="edit_sales" {{ $role->hasPermissionTo('edit_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_sales">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_sales" name="permissions[]"
                                                               value="delete_sales" {{ $role->hasPermissionTo('delete_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_sales">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_pos_sales" name="permissions[]"
                                                               value="create_pos_sales" {{ $role->hasPermissionTo('create_pos_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_pos_sales">POS System</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_sale_payments" name="permissions[]"
                                                               value="access_sale_payments" {{ $role->hasPermissionTo('access_sale_payments') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_sale_payments">Payments</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Sale Returns Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Sale Returns
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_sale_returns" name="permissions[]"
                                                               value="access_sale_returns" {{ $role->hasPermissionTo('access_sale_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_sale_returns">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_sale_returns" name="permissions[]"
                                                               value="create_sale_returns" {{ $role->hasPermissionTo('create_sale_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_sale_returns">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_sale_returns" name="permissions[]"
                                                               value="show_sale_returns" {{ $role->hasPermissionTo('show_sale_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_sale_returns">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_sale_returns" name="permissions[]"
                                                               value="edit_sale_returns" {{ $role->hasPermissionTo('edit_sale_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_sale_returns">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_sale_returns" name="permissions[]"
                                                               value="delete_sale_returns" {{ $role->hasPermissionTo('delete_sale_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_sale_returns">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_sale_return_payments" name="permissions[]"
                                                               value="access_sale_return_payments" {{ $role->hasPermissionTo('access_sale_return_payments') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_sale_return_payments">Payments</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Purchases Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Purchases
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_purchases" name="permissions[]"
                                                               value="access_purchases" {{ $role->hasPermissionTo('access_purchases') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_purchases">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_purchases" name="permissions[]"
                                                               value="create_purchases" {{ $role->hasPermissionTo('create_purchases') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_purchases">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_purchases" name="permissions[]"
                                                               value="show_purchases" {{ $role->hasPermissionTo('show_purchases') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_purchases">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_purchases" name="permissions[]"
                                                               value="edit_purchases" {{ $role->hasPermissionTo('edit_purchases') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_purchases">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_purchases" name="permissions[]"
                                                               value="delete_purchases" {{ $role->hasPermissionTo('delete_purchases') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_purchases">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_purchase_payments" name="permissions[]"
                                                               value="access_purchase_payments" {{ $role->hasPermissionTo('access_purchase_payments') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_purchase_payments">Payments</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Purchases Returns Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Purchase Returns
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_purchase_returns" name="permissions[]"
                                                               value="access_purchase_returns" {{ $role->hasPermissionTo('access_purchase_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_purchase_returns">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_purchase_returns" name="permissions[]"
                                                               value="create_purchase_returns" {{ $role->hasPermissionTo('create_purchase_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_purchase_returns">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_purchase_returns" name="permissions[]"
                                                               value="show_purchase_returns" {{ $role->hasPermissionTo('show_purchase_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_purchase_returns">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_purchase_returns" name="permissions[]"
                                                               value="edit_purchase_returns" {{ $role->hasPermissionTo('edit_purchase_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_purchase_returns">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_purchase_returns" name="permissions[]"
                                                               value="delete_purchase_returns" {{ $role->hasPermissionTo('delete_purchase_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_purchase_returns">Hapus</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_purchase_return_payments" name="permissions[]"
                                                               value="access_purchase_return_payments" {{ $role->hasPermissionTo('access_purchase_return_payments') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_purchase_return_payments">Payments</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Currencies Permission -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Currencies
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_currencies" name="permissions[]"
                                                               value="access_currencies" {{ $role->hasPermissionTo('access_currencies') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_currencies">Hak Aksess</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_currencies" name="permissions[]"
                                                               value="create_currencies" {{ $role->hasPermissionTo('create_currencies') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_currencies">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_currencies" name="permissions[]"
                                                               value="edit_currencies" {{ $role->hasPermissionTo('edit_currencies') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_currencies">Ubah</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_currencies" name="permissions[]"
                                                               value="delete_currencies" {{ $role->hasPermissionTo('delete_currencies') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_currencies">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

                                <!-- Reports -->
                                <!-- <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Reports
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="access_reports" name="permissions[]"
                                                               value="access_reports" {{ $role->hasPermissionTo('access_reports') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_reports">Hak Aksess</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->


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
        $(document).ready(function () {
            $('#select-all').click(function () {
                var checked = this.checked;
                $('input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            })
            $('#select-all-dashboard').click(function () {
                var checked = this.checked;
                $('#dashboard input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-user-settings').click(function () {
                var checked = this.checked;
                $('#user-settings input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-sale').click(function () {
                var checked = this.checked;
                $('#sale input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-rsale').click(function () {
                var checked = this.checked;
                $('#rsale input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-purchase').click(function () {
                var checked = this.checked;
                $('#purchase input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-rpurchase').click(function () {
                var checked = this.checked;
                $('#rpurchase input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-cost').click(function () {
                var checked = this.checked;
                $('#cost input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-ijin').click(function () {
                var checked = this.checked;
                $('#ijin input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-tfstock').click(function () {
                var checked = this.checked;
                $('#tfstock input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-break').click(function () {
                var checked = this.checked;
                $('#break input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-pengaturan').click(function () {
                var checked = this.checked;
                $('#pengaturan input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-product').click(function () {
                var checked = this.checked;
                $('#product input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-adjustment').click(function () {
                var checked = this.checked;
                $('#adjustment input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-merek').click(function () {
                var checked = this.checked;
                $('#merek input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-lokasi').click(function () {
                var checked = this.checked;
                $('#lokasi input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });

            $('#select-all-pelanggan').click(function () {
                var checked = this.checked;
                $('#pelanggan input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-pemasok').click(function () {
                var checked = this.checked;
                $('#pemasok input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-tax').click(function() {
                var checked = this.checked;
                $('#tax input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-account').click(function () {
                var checked = this.checked;
                $('#account input[type="checkbox"]').each(function () {
                    this.checked = checked;
                });
            });
            $('#select-all-payment-term').click(function() {
                var checked = this.checked;
                $('#paymentTerm input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
            $('#select-all-payment-method').click(function() {
                var checked = this.checked;
                $('#payment-method input[type="checkbox"]').each(function() {
                    this.checked = checked;
                });
            });
        });
    </script>
@endpush

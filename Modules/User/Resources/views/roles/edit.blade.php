@extends('layouts.app')

@section('title', 'Edit Role')

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
                                    Hak Akses <span class="text-danger">*</span>
                                </label>
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
                                                        <label class="custom-control-label" for="users.access">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.create" name="permissions[]"
                                                               value="users.create" {{ $role->hasPermissionTo('users.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.create">Create</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.edit" name="permissions[]"
                                                               value="users.edit" {{ $role->hasPermissionTo('users.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.edit">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="users.delete" name="permissions[]"
                                                               value="users.delete" {{ $role->hasPermissionTo('location.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="users.delete">Delete</label>
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
                                                        <label class="custom-control-label" for="role.access">Akses</label>
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
                                                        <label class="custom-control-label" for="role.edit">Edit</label>
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
                                                               id="access_products" name="permissions[]"
                                                               value="access_products" {{ $role->hasPermissionTo('access_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_products">Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_products" name="permissions[]"
                                                               value="show_products" {{ $role->hasPermissionTo('show_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_products">Lihat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_products" name="permissions[]"
                                                               value="create_products" {{ $role->hasPermissionTo('create_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_products">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_products" name="permissions[]"
                                                               value="edit_products" {{ $role->hasPermissionTo('edit_products') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_products">Edit</label>
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
                                                               id="access_product_categories" name="permissions[]"
                                                               value="access_product_categories" {{ $role->hasPermissionTo('access_product_categories') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_product_categories">Daftar Kategori</label>
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
                                                        <label class="custom-control-label" for="view_access_table_product">Akses Tabel</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>



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
                                                        <label class="custom-control-label" for="adjustment.access">Akses</label>
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
                                                        <label class="custom-control-label" for="adjustment.edit">Edit</label>
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

                                            </div>
                                        </div>
                                    </div>
                                </div>


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
                                                               value="access_adjustments" {{ $role->hasPermissionTo('access_adjustments') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="access_adjustments">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_adjustments" name="permissions[]"
                                                               value="create_adjustments" {{ $role->hasPermissionTo('create_adjustments') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_adjustments">Create</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="show_adjustments" name="permissions[]"
                                                               value="show_adjustments" {{ $role->hasPermissionTo('show_adjustments') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="show_adjustments">View</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_adjustments" name="permissions[]"
                                                               value="edit_adjustments" {{ $role->hasPermissionTo('edit_adjustments') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_adjustments">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_adjustments" name="permissions[]"
                                                               value="delete_adjustments" {{ $role->hasPermissionTo('delete_adjustments') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_adjustments">Delete</label>
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
                                                        <label class="custom-control-label" for="access_quotations">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_quotations" name="permissions[]"
                                                               value="create_quotations" {{ $role->hasPermissionTo('create_quotations') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_quotations">Create</label>
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
                                                        <label class="custom-control-label" for="edit_quotations">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_quotations" name="permissions[]"
                                                               value="delete_quotations" {{ $role->hasPermissionTo('delete_quotations') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_quotations">Delete</label>
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
                                                        <label class="custom-control-label" for="access_expenses">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_expenses" name="permissions[]"
                                                               value="create_expenses" {{ $role->hasPermissionTo('create_expenses') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_expenses">Create</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_expenses" name="permissions[]"
                                                               value="edit_expenses" {{ $role->hasPermissionTo('edit_expenses') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_expenses">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_expenses" name="permissions[]"
                                                               value="delete_expenses" {{ $role->hasPermissionTo('delete_expenses') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_expenses">Delete</label>
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
                                                        <label class="custom-control-label" for="access_customers">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_customers" name="permissions[]"
                                                               value="create_customers" {{ $role->hasPermissionTo('create_customers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_customers">Create</label>
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
                                                        <label class="custom-control-label" for="edit_customers">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_customers" name="permissions[]"
                                                               value="delete_customers" {{ $role->hasPermissionTo('delete_customers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_customers">Delete</label>
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
                                                        <label class="custom-control-label" for="access_suppliers">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_suppliers" name="permissions[]"
                                                               value="create_suppliers" {{ $role->hasPermissionTo('create_suppliers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_suppliers">Create</label>
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
                                                        <label class="custom-control-label" for="edit_suppliers">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_customers" name="permissions[]"
                                                               value="delete_customers" {{ $role->hasPermissionTo('delete_customers') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_customers">Delete</label>
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
                                                        <label class="custom-control-label" for="access_sales">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_sales" name="permissions[]"
                                                               value="create_sales" {{ $role->hasPermissionTo('create_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_sales">Create</label>
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
                                                        <label class="custom-control-label" for="edit_sales">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_sales" name="permissions[]"
                                                               value="delete_sales" {{ $role->hasPermissionTo('delete_sales') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_sales">Delete</label>
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
                                                        <label class="custom-control-label" for="access_sale_returns">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_sale_returns" name="permissions[]"
                                                               value="create_sale_returns" {{ $role->hasPermissionTo('create_sale_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_sale_returns">Create</label>
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
                                                        <label class="custom-control-label" for="edit_sale_returns">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_sale_returns" name="permissions[]"
                                                               value="delete_sale_returns" {{ $role->hasPermissionTo('delete_sale_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_sale_returns">Delete</label>
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
                                                        <label class="custom-control-label" for="access_purchases">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_purchases" name="permissions[]"
                                                               value="create_purchases" {{ $role->hasPermissionTo('create_purchases') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_purchases">Create</label>
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
                                                        <label class="custom-control-label" for="edit_purchases">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_purchases" name="permissions[]"
                                                               value="delete_purchases" {{ $role->hasPermissionTo('delete_purchases') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_purchases">Delete</label>
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
                                                        <label class="custom-control-label" for="access_purchase_returns">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_purchase_returns" name="permissions[]"
                                                               value="create_purchase_returns" {{ $role->hasPermissionTo('create_purchase_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_purchase_returns">Create</label>
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
                                                        <label class="custom-control-label" for="edit_purchase_returns">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_purchase_returns" name="permissions[]"
                                                               value="delete_purchase_returns" {{ $role->hasPermissionTo('delete_purchase_returns') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_purchase_returns">Delete</label>
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
                                                        <label class="custom-control-label" for="access_currencies">Access</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="create_currencies" name="permissions[]"
                                                               value="create_currencies" {{ $role->hasPermissionTo('create_currencies') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="create_currencies">Create</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="edit_currencies" name="permissions[]"
                                                               value="edit_currencies" {{ $role->hasPermissionTo('edit_currencies') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="edit_currencies">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="delete_currencies" name="permissions[]"
                                                               value="delete_currencies" {{ $role->hasPermissionTo('delete_currencies') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="delete_currencies">Delete</label>
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
                                                        <label class="custom-control-label" for="access_reports">Access</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div> -->

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
                                                               for="access_settings">Akses</label>
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
                                                               for="crud_bussiness">Hak Akses Bisnis</label>
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
                                                        <label class="custom-control-label"
                                                               for="customer.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.create" name="permissions[]"
                                                               value="customer.access" {{ $role->hasPermissionTo('customer.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="customer.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.edit" name="permissions[]"
                                                               value="customer.edit" {{ $role->hasPermissionTo('customer.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="customer.edit">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="customer.delete" name="permissions[]"
                                                               value="customer.delete" {{ $role->hasPermissionTo('customer.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="customer.delete">Delete</label>
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
                                                        <label class="custom-control-label"
                                                               for="supplier.access">Hak Akses</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.create" name="permissions[]"
                                                               value="supplier.access" {{ $role->hasPermissionTo('supplier.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="supplier.create">Buat</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.edit" name="permissions[]"
                                                               value="supplier.edit" {{ $role->hasPermissionTo('supplier.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="supplier.edit">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="supplier.delete" name="permissions[]"
                                                               value="supplier.delete" {{ $role->hasPermissionTo('supplier.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label"
                                                               for="supplier.delete">Delete</label>
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
                                                        <label class="custom-control-label" for="brand.access">Akses</label>
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
                                                        <label class="custom-control-label" for="brand.edit">Edit</label>
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
                                                        <label class="custom-control-label" for="location.access">Akses</label>
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
                                                        <label class="custom-control-label" for="location.edit">Edit</label>
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

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pajak -->
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <div class="card h-100 border-0 shadow">
                                        <div class="card-header">
                                            Pajak
                                            <div class="custom-control custom-checkbox float-right">
                                                <input type="checkbox" class="custom-control-input" id="select-all-tax">
                                                <label class="custom-control-label" for="select-all-tax">Pilih Semua</label>
                                            </div>
                                        </div>
                                        <div id="tax" class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tax.access" name="permissions[]"
                                                               value="tax.access" {{ old('tax.access') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tax.access">Akses</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tax.create" name="permissions[]"
                                                               value="tax.create" {{ old('tax.create') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tax.create">Buat</label>
                                                    </div>
                                                </div>

                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tax.edit" name="permissions[]"
                                                               value="tax.edit" {{ old('tax.edit') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tax.edit">Edit</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="custom-control custom-switch">
                                                        <input type="checkbox" class="custom-control-input"
                                                               id="tax.delete" name="permissions[]"
                                                               value="tax.delete" {{ old('tax.delete') ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="tax.delete">Hapus</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
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
            $('#select-all-ijin').click(function () {
                var checked = this.checked;
                $('#ijin input[type="checkbox"]').each(function () {
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
        });
    </script>
@endpush

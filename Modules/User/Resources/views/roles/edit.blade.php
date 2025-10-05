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
        .custom-control-label { cursor: pointer; }
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
                    <div class="form-group mb-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Perbaharui Peran <i class="bi bi-check"></i></button>
                        <a href="{{ route('roles.index') }}" class="btn btn-secondary">Kembali</a>
                    </div>

                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="form-group mb-4">
                                <label for="name">Nama Peran <span class="text-danger">*</span></label>
                                <input class="form-control" type="text" name="name" required value="{{ old('name', $role->name) }}">
                            </div>

                            <hr>

                            <div class="form-group mb-2">
                                <label>Hak Akses <span class="text-danger">*</span></label>
                            </div>

                            <div class="form-group mb-3">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="select-all">
                                    <label class="custom-control-label" for="select-all">Beri Semua Hak Akses</label>
                                </div>
                            </div>

                            <div class="row gy-3">
                                @php
                                    $permissionGroups = [
                                        'Dashboard' => [
                                            'show_notifications' => 'Notifications',
                                        ],

                                        'Penjualan' => [
                                            'sales.access'   => 'Hak Akses',
                                            'sales.create'   => 'Buat',
                                            'sales.edit'     => 'Ubah',
                                            'sales.delete'   => 'Hapus',
                                            'sales.dispatch' => 'Kirim',
                                            'sales.show'     => 'Lihat',
                                            'sales.approval' => 'Persetujuan',
                                        ],

                                        'Retur Penjualan' => [
                                            'saleReturns.access' => 'Hak Akses',
                                            'saleReturns.create' => 'Buat',
                                            'saleReturns.edit'   => 'Ubah',
                                            'saleReturns.delete' => 'Hapus',
                                            'saleReturns.show'   => 'Lihat',
                                            'saleReturns.approve' => 'Persetujuan',
                                            'saleReturns.receive' => 'Penerimaan',
                                        ],

                                        'Pembayaran Penjualan' => [
                                            'salePayments.access' => 'Akses Pembayaran Penjualan',
                                            'salePayments.create' => 'Buat Pembayaran Penjualan',
                                            'salePayments.edit'   => 'Ubah Pembayaran Penjualan',
                                            'salePayments.delete' => 'Hapus Pembayaran Penjualan',
                                            'salePayments.show'   => 'Lihat Pembayaran Penjualan',
                                        ],

                                        'Pembayaran Retur Penjualan' => [
                                            'saleReturnPayments.access' => 'Akses Pembayaran Retur Penjualan',
                                            'saleReturnPayments.create' => 'Buat Pembayaran Retur Penjualan',
                                            'saleReturnPayments.edit'   => 'Ubah Pembayaran Retur Penjualan',
                                            'saleReturnPayments.delete' => 'Hapus Pembayaran Retur Penjualan',
                                        ],

                                        'Pembelian' => [
                                            'purchases.access'   => 'Hak Akses',
                                            'purchases.create'   => 'Buat',
                                            'purchases.edit'     => 'Ubah',
                                            'purchases.delete'   => 'Hapus',
                                            'purchases.show'     => 'Lihat',
                                            'purchases.receive'  => 'Penerimaan',
                                            'purchases.approval' => 'Persetujuan',
                                            'purchases.view'     => 'Lihat Detail',
                                        ],

                                        'Laporan Pembelian' => [
                                            'purchaseReports.access' => 'Akses Laporan Pembelian',
                                        ],

                                        'Pembayaran Pembelian' => [
                                            'purchasePayments.access' => 'Akses Pembayaran Pembelian',
                                            'purchasePayments.create' => 'Buat Pembayaran Pembelian',
                                            'purchasePayments.edit'   => 'Ubah Pembayaran Pembelian',
                                            'purchasePayments.delete' => 'Hapus Pembayaran Pembelian',
                                        ],

                                        'Retur Pembelian' => [
                                            'purchaseReturns.access' => 'Hak Akses',
                                            'purchaseReturns.create' => 'Buat',
                                            'purchaseReturns.edit'   => 'Ubah',
                                            'purchaseReturns.delete' => 'Hapus',
                                            'purchaseReturns.show'   => 'Lihat',
                                        ],

                                        'Pembayaran Retur Pembelian' => [
                                            'purchaseReturnPayments.access' => 'Akses Pembayaran Retur Pembelian',
                                            'purchaseReturnPayments.create' => 'Buat Pembayaran Retur Pembelian',
                                            'purchaseReturnPayments.edit'   => 'Ubah Pembayaran Retur Pembelian',
                                            'purchaseReturnPayments.delete' => 'Hapus Pembayaran Retur Pembelian',
                                            'purchaseReturnPayments.show'   => 'Lihat Pembayaran Retur Pembelian',
                                        ],

                                        'Penyesuaian Stok' => [
                                            'adjustments.access'               => 'Hak Akses',
                                            'adjustments.create'               => 'Buat',
                                            'adjustments.edit'                 => 'Ubah',
                                            'adjustments.delete'               => 'Hapus',
                                            'adjustments.show'                 => 'Lihat',
                                            'adjustments.approval'             => 'Persetujuan',
                                            'adjustments.breakage.create'      => 'Breakage Buat',
                                            'adjustments.breakage.edit'        => 'Breakage Ubah',
                                            'adjustments.breakage.approval'    => 'Breakage Persetujuan',
                                            'adjustments.reject'               => 'Tolak',
                                        ],

                                        'Transfer Stok' => [
                                            'stockTransfers.access'   => 'Hak Akses',
                                            'stockTransfers.create'   => 'Buat',
                                            'stockTransfers.edit'     => 'Ubah',
                                            'stockTransfers.delete'   => 'Hapus',
                                            'stockTransfers.show'     => 'Lihat',
                                            'stockTransfers.dispatch' => 'Kirim',
                                            'stockTransfers.receive'  => 'Terima',
                                            'stockTransfers.approval' => 'Persetujuan',
                                        ],

                                        'Produk' => [
                                            'products.access'           => 'Akses',
                                            'products.create'           => 'Buat',
                                            'products.edit'             => 'Ubah',
                                            'products.delete'           => 'Hapus',
                                            'products.show'             => 'Lihat',
                                            'products.bundle.access'    => 'Bundle Akses',
                                            'products.bundle.create'    => 'Bundle Buat',
                                            'products.bundle.edit'      => 'Bundle Ubah',
                                            'products.bundle.delete'    => 'Bundle Hapus',
                                            'barcodes.print'            => 'Print Barcodes',
                                        ],

                                        'Merek' => [
                                            'brands.access' => 'Hak Akses',
                                            'brands.create' => 'Buat',
                                            'brands.edit'   => 'Ubah',
                                            'brands.delete' => 'Hapus',
                                            'brands.view'   => 'Lihat',
                                        ],

                                        'Kategori' => [
                                            'categories.access' => 'Akses Kategori',
                                            'categories.create' => 'Buat Kategori',
                                            'categories.edit'   => 'Ubah Kategori',
                                            'categories.delete' => 'Hapus Kategori',
                                        ],

                                        'Pelanggan' => [
                                            'customers.access' => 'Hak Akses',
                                            'customers.create' => 'Buat',
                                            'customers.edit'   => 'Ubah',
                                            'customers.delete' => 'Hapus',
                                            'customers.show'   => 'Lihat',
                                        ],

                                        'Pemasok' => [
                                            'suppliers.access' => 'Hak Akses',
                                            'suppliers.create' => 'Buat',
                                            'suppliers.edit'   => 'Ubah',
                                            'suppliers.delete' => 'Hapus',
                                            'suppliers.show'   => 'Lihat',
                                        ],

                                        'Jurnal' => [
                                            'journals.access' => 'Akses Jurnal',
                                            'journals.create' => 'Buat Jurnal',
                                            'journals.edit'   => 'Ubah Jurnal',
                                            'journals.delete' => 'Hapus Jurnal',
                                            'journals.show'   => 'Lihat Jurnal',
                                        ],

                                        'POS' => [
                                            'pos.access' => 'Akses POS',
                                            'pos.create' => 'Buat POS',
                                        ],

                                        'Bisnis' => [
                                            'businesses.access' => 'Akses Bisnis',
                                            'businesses.create' => 'Buat Bisnis',
                                            'businesses.edit'   => 'Ubah Bisnis',
                                            'businesses.delete' => 'Hapus Bisnis',
                                            'businesses.show'   => 'Lihat Bisnis',
                                        ],

                                        'Pengaturan / Laporan' => [
                                            'settings.access' => 'Akses Pengaturan',
                                            'settings.edit'   => 'Ubah Pengaturan',
                                            'reports.access'  => 'Akses Laporan',
                                        ],

                                        'Mata Uang' => [
                                            'currencies.access' => 'Akses',
                                            'currencies.create' => 'Buat',
                                            'currencies.edit'   => 'Ubah',
                                            'currencies.delete' => 'Hapus',
                                        ],

                                        'Pajak' => [
                                            'taxes.access' => 'Akses Pajak',
                                            'taxes.create' => 'Buat Pajak',
                                            'taxes.edit'   => 'Ubah Pajak',
                                            'taxes.delete' => 'Hapus Pajak',
                                        ],

                                        'Unit' => [
                                            'units.access' => 'Akses Unit',
                                            'units.create' => 'Buat Unit',
                                            'units.edit'   => 'Ubah Unit',
                                            'units.delete' => 'Hapus Unit',
                                        ],

                                        'Kategori Pengeluaran' => [
                                            'expenseCategories.access' => 'Akses',
                                            'expenseCategories.create' => 'Buat',
                                            'expenseCategories.edit'   => 'Ubah',
                                            'expenseCategories.delete' => 'Hapus',
                                        ],

                                        'Pengeluaran' => [
                                            'expenses.access' => 'Akses',
                                            'expenses.create' => 'Buat',
                                            'expenses.edit'   => 'Ubah',
                                            'expenses.delete' => 'Hapus',
                                        ],

                                        'Lokasi' => [
                                            'locations.access' => 'Akses',
                                            'locations.create' => 'Buat',
                                            'locations.edit'   => 'Ubah',
                                        ],

                                        'Metode / Syarat Pembayaran' => [
                                            'paymentMethods.access' => 'Akses Metode Pembayaran',
                                            'paymentMethods.create' => 'Buat Metode Pembayaran',
                                            'paymentMethods.edit'   => 'Ubah Metode Pembayaran',
                                            'paymentMethods.delete' => 'Hapus Metode Pembayaran',
                                            'paymentTerms.access'   => 'Akses Syarat Pembayaran',
                                            'paymentTerms.create'   => 'Buat Syarat Pembayaran',
                                            'paymentTerms.edit'     => 'Ubah Syarat Pembayaran',
                                            'paymentTerms.delete'   => 'Hapus Syarat Pembayaran',
                                        ],

                                        'Profil' => [
                                            'profiles.edit' => 'Ubah Profil',
                                        ],

                                        'Mata Uang & Lainnya' => [
                                            'chartOfAccounts.access' => 'Akses COA',
                                            'chartOfAccounts.create' => 'Buat COA',
                                            'chartOfAccounts.edit'   => 'Ubah COA',
                                            'chartOfAccounts.delete' => 'Hapus COA',
                                            'chartOfAccounts.show'   => 'Lihat COA',
                                        ],
                                    ];
                                @endphp

                                @foreach($permissionGroups as $groupName => $perms)
                                    <div class="col-lg-4 col-md-6 mb-3">
                                        <div class="card h-100 border-0 shadow">
                                            <div class="card-header">
                                                {{ $groupName }}
                                                <div class="custom-control custom-checkbox float-right">
                                                    <input type="checkbox" class="custom-control-input group-toggle" id="select-all-{{ \Illuminate\Support\Str::slug($groupName) }}" data-target="{{ \Illuminate\Support\Str::slug($groupName) }}">
                                                    <label class="custom-control-label" for="select-all-{{ \Illuminate\Support\Str::slug($groupName) }}">Pilih Semua</label>
                                                </div>
                                            </div>
                                            <div id="{{ \Illuminate\Support\Str::slug($groupName) }}" class="card-body">
                                                @foreach ($perms as $perm => $label)
                                                    <div class="custom-control custom-switch mb-2">
                                                        @php
                                                            $inputId = str_replace(['.', '_'], '_', $perm);
                                                        @endphp
                                                        <input type="checkbox" class="custom-control-input group-member" id="{{ $inputId }}" name="permissions[]" value="{{ $perm }}" {{ $role->hasPermissionTo($perm) ? 'checked' : (in_array($perm, old('permissions', [])) ? 'checked' : '') }}>
                                                        <label class="custom-control-label" for="{{ $inputId }}">{{ $label }}</label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endforeach

                            </div> {{-- row --}}
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        function syncGroupToggle(groupToggle) {
            const target = groupToggle.dataset.target;
            const container = document.getElementById(target);
            if (!container) return;
            const checkboxes = container.querySelectorAll('input[name="permissions[]"]');
            checkboxes.forEach(cb => cb.checked = groupToggle.checked);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const selectAll = document.getElementById('select-all');
            selectAll?.addEventListener('change', function () {
                const all = document.querySelectorAll('input[name="permissions[]"]');
                all.forEach(i => i.checked = this.checked);
                document.querySelectorAll('.group-toggle').forEach(gt => gt.checked = this.checked);
            });

            document.querySelectorAll('.group-toggle').forEach(function (toggle) {
                toggle.addEventListener('change', function () {
                    syncGroupToggle(this);
                });
            });

            document.querySelectorAll('.card-body').forEach(function (container) {
                const groupToggleId = 'select-all-' + container.id;
                const groupToggle = document.getElementById(groupToggleId);
                if (!groupToggle) return;
                const members = container.querySelectorAll('input[name="permissions[]"]');
                members.forEach(function (member) {
                    member.addEventListener('change', function () {
                        const allChecked = Array.from(members).every(i => i.checked);
                        groupToggle.checked = allChecked;
                    });
                });
            });
        });
    </script>
@endpush

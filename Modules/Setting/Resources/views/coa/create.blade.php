@extends('layouts.app')

@section('title', 'Buat Akun Jurnal')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('chart-of-account.index') }}">Akun Jurnal</a></li>
        <li class="breadcrumb-item active">Add</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('chart-of-account.store') }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="name">Nama Akun <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="name" required>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="account_number">Nomor Akun <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="account_number" required>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="category">Kategori <span class="text-danger">*</span></label>
                                        <select class="form-control" name="category" required>
                                            <option value="Akun Piutang">Akun Piutang</option>
                                            <option value="Aktiva Lancar Lainnya">Aktiva Lancar Lainnya</option>
                                            <option value="Kas & Bank">Kas & Bank</option>
                                            <option value="Persediaan">Persediaan</option>
                                            <option value="Aktiva Tetap">Aktiva Tetap</option>
                                            <option value="Aktiva Lainnya">Aktiva Lainnya</option>
                                            <option value="Depresiasi & Amortisasi">Depresiasi & Amortisasi</option>
                                            <option value="Akun Hutang">Akun Hutang</option>
                                            <option value="Kartu Kredit">Kartu Kredit</option>
                                            <option value="Kewajiban Lancar Lainnya">Kewajiban Lancar Lainnya</option>
                                            <option value="Kewajiban Jangka Panjang">Kewajiban Jangka Panjang</option>
                                            <option value="Ekuitas">Ekuitas</option>
                                            <option value="Pendapatan">Pendapatan</option>
                                            <option value="Pendapatan Lainnya">Pendapatan Lainnya</option>
                                            <option value="Harga Pokok Penjualan">Harga Pokok Penjualan</option>
                                            <option value="Beban">Beban</option>
                                            <option value="Beban Lainnya">Beban Lainnya</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="parent_account_id">Akun Induk</label>
                                        <select class="form-control" name="parent_account_id">
                                            <option value="">Pilih Akun Induk</option>
                                            <!-- Add PHP logic to populate parent accounts -->
                                            @foreach($parent_accounts as $parent_account)
                                                <option value="{{ $parent_account->id }}">{{ $parent_account->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="tax_id">Pajak</label>
                                        <select class="form-control" name="tax_id">
                                            <option value="">Pilih Pajak</option>
                                            <!-- Add PHP logic to populate taxes -->
                                            @foreach($taxes as $tax)
                                                <option value="{{ $tax->id }}">{{ $tax->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="description">Deskripsi</label>
                                        <textarea class="form-control" name="description" rows="3"></textarea>
                                    </div>
                                </div>
                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('chart-of-account.index') }}" class="btn btn-secondary mr-2">
                                            Kembali
                                        </a>
                                        @can("create_account")
                                        <button class="btn btn-primary">Tambah Akun <i class="bi bi-check"></i></button>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

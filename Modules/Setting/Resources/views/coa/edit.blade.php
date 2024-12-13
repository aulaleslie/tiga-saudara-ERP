@extends('layouts.app')

@section('title', 'Edit Chart of Account')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('chart-of-account.index') }}">Chart of Accounts</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('chart-of-account.update', $chartOfAccount) }}" method="POST">
            @csrf
            @method('put')
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="name">Nama Akun <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="name" required value="{{ $chartOfAccount->name }}">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="account_number">Nomor Akun <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="account_number" required value="{{ $chartOfAccount->account_number }}">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="category">Kategori <span class="text-danger">*</span></label>
                                        <select class="form-control" name="category" required>
                                            <option value="Akun Piutang" {{ $chartOfAccount->category == 'Akun Piutang' ? 'selected' : '' }}>Akun Piutang</option>
                                            <option value="Aktiva Lancar Lainnya" {{ $chartOfAccount->category == 'Aktiva Lancar Lainnya' ? 'selected' : '' }}>Aktiva Lancar Lainnya</option>
                                            <option value="Kas & Bank" {{ $chartOfAccount->category == 'Kas & Bank' ? 'selected' : '' }}>Kas & Bank</option>
                                            <option value="Persediaan" {{ $chartOfAccount->category == 'Persediaan' ? 'selected' : '' }}>Persediaan</option>
                                            <option value="Aktiva Tetap" {{ $chartOfAccount->category == 'Aktiva Tetap' ? 'selected' : '' }}>Aktiva Tetap</option>
                                            <option value="Aktiva Lainnya" {{ $chartOfAccount->category == 'Aktiva Lainnya' ? 'selected' : '' }}>Aktiva Lainnya</option>
                                            <option value="Depresiasi & Amortisasi" {{ $chartOfAccount->category == 'Depresiasi & Amortisasi' ? 'selected' : '' }}>Depresiasi & Amortisasi</option>
                                            <option value="Akun Hutang" {{ $chartOfAccount->category == 'Akun Hutang' ? 'selected' : '' }}>Akun Hutang</option>
                                            <option value="Kartu Kredit" {{ $chartOfAccount->category == 'Kartu Kredit' ? 'selected' : '' }}>Kartu Kredit</option>
                                            <option value="Kewajiban Lancar Lainnya" {{ $chartOfAccount->category == 'Kewajiban Lancar Lainnya' ? 'selected' : '' }}>Kewajiban Lancar Lainnya</option>
                                            <option value="Kewajiban Jangka Panjang" {{ $chartOfAccount->category == 'Kewajiban Jangka Panjang' ? 'selected' : '' }}>Kewajiban Jangka Panjang</option>
                                            <option value="Ekuitas" {{ $chartOfAccount->category == 'Ekuitas' ? 'selected' : '' }}>Ekuitas</option>
                                            <option value="Pendapatan" {{ $chartOfAccount->category == 'Pendapatan' ? 'selected' : '' }}>Pendapatan</option>
                                            <option value="Pendapatan Lainnya" {{ $chartOfAccount->category == 'Pendapatan Lainnya' ? 'selected' : '' }}>Pendapatan Lainnya</option>
                                            <option value="Harga Pokok Penjualan" {{ $chartOfAccount->category == 'Harga Pokok Penjualan' ? 'selected' : '' }}>Harga Pokok Penjualan</option>
                                            <option value="Beban" {{ $chartOfAccount->category == 'Beban' ? 'selected' : '' }}>Beban</option>
                                            <option value="Beban Lainnya" {{ $chartOfAccount->category == 'Beban Lainnya' ? 'selected' : '' }}>Beban Lainnya</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="parent_account_id">Akun Induk</label>
                                        <select class="form-control" name="parent_account_id">
                                            <option value="">Pilih Akun Induk</option>
                                            @foreach($parent_accounts as $parent_account)
                                                <option value="{{ $parent_account->id }}" {{ $chartOfAccount->parent_account_id == $parent_account->id ? 'selected' : '' }}>{{ $parent_account->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="tax_id">Pajak</label>
                                        <select class="form-control" name="tax_id">
                                            <option value="">Pilih Pajak</option>
                                            @foreach($taxes as $tax)
                                                <option value="{{ $tax->id }}" {{ $chartOfAccount->tax_id == $tax->id ? 'selected' : '' }}>{{ $tax->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="description">Deskripsi</label>
                                        <textarea class="form-control" name="description" rows="3">{{ $chartOfAccount->description }}</textarea>
                                    </div>
                                </div>
                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('chart-of-account.index') }}" class="btn btn-secondary mr-2">Kembali</a>
                                        @can("edit_account")
                                        <button class="btn btn-primary">Update Akun <i class="bi bi-check"></i></button>
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

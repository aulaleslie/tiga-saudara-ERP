@extends('layouts.app')

@section('title', 'Edit Supplier')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('suppliers.index') }}">Pemasok</a></li>
        <li class="breadcrumb-item active">Ubah Pemasok</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('suppliers.update', $supplier->id) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="form-group">
                        <a href="{{ route('suppliers.index') }}" class="btn btn-secondary mr-2">
                            Kembali
                        </a>
                        <button class="btn btn-primary">Perbaharui Pemasok <i class="bi bi-check"></i></button>
                    </div>
                </div>

                <!-- Informasi Umum Section -->
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Informasi Umum</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nama Kontak" name="contact_name" value="{{ old('contact_name', $supplier->contact_name) }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="identity">Identitas</label>
                                        <select class="form-control" name="identity" id="identity">
                                            <option value="" {{ old('identity', $supplier->identity) == '' ? 'selected' : '' }}>-- Tidak ada Identitas --</option>
                                            <option value="KTP" {{ old('identity', $supplier->identity) == 'KTP' ? 'selected' : '' }}>KTP</option>
                                            <option value="SIM" {{ old('identity', $supplier->identity) == 'SIM' ? 'selected' : '' }}>SIM</option>
                                            <option value="Passport" {{ old('identity', $supplier->identity) == 'Passport' ? 'selected' : '' }}>Passport</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nomor Identitas" name="identity_number" value="{{ old('identity_number', $supplier->identity_number) }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Nama Perusahaan" name="supplier_name" value="{{ old('supplier_name', $supplier->supplier_name) }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nomor Kontak" name="supplier_phone" value="{{ old('supplier_phone', $supplier->supplier_phone) }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="NPWP" name="npwp" value="{{ old('npwp', $supplier->npwp) }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Alamat Penagihan" name="billing_address" value="{{ old('billing_address', $supplier->billing_address) }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Alamat Pengiriman" name="shipping_address" value="{{ old('shipping_address', $supplier->shipping_address) }}"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Bank Section -->
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Info Bank</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nama Bank" name="bank_name" value="{{ old('bank_name', $supplier->bank_name) }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Kantor Cabang Bank" name="bank_branch" value="{{ old('bank_branch', $supplier->bank_branch) }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nomor Rekening" name="account_number" value="{{ old('account_number', $supplier->account_number) }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Pemegang Akun Bank" name="account_holder" value="{{ old('account_holder', $supplier->account_holder) }}"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </form>
    </div>
@endsection

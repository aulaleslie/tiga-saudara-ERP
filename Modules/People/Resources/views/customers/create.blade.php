@extends('layouts.app')

@section('title', 'Create Customer')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('customers.index') }}">Pelanggan</a></li>
        <li class="breadcrumb-item active">Tambahkan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('customers.store') }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <a href="{{ route('customers.index') }}" class="btn btn-secondary mr-2">
                            Kembali
                        </a>
                        @canany('customer.create')
                        <button class="btn btn-primary">Tambahkan Pelanggan <i class="bi bi-check"></i></button>
                        @endcanany
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
                                    <div class="form-group">
                                        <label for="tier">Tier</label>
                                        <select class="form-control" name="tier" id="tier">
                                            <option value="" {{ old('tier') == '' ? 'selected' : '' }}>-- Pelanggan Normal --</option>
                                            <option value="WHOLESALER" {{ old('tier') == 'WHOLESALER' ? 'selected' : '' }}>Grosir</option>
                                            <option value="RESELLER" {{ old('tier') == 'RESELLER' ? 'selected' : '' }}>Reseller</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nama Kontak" name="contact_name" value="{{ old('contact_name') }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="identity">Identitas</label>
                                        <select class="form-control" name="identity" id="identity">
                                            <option value="" {{ old('identity') == '' ? 'selected' : '' }}>-- Tidak ada
                                                Identitas --
                                            </option>
                                            <option value="KTP" {{ old('identity') == 'KTP' ? 'selected' : '' }}>KTP
                                            </option>
                                            <option value="SIM" {{ old('identity') == 'SIM' ? 'selected' : '' }}>SIM
                                            </option>
                                            <option
                                                value="Passport" {{ old('identity') == 'Passport' ? 'selected' : '' }}>
                                                Passport
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nomor Identitas" name="identity_number"
                                             value="{{ old('identity_number') }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Nama Perusahaan" name="customer_name"
                                             value="{{ old('customer_name') }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nomor Handphone" name="customer_phone"
                                             value="{{ old('customer_phone') }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="E-Mail" name="customer_email" value="{{ old('customer_email') }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="NPWP" name="npwp" value="{{ old('npwp') }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Alamat Penagihan" name="billing_address"
                                             value="{{ old('billing_address') }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Alamat Pengiriman" name="shipping_address"
                                             value="{{ old('shipping_address') }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <label for="payment_term_id">Syarat Pembayaran</label>
                                    <select class="form-control" name="payment_term_id" id="payment_term_id">
                                        <option value="" {{ old('payment_term_id', $customer->payment_term_id ?? '') == '' ? 'selected' : '' }}>
                                            -- Pilih Syarat Pembayaran --
                                        </option>
                                        @foreach($paymentTerms as $paymentTerm)
                                            <option value="{{ $paymentTerm->id }}" {{ old('payment_term_id', $customer->payment_term_id ?? '') == $paymentTerm->id ? 'selected' : '' }}>
                                                {{ $paymentTerm->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="additional_info">Info Lainnya</label>
                                        <textarea class="form-control" name="additional_info"
                                                  placeholder="Masukkan info lainnya">{{ old('additional_info') }}</textarea>
                                    </div>
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
                                    <x-input label="Nama Bank" name="bank_name" value="{{ old('bank_name') }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Kantor Cabang Bank" name="bank_branch"
                                             value="{{ old('bank_branch') }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nomor Rekening" name="account_number"
                                             value="{{ old('account_number') }}"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Pemegang Akun Bank" name="account_holder"
                                             value="{{ old('account_holder') }}"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </form>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Buat Supplier')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('suppliers.index') }}">Pemasok</a></li>
        <li class="breadcrumb-item active">Tambahkan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form id="supplier-create-form" action="{{ route('suppliers.store') }}" method="POST">
            @csrf
            <input type="hidden" name="idempotency_token" value="{{ old('idempotency_token', $idempotencyToken) }}">
            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <a href="<?php echo e(route('suppliers.index')); ?>" class="btn btn-secondary mr-2">
                            Kembali
                        </a>
                        @can('suppliers.create')
                        <button type="submit" class="btn btn-primary">Tambahkan Pemasok <i class="bi bi-check"></i></button>
                        @endcan
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
                                    <x-input label="Nama Kontak" name="contact_name"/>
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
                                    <x-input label="Nomor Identitas" name="identity_number"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Nama Perusahaan" name="supplier_name"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nomor Kontak" name="supplier_phone"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="NPWP" name="npwp"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Alamat Penagihan" name="billing_address"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Alamat Pengiriman" name="shipping_address"/>
                                </div>
                                <div class="col-lg-6">
                                    <label for="payment_term_id">Syarat Pembayaran</label>
                                    <select class="form-control" name="payment_term_id" id="payment_term_id">
                                        <option value="" {{ old('payment_term_id', $supplier->payment_term_id ?? '') == '' ? 'selected' : '' }}>
                                            -- Pilih Syarat Pembayaran --
                                        </option>
                                        @foreach($paymentTerms as $paymentTerm)
                                            <option value="{{ $paymentTerm->id }}" {{ old('payment_term_id', $supplier->payment_term_id ?? '') == $paymentTerm->id ? 'selected' : '' }}>
                                                {{ $paymentTerm->name }}
                                            </option>
                                        @endforeach
                                    </select>
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
                                    <x-input label="Nama Bank" name="bank_name"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Kantor Cabang Bank" name="bank_branch"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <x-input label="Nomor Rekening" name="account_number"/>
                                </div>
                                <div class="col-lg-6">
                                    <x-input label="Pemegang Akun Bank" name="account_holder"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </form>
    </div>
@endsection

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('supplier-create-form');

            if (!form) {
                return;
            }

            const lockSubmitButtons = () => {
                const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');

                submitButtons.forEach((button) => {
                    if (button.dataset.locked === 'true') return;

                    button.dataset.locked = 'true';
                    button.disabled = true;

                    if (button.tagName.toLowerCase() === 'button') {
                        button.dataset.originalHtml = button.innerHTML;
                        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Memproses...';
                    } else {
                        button.dataset.originalValue = button.value;
                        button.value = 'Memproses...';
                    }
                });
            };

            form.addEventListener('submit', () => {
                lockSubmitButtons();
            }, { once: true });
        });
    </script>
@endpush

@php use Modules\Currency\Entities\Currency; @endphp
@extends('layouts.app')

@section('title', 'Ubah Pengaturan Perusahaan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Settings</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                @include('utils.alerts')
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Pengaturan Bisnis</h5>
                    </div>
                    <div class="card-body">
                        <form id="settings-update-form" action="{{ route('settings.update') }}" method="POST">
                            @csrf
                            @method('patch')
                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="company_name">Nama Perusahaan <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="company_name"
                                               value="{{ $settings->company_name }}" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="company_email">Email Perusahaan <span
                                                class="text-danger">*</span></label>
                                        <input type="email" class="form-control" name="company_email"
                                               value="{{ $settings->company_email }}" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="company_phone">Telepon Perusahaan <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="company_phone"
                                               value="{{ $settings->company_phone }}" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="document_prefix">Prefix Dokumen <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="document_prefix"
                                               value="{{ $settings->document_prefix }}" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="purchase_prefix_document">Prefix Dokumen Pembelian <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="purchase_prefix_document"
                                               value="{{ $settings->purchase_prefix_document }}" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="sale_prefix_document">Prefix Dokumen Penjualan <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="sale_prefix_document"
                                               value="{{ $settings->sale_prefix_document }}" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="purchase_return_prefix_document">Prefix Dokumen Retur Pembelian</label>
                                        <input type="text" class="form-control" name="purchase_return_prefix_document"
                                               value="{{ $settings->purchase_return_prefix_document }}">
                                        <small class="form-text text-muted">Contoh: PRRN</small>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="sale_return_prefix_document">Prefix Dokumen Retur Penjualan</label>
                                        <input type="text" class="form-control" name="sale_return_prefix_document"
                                               value="{{ $settings->sale_return_prefix_document }}">
                                        <small class="form-text text-muted">Contoh: SLRN</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="is_pkp">Status PKP</label>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="is_pkp" name="is_pkp" value="1"
                                                   {{ old('is_pkp', (bool) ($settings->is_pkp ?? false)) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="is_pkp">
                                                Bisnis ini PKP (pajak wajib dipilih saat membuat pembelian/penjualan)
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="pos_enabled">Aktifkan POS</label>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="pos_enabled" name="pos_enabled" value="1"
                                                   {{ old('pos_enabled', (bool) ($settings->pos_enabled ?? false)) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="pos_enabled">
                                                Izinkan akses modul POS untuk bisnis ini.
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="pos_walk_in_customer_id">Pelanggan Walk-In POS</label>
                                        <select class="form-control" id="pos_walk_in_customer_id" name="pos_walk_in_customer_id">
                                            <option value="">Belum diatur</option>
                                            @foreach($walkInCustomerOptions as $customerOption)
                                                @php
                                                    $displayName = $customerOption->contact_name
                                                        ? $customerOption->contact_name . ' - ' . $customerOption->customer_name
                                                        : $customerOption->customer_name;
                                                @endphp
                                                <option value="{{ $customerOption->id }}"
                                                    {{ (string) old('pos_walk_in_customer_id', $settings->pos_walk_in_customer_id) === (string) $customerOption->id ? 'selected' : '' }}>
                                                    {{ $displayName }}{{ $customerOption->customer_phone ? ' (' . $customerOption->customer_phone . ')' : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="form-text text-muted">
                                            Digunakan sebagai pelanggan default saat kasir tidak memilih pelanggan pada POS.
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-12">
                                    <div class="form-group">
                                        <label for="company_address">Alamat Perusahaan <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="company_address"
                                               value="{{ $settings->company_address }}">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <x-button label="Simpan Perubahan" icon="bi-check" processing-text="Memproses…" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script src="{{ asset('js/form-submission-lock.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            initFormSubmissionLock('settings-update-form', {
                errorEventName: 'settings:submit-error'
            });
        });
    </script>
@endpush

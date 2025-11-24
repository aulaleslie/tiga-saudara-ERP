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
                        <form action="{{ route('settings.update') }}" method="POST">
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
                            </div>

                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="pos_idle_threshold_minutes">Peringatan Waktu Idle (menit)</label>
                                        <input type="number" min="0" class="form-control" name="pos_idle_threshold_minutes"
                                               value="{{ old('pos_idle_threshold_minutes', $settings->pos_idle_threshold_minutes ?? 0) }}">
                                        <small class="text-muted">Atur ke 0 untuk menonaktifkan peringatan idle kasir.</small>
                                    </div>
                                </div>

                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="pos_default_cash_threshold">Ambang Kas Default POS</label>
                                        <input type="number" step="0.01" min="0" class="form-control" name="pos_default_cash_threshold"
                                               value="{{ old('pos_default_cash_threshold', $settings->pos_default_cash_threshold ?? 0) }}">
                                        <small class="text-muted">Dipakai saat lokasi belum memiliki ambang kas khusus.</small>
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
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check"></i> Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

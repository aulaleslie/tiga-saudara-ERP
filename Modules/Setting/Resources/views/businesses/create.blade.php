@php use Modules\Currency\Entities\Currency; @endphp
@extends('layouts.app')

@section('title', 'Edit Settings')

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
                        <h5 class="mb-0">Tambah Bisnis</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('businesses.store') }}" method="POST">
                            @csrf
                            @method('post')
                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="company_name">Nama Bisnis <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="company_name" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="company_email">Email Bisnis <span
                                                class="text-danger">*</span></label>
                                        <input type="email" class="form-control" name="company_email" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="company_phone">Telepon Bisnis <span
                                                class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="company_phone" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="document_prefix">Prefix Dokumen <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="document_prefix" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="purchase_prefix_document">Prefix Dokumen Pembelian <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="purchase_prefix_document" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="sale_prefix_document">Prefix Dokumen Penjualan <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="sale_prefix_document" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-12">
                                    <div class="form-group">
                                        <label for="company_address">Alamat <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="company_address">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <a href="{{ route('businesses.index') }}" class="btn btn-secondary mr-2">
                                    Kembali
                                </a>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check"></i> Buat</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection


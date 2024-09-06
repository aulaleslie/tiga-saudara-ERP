@extends('layouts.app')

@section('title', 'Create Supplier')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('suppliers.index') }}">Suppliers</a></li>
        <li class="breadcrumb-item active">Add</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('suppliers.store') }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="form-group">
                        <a href="<?php echo e(route('suppliers.index')); ?>" class="btn btn-secondary mr-2">
                            Kembali
                        </a>
                        <button class="btn btn-primary">Tambahkan Pemasok <i class="bi bi-check"></i></button>
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
                                        <label for="contact_name">Nama Kontak</label>
                                        <input type="text" class="form-control" name="contact_name">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="identity">Identitas</label>
                                        <input type="text" class="form-control" name="identity">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="identity_number">Nomor Identitas</label>
                                        <input type="text" class="form-control" name="identity_number">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="company_name">Nama Perusahaan</label>
                                        <input type="text" class="form-control" name="company_name">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="phone">Nomor Handphone</label>
                                        <input type="text" class="form-control" name="phone">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="telephone">Nomor Telepon</label>
                                        <input type="text" class="form-control" name="telephone">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="fax">Fax</label>
                                        <input type="text" class="form-control" name="fax">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="npwp">NPWP</label>
                                        <input type="text" class="form-control" name="npwp">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="billing_address">Alamat Penagihan</label>
                                        <input type="text" class="form-control" name="billing_address">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="shipping_address">Alamat Pengiriman</label>
                                        <input type="text" class="form-control" name="shipping_address">
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
                                    <div class="form-group">
                                        <label for="bank_name">Nama Bank</label>
                                        <input type="text" class="form-control" name="bank_name">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="bank_branch">Kantor Cabang Bank</label>
                                        <input type="text" class="form-control" name="bank_branch">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="account_number">Nomor Rekening</label>
                                        <input type="text" class="form-control" name="account_number">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="account_holder">Pemegang Akun Bank</label>
                                        <input type="text" class="form-control" name="account_holder">
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

@extends('layouts.app')

@section('title', 'Create Customer')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('customers.index') }}">Customers</a></li>
        <li class="breadcrumb-item active">Add</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('customers.store') }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="form-group">
                        <a href="{{ route('customers.index') }}" class="btn btn-secondary mr-2">
                            Kembali
                        </a>
                        <button class="btn btn-primary">Tambahkan Pelanggan <i class="bi bi-check"></i></button>
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
                                        <input type="text" class="form-control" name="contact_name" placeholder="Masukkan nama kontak">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="identity">Identitas</label>
                                        <select class="form-control" name="identity" id="identity">
                                            <option value="KTP">KTP</option>
                                            <option value="SIM">SIM</option>
                                            <option value="Passport">Passport</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="identity_number">Nomor Identitas</label>
                                        <input type="text" class="form-control" name="identity_number" placeholder="Masukkan nomor identitas">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="customer_name">Nama Perusahaan</label>
                                        <input type="text" class="form-control" name="customer_name" required placeholder="Masukkan nama perusahaan">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="customer_phone">Nomor Handphone</label>
                                        <input type="text" class="form-control" name="customer_phone" required placeholder="Masukkan nomor handphone">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="telephone">Nomor Telepon</label>
                                        <input type="text" class="form-control" name="telephone" placeholder="Masukkan nomor telepon">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="fax">E-Mail</label>
                                        <input type="text" class="form-control" name="fax" placeholder="Masukkan E-Mail">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="fax">Fax</label>
                                        <input type="text" class="form-control" name="fax" placeholder="Masukkan nomor fax">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="npwp">NPWP</label>
                                        <input type="text" class="form-control" name="npwp" placeholder="Masukkan NPWP">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="billing_address">Alamat Penagihan</label>
                                        <input type="text" class="form-control" name="billing_address" placeholder="Masukkan alamat penagihan">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="shipping_address">Alamat Pengiriman</label>
                                        <input type="text" class="form-control" name="shipping_address" placeholder="Masukkan alamat pengiriman">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="additional_info">Info Lainnya</label>
                                        <textarea class="form-control" name="additional_info" placeholder="Masukkan info lainnya"></textarea>
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
                                        <input type="text" class="form-control" name="bank_name" placeholder="Masukkan nama bank">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="bank_branch">Kantor Cabang Bank</label>
                                        <input type="text" class="form-control" name="bank_branch" placeholder="Masukkan kantor cabang bank">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="account_number">Nomor Rekening</label>
                                        <input type="text" class="form-control" name="account_number" placeholder="Masukkan nomor rekening">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="account_holder">Pemegang Akun Bank</label>
                                        <input type="text" class="form-control" name="account_holder" placeholder="Masukkan pemegang akun bank">
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

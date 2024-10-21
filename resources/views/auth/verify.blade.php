@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8" style="margin-top: 2%">
                <div class="card" style="width: 40rem;">
                    <div class="card-body">
                        <h4 class="card-title">Verifikasi Alamat Email Anda</h4>
                        @if (session('resent'))
                            <p class="alert alert-success" role="alert">Tautan verifikasi baru telah dikirim ke alamat email Anda</p>
                        @endif
                        <p class="card-text">Sebelum melanjutkan, silakan periksa email Anda untuk tautan verifikasi. Jika Anda tidak menerima email,</p>
                        <a href="{{ route('verification.resend') }}">klik di sini untuk meminta yang lain</a>.
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@extends('layouts.pos')

@section('title', 'Penyetoran Kas')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')
            </div>
            <div class="col-12 mb-3">
                <div class="card shadow-sm">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="mb-0">Kelola Sesi POS</h6>
                            <small class="text-muted">Jeda, lanjutkan, atau tutup sesi kasir Anda</small>
                        </div>
                        <a href="{{ route('app.pos.session') }}" class="btn btn-outline-primary">
                            <i class="bi bi-gear mr-1"></i> Status Sesi POS
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-12">
                @include('sale::pos.partials.cash-navigation')
            </div>
            <div class="col-xl-6 col-lg-8 mx-auto">
                <livewire:pos.cash-settlement />
            </div>
        </div>
    </div>
@endsection

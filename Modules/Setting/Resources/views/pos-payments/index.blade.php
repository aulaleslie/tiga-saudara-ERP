@extends('layouts.app')

@section('title', 'Konfigurasi Pembayaran POS')

@section('content')
    <div class="container">
        @php($canEdit = auth()->user()?->can('paymentMethods.edit'))
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Konfigurasi Pembayaran POS</span>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary text-white">{{ $setting->company_name }}</span>
                            @if($canEdit)
                                <div class="btn-group">
                                    <form action="{{ route('pos-payment-configurations.bulkEnable') }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">Enable All</button>
                                    </form>
                                    <form action="{{ route('pos-payment-configurations.bulkDisable') }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Disable All</button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0 table-striped">
                                <thead>
                                    <tr>
                                        <th>Metode Pembayaran</th>
                                        <th>Akun (COA)</th>
                                        <th class="text-center">Tipe</th>
                                        <th class="text-center">Referensi</th>
                                        <th class="text-center">Status</th>
                                        @if($canEdit)
                                            <th class="text-end">Aksi</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($paymentMethods as $paymentMethod)
                                        <tr>
                                            <td>{{ $paymentMethod->name }}</td>
                                            <td>
                                                @if($paymentMethod->chartOfAccount)
                                                    <span class="small">{{ $paymentMethod->chartOfAccount->code }} - {{ $paymentMethod->chartOfAccount->name }}</span>
                                                @else
                                                    <span class="text-muted small">N/A</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                @if($paymentMethod->is_cash)
                                                    <span class="badge bg-info text-white">Tunai</span>
                                                @else
                                                    <span class="badge bg-secondary">Non-Tunai</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                @if($paymentMethod->requires_reference)
                                                    <span class="badge bg-warning text-dark">Wajib</span>
                                                @else
                                                    <span class="badge bg-light text-dark">Opsional</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                @if($paymentMethod->is_enabled)
                                                    <span class="badge bg-primary">Enabled</span>
                                                @else
                                                    <span class="badge bg-secondary">Disabled</span>
                                                @endif
                                            </td>
                                            @if($canEdit)
                                                <td class="text-end">
                                                    <form action="{{ route('pos-payment-configurations.toggle', $paymentMethod->id) }}" method="POST">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="is_enabled" value="{{ $paymentMethod->is_enabled ? '0' : '1' }}">
                                                        <button type="submit" class="btn btn-sm {{ $paymentMethod->is_enabled ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                                            {{ $paymentMethod->is_enabled ? 'Disable' : 'Enable' }}
                                                        </button>
                                                    </form>
                                                </td>
                                            @endif
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ $canEdit ? 6 : 5 }}" class="text-center py-4">
                                                Belum ada metode pembayaran yang tersedia.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

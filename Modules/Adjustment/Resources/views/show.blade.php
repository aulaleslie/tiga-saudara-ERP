@extends('layouts.app')

@php
    $isNormalVersioned = $isNormalVersioned ?? false;
    $isBreakageDetail = $isBreakageDetail ?? false;
@endphp

@section('title', $isNormalVersioned ? 'Rincian Stock Opname' : ($isBreakageDetail ? 'Rincian Barang Rusak' : 'Adjustment Details'))

@push('page_css')
    @livewireStyles
@endpush

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('adjustments.index') }}">Penyesuaian</a></li>
        <li class="breadcrumb-item active">Rincian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        @if($isNormalVersioned)
            @include('adjustment::partials.stock-opname-show')
        @elseif($isBreakageDetail)
            @include('adjustment::partials.breakage-show')
        @else
        <div class="row mb-3">
            <div class="col-12">
                <a href="{{ route('adjustments.index') }}" class="btn btn-secondary">
                    Kembali
                </a>

                @php
                    $user = auth()->user();
                    $canApproveAny = $user?->can('adjustments.approval');
                    $canApproveBreakage = $user?->can('adjustments.breakage.approval');
                    $canRejectGeneric = $user?->can('adjustments.reject');
                    $canEdit = $user?->can('adjustments.edit');

                    // Normalisasi
                    $statusValue = $adjustment->status instanceof \Modules\Adjustment\Entities\AdjustmentStatus
                        ? $adjustment->status->value
                        : $adjustment->status;
                    $status      = is_string($statusValue) ? strtolower(trim($statusValue)) : $statusValue;
                    $type        = is_string($adjustment->type)   ? strtolower(trim($adjustment->type))   : $adjustment->type;
                    $isBreakage  = $type === 'breakage';

                    // Legacy/breakage lifecycle: single 'pending' status gate.
                    $isPending = $status === 'pending';

                    $showSubmit  = false;
                    $showApprove = $isPending && ($isBreakage ? ($canApproveBreakage || $canApproveAny) : $canApproveAny);
                    $showReject  = $isPending && ($isBreakage ? ($canApproveBreakage || $canApproveAny || $canRejectGeneric) : ($canApproveAny || $canRejectGeneric));
                @endphp

                @if($showApprove)
                    <form action="{{ route('adjustments.approve', $adjustment) }}" method="POST" class="d-inline">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-success">Setuju</button>
                    </form>
                @endif

                @if($showReject)
                    <form action="{{ route('adjustments.reject', $adjustment) }}" method="POST" class="d-inline">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-danger">Tolak</button>
                    </form>
                @endif
            </div>
        </div>

        <!-- Header Table -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <tr>
                                    <th>Tanggal</th>
                                    <td>{{ $adjustment->date }}</td>
                                    <th>Reference</th>
                                    <td>{{ $adjustment->reference }}</td>
                                </tr>
                                <tr>
                                    <th>Lokasi</th>
                                    <td>{{ $adjustment->location->name ?? '-' }}</td>
                                    <th>Jenis Penyesuaian</th>
                                    <td>{{ strtoupper($adjustment->type) }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details Table -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Rincian Perhitungan Fisik (Stock Opname)</h5>
                    </div>
                    <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="thead-light">
                                    <tr>
                                        <th>Nama Produk</th>
                                        <th>Kode Produk</th>
                                        @can('adjustments.view-system-stock')
                                            <th>Stok</th>
                                        @endcan
                                        <th>Kuantitas Terhitung</th>
                                        <th>Serial Numbers</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($adjustment->adjustedProducts as $adjustedProduct)
                                        <tr>
                                            <td>{{ $adjustedProduct->product->product_name }}</td>
                                            <td>{{ $adjustedProduct->product->product_code }}</td>
                                            @can('adjustments.view-system-stock')
                                                <td class="text-center">
                                                    <span class="badge badge-info">
                                                        {{ $adjustedProduct->stock_info['quantity'] }} {{ $adjustedProduct->stock_info['unit'] }}
                                                    </span>
                                                    <span class="d-inline-block"
                                                          data-toggle="tooltip"
                                                          data-placement="top"
                                                          title="Stok Pajak: {{ $adjustedProduct->stock_info['quantity_tax'] }} {{ $adjustedProduct->stock_info['unit'] }} | Stok Non-Pajak: {{ $adjustedProduct->stock_info['quantity_non_tax'] }} {{ $adjustedProduct->stock_info['unit'] }} | Rusak Pajak: {{ $adjustedProduct->stock_info['broken_quantity_tax'] }} {{ $adjustedProduct->stock_info['unit'] }} | Rusak Non-Pajak: {{ $adjustedProduct->stock_info['broken_quantity_non_tax'] }} {{ $adjustedProduct->stock_info['unit'] }}">
                                                        <i class="bi bi-info-circle text-primary" style="cursor: pointer;"></i>
                                                    </span>
                                                </td>
                                            @endcan
                                            <td class="text-center">{{ $adjustedProduct->quantity }}</td>
                                            <td>
                                                @if(!empty($adjustedProduct->serialNumbers))
                                                    <ol class="mb-0 ps-3">
                                                        @foreach($adjustedProduct->serialNumbers as $serial)
                                                            <li>{{ $serial['serial_number'] }} - {{ $serial['tax_label'] }}</li>
                                                        @endforeach
                                                    </ol>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>
@endsection

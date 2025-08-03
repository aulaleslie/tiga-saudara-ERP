@extends('layouts.app')

@section('title', 'Adjustment Details')

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
        <div class="row mb-3">
            <div class="col-12">
                <a href="{{ route('adjustments.index') }}" class="btn btn-secondary">
                    Kembali
                </a>

                @if($adjustment->status === 'pending')
                    @can('adjustments.approval')
                        <form action="{{ route('adjustments.approve', $adjustment) }}" method="POST"
                              class="d-inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-success">
                                Setuju
                            </button>
                        </form>
                    @endcan

                    @can('adjustments.reject')
                        <form action="{{ route('adjustments.reject', $adjustment) }}" method="POST"
                              class="d-inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-danger">
                                Tolak
                            </button>
                        </form>
                    @endcan
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
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                <tr>
                                    <th>Nama Produk</th>
                                    <th>Kode Produk</th>
                                    <th>Stok</th>
                                    <th>Kuantitas Terhitung</th>
                                    <th>Serial Numbers</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($adjustment->adjustedProducts as $adjustedProduct)
                                    <tr>
                                        <td>{{ $adjustedProduct->product->product_name }}</td>
                                        <td>{{ $adjustedProduct->product->product_code }}</td>
                                        <td class="text-center">
                                            <span class="badge badge-info">
                                                {{ $adjustedProduct->stock_info['quantity'] }} {{ $adjustedProduct->stock_info['unit'] }}
                                            </span>
                                            <span class="d-inline-block"
                                                  data-bs-toggle="tooltip"
                                                  data-bs-placement="top"
                                                  title="Stok Pajak: {{ $adjustedProduct->stock_info['quantity_tax'] }} {{ $adjustedProduct->stock_info['unit'] }} | Stok Non-Pajak: {{ $adjustedProduct->stock_info['quantity_non_tax'] }} {{ $adjustedProduct->stock_info['unit'] }} | Rusak Pajak: {{ $adjustedProduct->stock_info['broken_quantity_tax'] }} {{ $adjustedProduct->stock_info['unit'] }} | Rusak Non-Pajak: {{ $adjustedProduct->stock_info['broken_quantity_non_tax'] }} {{ $adjustedProduct->stock_info['unit'] }}">
                                                <i class="bi bi-info-circle text-primary" style="cursor: pointer;"></i>
                                            </span>
                                        </td>
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
    </div>
@endsection

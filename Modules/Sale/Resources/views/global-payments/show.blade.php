@php use Carbon\Carbon; @endphp
@extends('layouts.app')

@section('title', 'Rincian Penjualan - Pembayaran Global')

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center">
                <div>
                    Referensi: <strong>{{ $sale->reference ?? 'N/A' }}</strong>
                </div>

                @if($sale->live_due_amount > 0 && auth()->user()->can('salePayments.create'))
                    <a href="{{ route('sales.global-payments.create', $sale->id) }}"
                       class="btn btn-sm btn-primary mfs-auto mfe-1 d-print-none">
                        <i class="bi bi-cash-coin"></i> Buat Pembayaran
                    </a>
                @endif

                <a class="btn btn-sm btn-info mfs-auto mfe-1 d-print-none" href="{{ route('sales.global-payments.index') }}">
                    <i class="bi bi-back"></i> Kembali
                </a>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <!-- Informasi Bisnis -->
                    <div class="col-sm-4 mb-3 mb-md-0">
                        <h5 class="mb-2 border-bottom pb-2">Informasi Bisnis:</h5>
                        <div><strong>{{ $setting->company_name ?? 'N/A' }}</strong></div>
                        <div>{{ $setting->company_address ?? 'N/A' }}</div>
                        <div>Email: {{ $setting->company_email ?? 'N/A' }}</div>
                        <div>Kontak: {{ $setting->company_phone ?? 'N/A' }}</div>
                    </div>
                    <!-- Informasi Pelanggan -->
                    <div class="col-sm-4 mb-3 mb-md-0">
                        <h5 class="mb-2 border-bottom pb-2">Informasi Pelanggan:</h5>
                        <div><strong>{{ $sale->customer->customer_name ?? 'N/A' }}</strong></div>
                    </div>
                    <!-- Info Faktur -->
                    <div class="col-sm-4 mb-3 mb-md-0">
                        <h5 class="mb-2 border-bottom pb-2">Info Faktur:</h5>
                        <div>Faktur: <strong>INV/{{ $sale->reference }}</strong></div>
                        <div>Tanggal: {{ Carbon::parse($sale->date)->format('d M, Y') }}</div>
                        <div class="mt-2">
                            <div>Tags:</div>
                            <div>
                                @forelse ($sale->tags as $tag)
                                    <span class="badge badge-secondary">
                                        {{ is_array($tag->name) ? ($tag->name['en'] ?? reset($tag->name)) : $tag->name }}
                                    </span>
                                @empty
                                    <span class="text-muted">-</span>
                                @endforelse
                            </div>
                        </div>
                        <div>Status: <strong>{{ $sale->status }}</strong></div>
                        <div>Status Pembayaran: <strong>{{ \App\Constants\PaymentStatus::label($sale->payment_status) }}</strong></div>
                    </div>
                </div>

                @php
                    $standaloneBundles = $sale->bundleItems->filter(fn($item) => is_null($item->sale_detail_id));
                    $totalSaleTax = $sale->saleDetails->sum('product_tax_amount') + $standaloneBundles->sum('tax_amount');
                    $totalSubTotal = $sale->saleDetails->sum('sub_total') + $standaloneBundles->sum('sub_total');
                @endphp

                <!-- Detail Penjualan -->
                <div class="table-responsive-sm">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th class="align-middle" style="width: 15%;">Produk</th>
                            <th class="align-middle">Harga Satuan</th>
                            <th class="align-middle">Kuantitas</th>
                            <th class="align-middle">Diskon</th>
                            @if($totalSaleTax > 0)
                                <th class="align-middle">DPP</th>
                                <th class="align-middle">Pajak %</th>
                            @endif
                            <th class="align-middle">Jumlah Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($sale->saleDetails as $detail)
                            <tr>
                                <td class="align-middle">
                                    {{ $detail->product_name }} <br>
                                    <span class="badge bg-success">{{ $detail->product_code }}</span>
                                </td>
                                <td class="align-middle">{{ format_currency($detail->price) }}</td>
                                <td class="align-middle">{{ $detail->quantity }}</td>
                                <td class="align-middle">{{ format_currency($detail->product_discount_amount) }}</td>
                                @if($totalSaleTax > 0)
                                    <td class="align-middle">
                                        {{ format_currency($detail->sub_total - $detail->product_tax_amount - $detail->product_discount_amount) }}
                                    </td>
                                    <td class="align-middle">
                                        {{ $detail->tax ? $detail->tax->value . '%' : '-' }}
                                    </td>
                                @endif
                                <td class="align-middle">{{ format_currency($detail->sub_total) }}</td>
                            </tr>

                            {{-- Tampilkan bundle items jika ada --}}
                            @if($detail->bundleItems->isNotEmpty())
                                <tr>
                                    <td colspan="{{ $totalSaleTax > 0 ? 7 : 5 }}">
                                        <div class="ms-4">
                                            <strong>Item Bundel:</strong>
                                            <table class="table table-sm table-bordered mt-2">
                                                <thead>
                                                <tr>
                                                    <th>Nama Bundel</th>
                                                    <th>Harga</th>
                                                    <th>Kuantitas</th>
                                                    <th>Jumlah</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($detail->bundleItems as $bundle)
                                                    <tr>
                                                        <td>{{ $bundle->name }}</td>
                                                        <td>{{ ($bundle->price > 0) ? format_currency($bundle->price) : '-' }}</td>
                                                        <td>{{ $bundle->quantity }}</td>
                                                        <td>{{ ($bundle->sub_total > 0) ? format_currency($bundle->sub_total) : '-' }}</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach

                        {{-- Tampilkan standalone bundle items jika ada --}}
                        @php
                            $standaloneBundles = $sale->bundleItems->filter(fn($item) => is_null($item->sale_detail_id));
                        @endphp
                        @if($standaloneBundles->isNotEmpty())
                            <tr class="table-info">
                                <td colspan="{{ $totalSaleTax > 0 ? 7 : 5 }}">
                                    <strong>Item Layanan / Bundel Terpisah:</strong>
                                </td>
                            </tr>
                            @foreach($standaloneBundles as $bundle)
                                <tr>
                                    <td class="align-middle">
                                        {{ $bundle->name }} <br>
                                        <span class="badge bg-success">{{ $bundle->product->product_code ?? 'N/A' }}</span>
                                    </td>
                                    <td class="align-middle">{{ format_currency($bundle->price) }}</td>
                                    <td class="align-middle">{{ $bundle->quantity }}</td>
                                    <td class="align-middle">-</td>
                                    @if($totalSaleTax > 0)
                                        <td class="align-middle">
                                            {{ format_currency($bundle->sub_total - $bundle->tax_amount) }}
                                        </td>
                                        <td class="align-middle">
                                            {{ $bundle->tax ? $bundle->tax->value . '%' : '-' }}
                                        </td>
                                    @endif
                                    <td class="align-middle">{{ format_currency($bundle->sub_total) }}</td>
                                </tr>
                            @endforeach
                        @endif
                        </tbody>
                    </table>
                </div>

                <!-- Ringkasan Total & Pembayaran -->
                <div class="row">
                    <div class="col-lg-4 col-sm-5 ml-md-auto">
                        @php
                            $dppAmount = $totalSubTotal - $totalSaleTax - $sale->discount_amount;
                        @endphp
                        <table class="table">
                            <tbody>
                            @if($totalSaleTax > 0)
                                <tr>
                                    <td class="left"><strong>DPP (Dasar Pengenaan Pajak)</strong></td>
                                    <td class="right">{{ format_currency($dppAmount) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="left"><strong>Diskon ({{ $sale->discount_percentage }}%)</strong></td>
                                <td class="right">{{ format_currency($sale->discount_amount) }}</td>
                            </tr>
                            @if($totalSaleTax > 0)
                                <tr>
                                    <td class="left"><strong>Pajak</strong></td>
                                    <td class="right">{{ format_currency($totalSaleTax) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="left"><strong>Pengiriman</strong></td>
                                <td class="right">{{ format_currency($sale->shipping_amount) }}</td>
                            </tr>
                            <tr>
                                <td class="left"><strong>Total Keseluruhan</strong></td>
                                <td class="right"><strong>{{ format_currency($sale->total_amount) }}</strong></td>
                            </tr>
                            <tr class="table-info">
                                <td class="left"><strong>Jumlah Dibayar</strong></td>
                                <td class="right"><strong>{{ format_currency($sale->paid_amount) }}</strong></td>
                            </tr>
                            <tr class="table-warning">
                                <td class="left"><strong>Saldo Tagihan</strong></td>
                                <td class="right"><strong>{{ format_currency($sale->live_due_amount) }}</strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Catatan -->
                <div class="row mt-4">
                    <div class="col-sm-12">
                        <h5 class="mb-2 border-bottom pb-2">Catatan:</h5>
                        <p style="white-space: pre-wrap;">{{ $sale->note ?? 'Tidak ada catatan.' }}</p>
                    </div>
                </div>
            </div>

            <!-- Dispatch Details -->
            @if($sale->saleDispatches->isNotEmpty())
                <div class="row mt-4">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="mb-3">Pengeluaran Barang</h4>
                                <div class="mb-3">
                                    <span class="badge bg-info me-1">Biru</span>
                                    <small class="me-3">Serial masih aktif pada penjualan ini</small>
                                    <span class="badge bg-danger me-1">Merah</span>
                                    <small class="me-3">Serial sudah diretur dari penjualan ini</small>
                                    <span class="badge bg-secondary me-1">Abu-abu</span>
                                    <small>Status pengiriman belum final / serial tidak ditemukan</small>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-striped table-bordered">
                                        <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Total Dikirim</th>
                                            <th>Status</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($sale->saleDispatches as $dispatch)
                                            @php $sumQty = $dispatch->details->sum('dispatched_quantity'); @endphp
                                            <tr>
                                                <td>{{ \Carbon\Carbon::parse($dispatch->dispatch_date)->format('Y-m-d') }}</td>
                                                <td>{{ $sumQty }}</td>
                                                <td>
                                                    @if($dispatch->isPending())
                                                        <span class="badge badge-warning">Menunggu Persetujuan</span>
                                                    @elseif($dispatch->isApproved())
                                                        <span class="badge badge-success">Disetujui</span>
                                                    @elseif($dispatch->isRejected())
                                                        <span class="badge badge-danger">Ditolak</span>
                                                        @if($dispatch->rejection_reason)
                                                            <i class="bi bi-info-circle text-danger" title="{{ $dispatch->rejection_reason }}"></i>
                                                        @endif
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
    </div>
@endsection

@php use Carbon\Carbon;use Modules\Sale\Entities\Sale; @endphp
@extends('layouts.app')

@section('title', 'Rincian Penjualan')

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center">
                <div>
                    Referensi: <strong>{{ $sale->reference ?? 'N/A' }}</strong>
                </div>
                <a target="_blank" class="btn btn-sm btn-secondary mfs-auto mfe-1 d-print-none"
                   href="{{ route('sales.pdf', $sale->id) }}">
                    <i class="bi bi-printer"></i> Cetak
                </a>
                <a target="_blank" class="btn btn-sm btn-info mfe-1 d-print-none"
                   href="{{ route('sales.pdf', $sale->id) }}">
                    <i class="bi bi-save"></i> Simpan
                </a>
                <a class="btn btn-sm btn-info mfe-1 d-print-none" href="{{ route('sales.index') }}">
                    <i class="bi bi-back"></i> Kembali
                </a>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <!-- Informasi Bisnis -->
                    <div class="col-sm-4 mb-3 mb-md-0">
                        <h5 class="mb-2 border-bottom pb-2">Informasi Bisnis:</h5>
                        <div><strong>{{ settings()->company_name }}</strong></div>
                        <div>{{ settings()->company_address }}</div>
                        <div>Email: {{ settings()->company_email }}</div>
                        <div>Kontak: {{ settings()->company_phone }}</div>
                    </div>
                    <!-- Informasi Pelanggan -->
                    <div class="col-sm-4 mb-3 mb-md-0">
                        <h5 class="mb-2 border-bottom pb-2">Informasi Pelanggan:</h5>
                        <div><strong>{{ $customer->customer_name }}</strong></div>
                        <!-- Tambahkan info lain jika ada, seperti alamat, email, dsb. -->
                    </div>
                    <!-- Info Faktur -->
                    <div class="col-sm-4 mb-3 mb-md-0">
                        <h5 class="mb-2 border-bottom pb-2">Info Faktur:</h5>
                        <div>Faktur: <strong>INV/{{ $sale->reference }}</strong></div>
                        <div>Tanggal: {{ Carbon::parse($sale->date)->format('d M, Y') }}</div>
                        <div>Status: <strong>{{ $sale->status }}</strong></div>
                        <div>Status Pembayaran: <strong>{{ $sale->payment_status }}</strong></div>
                    </div>
                </div>

                <!-- Detail Penjualan -->
                <div class="table-responsive-sm">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th class="align-middle">Produk</th>
                            <th class="align-middle">Harga Satuan</th>
                            <th class="align-middle">Kuantitas</th>
                            <th class="align-middle">Diskon</th>
                            <th class="align-middle">Pajak</th>
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
                                <td class="align-middle">{{ format_currency($detail->product_tax_amount) }}</td>
                                <td class="align-middle">{{ format_currency($detail->sub_total) }}</td>
                            </tr>

                            {{-- Tampilkan bundle items jika ada --}}
                            @if($detail->bundleItems->isNotEmpty())
                                <tr>
                                    <td colspan="6">
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
                                                        <td>{{ format_currency($bundle->price) }}</td>
                                                        <td>{{ $bundle->quantity }}</td>
                                                        <td>{{ format_currency($bundle->sub_total) }}</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Ringkasan Total -->
                <div class="row">
                    <div class="col-lg-4 col-sm-5 ml-md-auto">
                        <table class="table">
                            <tbody>
                            <tr>
                                <td class="left"><strong>Diskon ({{ $sale->discount_percentage }}%)</strong></td>
                                <td class="right">{{ format_currency($sale->discount_amount) }}</td>
                            </tr>
                            <tr>
                                <td class="left"><strong>Pajak ({{ $sale->tax_percentage }}%)</strong></td>
                                <td class="right">{{ format_currency($sale->tax_amount) }}</td>
                            </tr>
                            <tr>
                                <td class="left"><strong>Pengiriman</strong></td>
                                <td class="right">{{ format_currency($sale->shipping_amount) }}</td>
                            </tr>
                            <tr>
                                <td class="left"><strong>Total Keseluruhan</strong></td>
                                <td class="right"><strong>{{ format_currency($sale->total_amount) }}</strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Catatan -->
                <div class="row mt-4">
                    <div class="col-sm-12">
                        <h5 class="mb-2 border-bottom pb-2">Catatan:</h5>
                        <p>{{ $sale->note ?? 'Tidak ada catatan.' }}</p>
                    </div>
                </div>
            </div>

            <div class="card-footer text-end">
                @if ($sale->status === Sale::STATUS_DRAFTED)
                    <form method="POST" action="{{ route('sales.updateStatus', $sale->id) }}" class="d-inline">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="{{ Sale::STATUS_WAITING_APPROVAL }}">
                        <button type="submit" class="btn btn-warning">Kirim untuk Persetujuan</button>
                    </form>
                    <a href="{{ route('sales.edit', $sale->id) }}" class="btn btn-primary">
                        <i class="bi bi-pencil mr-2"></i> Ubah
                    </a>
                @endif

                @can('sale.approval')
                    @if ($sale->status === Sale::STATUS_WAITING_APPROVAL)
                        <form method="POST" action="{{ route('sales.updateStatus', $sale->id) }}" class="d-inline">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ Sale::STATUS_APPROVED }}">
                            <button type="submit" class="btn btn-success">Setuju</button>
                        </form>
                        <form method="POST" action="{{ route('sales.updateStatus', $sale->id) }}" class="d-inline">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ Sale::STATUS_REJECTED }}">
                            <button type="submit" class="btn btn-danger">Tolak</button>
                        </form>
                    @endif
                @endcan

                @can('sale.receiving')
                    @if ($sale->status === Sale::STATUS_APPROVED || $sale->status === Sale::STATUS_DISPATCHED_PARTIALLY)
                        <a href="{{ route('sales.dispatch', $sale->id) }}" class="btn btn-primary">
                            Keluarkan
                        </a>
                    @endif
                @endcan
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        // Tambahkan skrip JavaScript jika diperlukan untuk fungsi dinamis
    </script>
@endpush

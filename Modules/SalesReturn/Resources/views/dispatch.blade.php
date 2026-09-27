@extends('layouts.app')

@section('title', 'Pengiriman Penggantian / Perbaikan')

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-body">
                <h5 class="mb-3">Pengajuan Pengiriman untuk Retur: {{ $saleReturn->reference }}</h5>

                <form action="{{ route('sale-returns.dispatch.request', $saleReturn->id) }}" method="POST">
                    @csrf

                    <table class="table">
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Serial / Qty</th>
                                <th>Metode</th>
                                <th>Serial Dikirim (opsional)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $item)
                                <tr>
                                    <td>{{ $item->detail?->product?->product_name ?? '-' }}</td>
                                    <td>{{ $item->serialNumber?->serial_number ?? ($item->detail->quantity ?? 1) }}</td>
                                    <td>{{ $item->method }}</td>
                                    <td>
                                        <input type="hidden" name="items[][id]" value="{{ $item->id }}">
                                        @if($item->detail?->product?->serial_number_required)
                                            {{-- For serial products, user enters dispatched serial (auto-select could be applied) --}}
                                            <input type="text" name="items[][dispatched_serial_number]" class="form-control form-control-sm" value="{{ $item->dispatched_serial_number ?? $item->serialNumber?->serial_number }}">
                                        @else
                                            {{-- For non-serial products, let user choose source location --}}
                                            <select name="items[][source_location_id]" class="form-select form-select-sm">
                                                <option value="">-- Pilih Lokasi Sumber --</option>
                                                @foreach(\Modules\Setting\Entities\Location::all() as $loc)
                                                    <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Ajukan Pengiriman</button>
                        <a href="{{ route('sale-returns.show', $saleReturn->id) }}" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Inspeksi Barang Retur')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-body">
                <h5 class="mb-1">Inspeksi Barang Retur: {{ $sale_return->reference }}</h5>
                <p class="text-muted small mb-3">
                    Barang yang diterima ditahan (tidak dapat dijual) sampai dilepas di sini. Pelepasan memindahkan barang ke stok
                    lokasi/pajak asalnya satu kali. Barang yang akan dikirim kembali ke pelanggan tidak dapat dilepas.
                </p>

                @include('salesreturn::partials.custody-summary', ['custody' => $custody])

                @if($custody['held'] > 0)
                    <form action="{{ route('sale-returns.inspect.store', $sale_return) }}" method="POST" class="mt-3">
                        @csrf
                        <input type="hidden" name="request_key" value="{{ $request_key }}">

                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th></th><th>Produk</th><th>Serial</th><th>Ditahan</th><th>Jumlah dilepas</th><th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($custody['rows'] as $row)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="release[{{ $row->id }}][selected]" value="1"
                                               @disabled($row->release_blocker || $row->held_quantity <= 0)>
                                    </td>
                                    <td>{{ $row->product?->product_name }}</td>
                                    <td>{{ $row->serialNumber?->serial_number ?? '-' }}</td>
                                    <td>{{ $row->held_quantity }}</td>
                                    <td style="width:120px">
                                        @if($row->product_serial_number_id)
                                            <input type="hidden" name="release[{{ $row->id }}][quantity]" value="1">1
                                        @else
                                            <input type="number" min="0.001" step="0.001" max="{{ $row->held_quantity }}" name="release[{{ $row->id }}][quantity]"
                                                   value="{{ $row->held_quantity }}" class="form-control form-control-sm"
                                                   @disabled($row->release_blocker || $row->held_quantity <= 0)>
                                        @endif
                                    </td>
                                    <td class="small text-muted">{{ $row->release_blocker ?? 'Dapat dilepas' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        <div class="mb-3">
                            <label class="form-label" for="notes">Catatan inspeksi <span class="text-danger">*</span></label>
                            <textarea id="notes" name="notes" rows="3" class="form-control @error('notes') is-invalid @enderror" required>{{ old('notes') }}</textarea>
                            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-primary">Lepas ke Stok</button>
                        <a href="{{ route('sale-returns.show', $sale_return) }}" class="btn btn-light">Kembali</a>
                    </form>
                @else
                    <div class="alert alert-info mt-3 mb-0">Tidak ada barang yang masih ditahan.</div>
                @endif
            </div>
        </div>
    </div>
@endsection

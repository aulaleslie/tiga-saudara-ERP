@extends('layouts.app')

@section('title', 'Catat Penerimaan Fisik Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.receivals.index') }}">Konsinyasi</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.receivals.show', $receival->id) }}">{{ $receival->reference }}</a></li>
        <li class="breadcrumb-item active">Penerimaan Fisik</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('consignments.receivings.store', $receival->id) }}" method="POST">
            @csrf
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white font-weight-bold">
                    Informasi Penerimaan Fisik (Ref Dokumen: {{ $receival->reference }})
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Supplier:</small>
                            <strong>{{ $receival->supplier->supplier_name }}</strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Tanggal Dokumen:</small>
                            <div>{{ $receival->date->format('d/m/Y') }}</div>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Ref Surat Jalan Supplier:</small>
                            <div>{{ $receival->supplier_delivery_reference ?? '-' }}</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-md-6 mb-3">
                            <label for="location_id">Lokasi Konsinyasi <span class="text-danger">*</span></label>
                            <select name="location_id" id="location_id" class="form-control" required>
                                <option value="">-- Pilih Lokasi Konsinyasi --</option>
                                @foreach($locations as $loc)
                                    <option value="{{ $loc->id }}" {{ old('location_id') == $loc->id ? 'selected' : '' }}>
                                        {{ $loc->name }} (Konsinyasi)
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted">Hanya lokasi yang diklasifikasikan sebagai konsinyasi yang dapat dipilih.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="date">Tanggal Penerimaan Fisik <span class="text-danger">*</span></label>
                            <input type="date" name="date" id="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="external_delivery_number">No. Surat Jalan Penerimaan</label>
                            <input type="text" name="external_delivery_number" id="external_delivery_number" class="form-control" value="{{ old('external_delivery_number', $receival->supplier_delivery_reference) }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="note">Catatan Penerimaan</label>
                            <input type="text" name="note" id="note" class="form-control" value="{{ old('note') }}" placeholder="Catatan fisik...">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white font-weight-bold">
                    Rincian Barang yang Diterima (Penerimaan Penuh)
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 30%;">Produk</th>
                                    <th style="width: 15%;">Jumlah Disetujui</th>
                                    <th style="width: 15%;">Jumlah Diterima <span class="text-danger">*</span></th>
                                    <th style="width: 40%;">Nomor Seri (Serial Number)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($receival->lines as $line)
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold">{{ $line->product_name }}</div>
                                            <small class="text-muted">{{ $line->product_code }}</small>
                                            @if($line->is_serialized)
                                                <span class="badge badge-info d-block mt-1" style="width: fit-content;">Serial Number Wajib</span>
                                            @endif
                                        </td>
                                        <td class="align-middle">{{ $line->quantity }} {{ $line->unit_code }}</td>
                                        <td class="align-middle">
                                            <input type="number" name="details[{{ $line->id }}][quantity_received]" class="form-control form-control-sm font-weight-bold bg-light" value="{{ $line->quantity }}" readonly>
                                        </td>
                                        <td>
                                            @if($line->is_serialized)
                                                <textarea name="details[{{ $line->id }}][serial_numbers]" class="form-control form-control-sm" rows="3" placeholder="Masukkan 1 nomor seri per baris (total {{ (int)$line->quantity }} nomor seri)..." required>{{ old("details.{$line->id}.serial_numbers") }}</textarea>
                                                <small class="text-muted">Pisahkan dengan baris baru (Enter) atau tanda koma.</small>
                                            @else
                                                <span class="text-muted italic">- Tidak Memerlukan Serial -</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end mb-4">
                <a href="{{ route('consignments.receivals.show', $receival->id) }}" class="btn btn-secondary mr-2">Batal</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Penerimaan Fisik (PENDING)</button>
            </div>
        </form>
    </div>
@endsection

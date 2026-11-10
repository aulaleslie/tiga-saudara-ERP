@extends('layouts.app')

@section('title', 'Persiapan Pembelian Putus Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.buyouts.index') }}">Pembelian Putus</a></li>
        <li class="breadcrumb-item active">Persiapan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h4 class="card-title">Persiapan Pembelian Putus</h4>
                <p class="text-muted mb-3">Pilih supplier untuk melihat penerimaan titipan, kapasitas, penjualan yang belum diatribusikan, dan semua penghalang. Sistem tidak memilih atau mengganti kuantitas secara otomatis.</p>

                @unless($mutationsEnabled)
                    <div class="alert alert-info py-2">Pembuatan draft belum diaktifkan; halaman ini hanya persiapan dan pemeriksaan.</div>
                @endunless

                <form method="GET" action="{{ route('consignments.buyouts.create') }}" class="row">
                    <div class="col-md-6 mb-2">
                        @include('consignment::partials.ajax-select', [
                            'name' => 'supplier_id', 'url' => route('consignments.select.suppliers'), 'selectedId' => $supplierId ?: null,
                            'selectedText' => $selectedSupplierText ?? null, 'placeholder' => '-- Pilih Supplier --',
                        ])
                    </div>
                    <div class="col-md-3 mb-2">
                        <select name="destination_location_id" class="form-control" title="Lokasi tujuan stok milik">
                            <option value="">-- Lokasi tujuan --</option>
                            @foreach($destinations as $dest)<option value="{{ $dest->id }}" @selected((int) $destinationId === (int) $dest->id)>{{ $dest->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <button class="btn btn-secondary" type="submit"><i class="bi bi-search"></i> Tampilkan</button>
                        <button class="btn btn-outline-primary" type="submit" name="propose" value="all"><i class="bi bi-list-check"></i> Usulkan semua yang memenuhi syarat</button>
                    </div>
                </form>
                @if($proposalError)<div class="alert alert-warning py-2 mt-2 mb-0">{{ $proposalError }}</div>@endif
            </div>
        </div>

        @if($supplierId)
            @if($unattributed->isNotEmpty())
                <div class="card border-warning shadow-sm mb-3">
                    <div class="card-header bg-warning text-dark">Penjualan belum diatribusikan ke penerimaan supplier ({{ $unattributed->count() }})</div>
                    <div class="card-body">
                        <p class="small text-muted">Atribusi bersifat non-finansial (tidak membuat tagihan untuk supplier mana pun). Selesaikan sebelum membeli stok yang belum terjual.</p>
                        <ul class="mb-0">
                            @foreach($unattributed as $row)
                                <li>
                                    Sumber terjual #{{ $row['source']->id }} — {{ $row['source']->product->product_name ?? '-' }}, {{ rtrim(rtrim(number_format((float) $row['source']->original_base_quantity, 3, '.', ''), '0'), '.') }} unit:
                                    {{ implode(' ', $row['blockers']) }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="mb-3">Penerimaan titipan supplier</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>Penerimaan</th><th>Lokasi</th><th>Produk</th><th class="text-right">Diterima</th>
                                    <th class="text-right">Terjual (atribusi)</th><th class="text-right">Dicadangkan</th>
                                    <th class="text-right">Dibeli (milik)</th><th class="text-right">Tersedia untuk dibeli</th><th>Penghalang</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($receipts as $r)
                                    @php($d = $r['detail']) @php($c = $r['capacity'])
                                    @php($pool = $poolBlockers[$d->product_id . ':' . $d->receiving->location_id] ?? [])
                                    <tr>
                                        <td>{{ $d->receiving->receiving_number }}</td>
                                        <td>{{ $d->receiving->location->name ?? '-' }}</td>
                                        <td>{{ $d->product->product_name ?? '-' }}@if($d->product && $d->product->serial_number_required) <span class="badge badge-light">serial</span>@endif</td>
                                        <td class="text-right">{{ $c['received'] }}</td>
                                        <td class="text-right">{{ $c['attributed_sold_net'] }}</td>
                                        <td class="text-right">{{ $c['buyout_reserved'] }}</td>
                                        <td class="text-right">{{ $c['acquired_unsold'] }}</td>
                                        <td class="text-right font-weight-bold">{{ $c['unsold_available'] }}</td>
                                        <td class="small">
                                            @foreach($c['conservation_errors'] as $e)<div class="text-danger">{{ $e }}</div>@endforeach
                                            @foreach($c['blockers'] as $e)<div class="text-warning">{{ $e }}</div>@endforeach
                                            @foreach($pool as $e)<div class="text-warning">{{ $e['reason'] }}</div>@endforeach
                                            @if(! $c['conservation_errors'] && ! $c['blockers'] && ! $pool)<span class="text-success">Tidak ada</span>@endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="text-center text-muted py-3">Supplier ini tidak memiliki penerimaan titipan yang disetujui.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mb-0">Lokasi tujuan yang valid (standar, bisnis yang sama): {{ $destinations->pluck('name')->implode(', ') ?: '—' }}.</p>
                </div>
            </div>

            @if($proposal)
                @php($fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0')
                <div class="card border-primary shadow-sm mt-3" id="proposal-review">
                    <div class="card-header bg-primary text-white">Tinjauan: semua yang memenuhi syarat (hanya usulan, belum ada dokumen)</div>
                    <div class="card-body">
                        <p class="small text-muted">Setiap penerimaan dan sumber terjual milik supplier ini muncul tepat satu kali, ikut atau dikecualikan beserta alasannya. Tidak ada supplier, penerimaan, lokasi, atau kuantitas yang diganti diam-diam. Bucket fisik dipakai berurutan (non-pajak lalu pajak) dan dapat diubah sebelum draft disimpan.</p>
                        <div class="mb-2"><strong>Total diusulkan:</strong> belum terjual {{ $fmt($proposal['totals']['unsold']) }} · terjual belum ditagih {{ $fmt($proposal['totals']['sold']) }}</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm">
                                <thead class="thead-light"><tr><th>Jenis</th><th>Penerimaan / Produk</th><th class="text-right">Kapasitas</th><th class="text-right">Diusulkan</th><th>Serial</th><th>Status</th></tr></thead>
                                <tbody>
                                    @foreach(array_merge($proposal['sold'], $proposal['unsold']) as $row)
                                        <tr class="{{ $row['included'] ? '' : 'table-secondary' }}">
                                            <td>{{ $row['line_type'] === 'SOLD_UNBILLED' ? 'Terjual belum ditagih' : 'Belum terjual' }}</td>
                                            <td>{{ $row['receiving_number'] }} — {{ $row['product'] }} ({{ $row['location'] }})@if(isset($row['sale_id'])) <span class="small text-muted">penjualan #{{ $row['sale_id'] }}</span>@endif</td>
                                            <td class="text-right">{{ $fmt($row['requested']) }}</td>
                                            <td class="text-right font-weight-bold">{{ $fmt($row['proposed']) }}</td>
                                            <td class="small">{{ $row['serial_ids'] ? implode(', ', $row['serial_ids']) : '—' }}</td>
                                            <td class="small">
                                                @if($row['included'])<span class="badge badge-success">Ikut</span>@else<span class="badge badge-secondary">Dikecualikan</span>@endif
                                                @foreach($row['reasons'] as $reason)<div class="{{ $row['included'] ? 'text-warning' : 'text-danger' }}">{{ $reason }}</div>@endforeach
                                            </td>
                                        </tr>
                                    @endforeach
                                    @foreach($proposal['unattributed'] as $u)
                                        <tr class="table-secondary"><td>Terjual</td><td>Sumber terjual #{{ $u['source_id'] }}</td><td class="text-right">—</td><td class="text-right">0</td><td>—</td>
                                            <td class="small"><span class="badge badge-secondary">Dikecualikan</span> @foreach($u['reasons'] as $reason)<div class="text-danger">{{ $reason }}</div>@endforeach</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="small mb-0">Usulan diverifikasi ulang penuh saat draft disimpan, diajukan, dan disetujui; bukan jaminan ketersediaan.</p>
                    </div>
                </div>
            @endif

            @if($mutationsEnabled)
                @can('consignments.buyouts.create')
                <form method="POST" action="{{ route('consignments.buyouts.store') }}" class="card border-0 shadow-sm mt-3">
                    @csrf
                    <input type="hidden" name="supplier_id" value="{{ $supplierId }}">
                    @php($pu = collect($proposal['unsold'] ?? [])->keyBy('consignment_receiving_detail_id'))
                    @php($ps = collect($proposal['sold'] ?? [])->keyBy(fn ($r) => $r['consignment_sold_source_id'] . ':' . $r['consignment_receiving_detail_id']))
                    <div class="card-body">
                        <h5 class="mb-3">Buat draft @if($proposal)<span class="badge badge-primary">diisi dari tinjauan; hilangkan centang untuk mengecualikan baris</span>@endif</h5>
                        <div class="form-row mb-3">
                            <div class="col-md-3"><label>Tanggal</label><input type="date" name="date" class="form-control" value="{{ old('date', now()->toDateString()) }}" required></div>
                            <div class="col-md-3"><label>No. Faktur Supplier</label><input name="invoice_metadata[supplier_invoice_number]" class="form-control" value="{{ old('invoice_metadata.supplier_invoice_number') }}"></div>
                            <div class="col-md-3"><label>Tanggal Faktur</label><input type="date" name="invoice_metadata[invoice_date]" class="form-control" value="{{ old('invoice_metadata.invoice_date', now()->toDateString()) }}"></div>
                            <div class="col-md-3"><label>Diskon Dokumen</label>
                                <div class="input-group">
                                    <select name="pricing_intent[global_discount_type]" class="form-control"><option value="fixed">Rp</option><option value="percentage">%</option></select>
                                    <input type="number" step="0.01" min="0" name="pricing_intent[global_discount_value]" class="form-control" value="0">
                                </div>
                            </div>
                        </div>

                        <h6>Stok belum terjual (dibeli &amp; dipindahkan ke stok milik)</h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered table-sm">
                                <thead class="thead-light"><tr><th>Penerimaan / Produk</th><th>Kuantitas beli</th><th>Bucket Pajak (kena pajak)</th><th>Bucket Non-Pajak</th><th>ID Serial (pisah koma)</th><th>Lokasi tujuan</th></tr></thead>
                                <tbody>
                                    @foreach($receipts as $i => $r)
                                        @php($d = $r['detail'])
                                        @php($pr = $pu->get($d->id))
                                        @php($pr = $pr && $pr['included'] ? $pr : null)
                                        <tr>
                                            <td>{{ $d->receiving->receiving_number }} — {{ $d->product->product_name ?? '-' }} (tersedia {{ $r['capacity']['unsold_available'] }})
                                                <input type="hidden" name="lines[u{{ $i }}][line_type]" value="UNSOLD_BUYOUT">
                                                <input type="hidden" name="lines[u{{ $i }}][consignment_receiving_detail_id]" value="{{ $d->id }}">
                                                @if($pr)<input type="hidden" name="lines[u{{ $i }}][reviewed]" value="1"><label class="small mb-0 d-block"><input type="checkbox" name="lines[u{{ $i }}][include]" value="1" checked> sertakan</label>@endif</td>
                                            <td><input type="number" step="0.001" min="0" name="lines[u{{ $i }}][base_quantity]" class="form-control form-control-sm" value="{{ $pr['proposed'] ?? '' }}"></td>
                                            <td><input type="number" step="0.001" min="0" name="lines[u{{ $i }}][buckets][TAXED]" class="form-control form-control-sm" value="{{ $pr['buckets']['TAXED'] ?? '' }}"></td>
                                            <td><input type="number" step="0.001" min="0" name="lines[u{{ $i }}][buckets][NON_TAXED]" class="form-control form-control-sm" value="{{ $pr['buckets']['NON_TAXED'] ?? '' }}"></td>
                                            <td><input name="lines[u{{ $i }}][serial_ids_text]" class="form-control form-control-sm" placeholder="mis. 12,13" value="{{ $pr ? implode(',', $pr['serial_ids']) : '' }}"></td>
                                            <td><select name="lines[u{{ $i }}][destination_location_id]" class="form-control form-control-sm">@foreach($destinations as $dest)<option value="{{ $dest->id }}" @selected((int) $destinationId === (int) $dest->id)>{{ $dest->name }}</option>@endforeach</select></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <h6>Terjual belum ditagih (ditagih pada faktur yang sama; tidak memindahkan stok)</h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered table-sm">
                                <thead class="thead-light"><tr><th>Sumber terjual / Penerimaan</th><th>Kuantitas tagih</th><th>ID Serial (pisah koma)</th><th>Lokasi tujuan retur</th></tr></thead>
                                <tbody>
                                    @forelse($soldRows as $i => $row)
                                        @php($pr = $ps->get($row['source']->id . ':' . $row['receiving_detail_id']))
                                        @php($pr = $pr && $pr['included'] ? $pr : null)
                                        <tr>
                                            <td>Sumber #{{ $row['source']->id }} / penerimaan #{{ $row['receiving_detail_id'] }} (atribusi {{ $row['attributed'] }}, tersedia {{ $row['available'] }})
                                                <input type="hidden" name="lines[s{{ $i }}][line_type]" value="SOLD_UNBILLED">
                                                <input type="hidden" name="lines[s{{ $i }}][consignment_receiving_detail_id]" value="{{ $row['receiving_detail_id'] }}">
                                                <input type="hidden" name="lines[s{{ $i }}][consignment_sold_source_id]" value="{{ $row['source']->id }}">
                                                @if($pr)<input type="hidden" name="lines[s{{ $i }}][reviewed]" value="1"><label class="small mb-0 d-block"><input type="checkbox" name="lines[s{{ $i }}][include]" value="1" checked> sertakan</label>@endif</td>
                                            <td><input type="number" step="0.001" min="0" name="lines[s{{ $i }}][base_quantity]" class="form-control form-control-sm" value="{{ $pr['proposed'] ?? '' }}"></td>
                                            <td><input name="lines[s{{ $i }}][serial_ids_text]" class="form-control form-control-sm" value="{{ $pr ? implode(',', $pr['serial_ids']) : '' }}"></td>
                                            <td><select name="lines[s{{ $i }}][destination_location_id]" class="form-control form-control-sm">@foreach($destinations as $dest)<option value="{{ $dest->id }}" @selected((int) $destinationId === (int) $dest->id)>{{ $dest->name }}</option>@endforeach</select></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted">Tidak ada penjualan yang sudah diatribusikan lengkap.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Draft</button>
                    </div>
                </form>
                @endcan
            @endif
        @endif
    </div>
@endsection

@push('page_scripts')
    @include('consignment::partials.ajax-select-scripts')
@endpush

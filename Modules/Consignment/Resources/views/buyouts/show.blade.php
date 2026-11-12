@extends('layouts.app')

@section('title', 'Pembelian Putus ' . $buyout->reference)

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.buyouts.index') }}">Pembelian Putus</a></li>
        <li class="breadcrumb-item active">{{ $buyout->reference }}</li>
    </ol>
@endsection

@php
    $status = $buyout->status;
    $can = fn ($ability) => $mutationsEnabled && \Illuminate\Support\Facades\Gate::allows($ability);
@endphp

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h4 class="mb-1">{{ $buyout->reference }} @include('consignment::buyouts.partials.status-badge', ['status' => $status])</h4>
                        <div class="text-muted">Supplier: <strong>{{ $buyout->supplier->supplier_name ?? '-' }}</strong> · Tanggal {{ $buyout->date->format('d/m/Y') }} · Revisi {{ $buyout->revision }}</div>
                        @if($buyout->purchase)
                            <div class="mt-1">Faktur pembelian:
                                <a href="{{ route('purchases.show', $buyout->purchase->id) }}" class="font-weight-bold">{{ $buyout->purchase->reference }}</a>
                                @if($buyout->purchase->isCancelled()) <span class="badge badge-dark">DIBATALKAN</span>@endif
                            </div>
                        @endif
                    </div>
                    @unless($mutationsEnabled)
                        <div class="alert alert-info py-1 mb-0">Aksi belum diaktifkan (hanya tampilan).</div>
                    @endunless
                </div>

                @if($hashIsCurrent === false)
                    <div class="alert alert-danger mt-3 mb-0">Bukti yang disetujui telah berubah setelah persetujuan. Batalkan dan setujui ulang dokumen ini.</div>
                @endif
            </div>
        </div>

        {{-- Totals and pricing --}}
        <div class="row">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header">Baris dokumen</div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="thead-light">
                                <tr><th>Dokumen penjualan</th><th>Jenis</th><th>Dari</th><th>Tujuan</th><th>Menjadi milik</th><th class="text-right">Kuantitas</th><th>Serial</th>
                                    <th class="text-right">Nilai asli</th><th class="text-right">Faktur</th><th class="text-right">Biaya persediaan</th></tr>
                            </thead>
                            <tbody>
                                @foreach($buyout->lines->groupBy(fn ($l) => $l->receivingDetail->consignment_receiving_id) as $receiptLines)
                                    <tr class="table-primary"><th colspan="10">Penerimaan: {{ $receiptLines->first()->receivingDetail->receiving->receiving_number ?? '-' }}</th></tr>
                                    @foreach($receiptLines->groupBy('product_id') as $productLines)
                                        <tr class="table-light"><th colspan="10">Produk: {{ $productLines->first()->receivingDetail->product->product_name ?? '-' }}</th></tr>
                                        @foreach(['UNSOLD_BUYOUT' => 'Belum terjual (dibeli)', 'SOLD_UNBILLED' => 'Terjual belum ditagih'] as $kind => $kindLabel)
                                            @php($kindLines = $productLines->where('line_type', $kind))
                                            @continue($kindLines->isEmpty())
                                            <tr><th colspan="10" class="text-muted">{{ $kindLabel }}</th></tr>
                                @foreach($kindLines as $line)
                                    @php($snap = $line->accepted_snapshot)
                                    @php($slice = $pricing ? collect($pricing['slices'])->firstWhere('line_id', $line->id) : null)
                                    <tr>
                                        <td>{{ $line->soldSource?->sale?->reference ?? '-' }}</td>
                                        <td>{{ $kindLabel }}</td>
                                        <td>{{ $locations[$line->source_location_id] ?? '-' }}</td>
                                        <td>{{ $locations[$line->destination_location_id] ?? '-' }}@if($line->line_type === 'SOLD_UNBILLED') <small class="text-muted">(tujuan retur)</small>@endif</td>
                                        <td class="small">@if($line->line_type === 'SOLD_UNBILLED')—@else{{ $owners[$line->inventoryOwnerSettingId()] ?? '-' }}@if($line->inventory_owner_setting_id && (int) $line->inventory_owner_setting_id !== (int) $buyout->setting_id) <span class="badge badge-info">bisnis lain</span>@endif @endif</td>
                                        <td class="text-right">{{ rtrim(rtrim(number_format((float) $line->base_quantity, 3, '.', ''), '0'), '.') }}</td>
                                        <td class="small">@forelse($line->serials as $serial)<div class="text-monospace">{{ $serial->serialNumber->serial_number ?? $serial->product_serial_number_id }}</div>@empty—@endforelse</td>
                                        <td class="text-right">{{ number_format((float) ($snap['original']['total'] ?? $slice['original']['total'] ?? 0), 2, ',', '.') }}</td>
                                        <td class="text-right">{{ number_format((float) ($snap['payable'] ?? $slice['payable'] ?? 0), 2, ',', '.') }}</td>
                                        <td class="text-right">{{ isset($snap['inventory_cost']) || isset($slice['inventory_cost']) ? number_format((float) ($snap['inventory_cost'] ?? $slice['inventory_cost']), 2, ',', '.') : '-' }}</td>
                                    </tr>
                                @endforeach
                                        @endforeach
                                    @endforeach
                                @endforeach
                            </tbody>
                            @if($pricing)
                                <tfoot>
                                    <tr><th colspan="8" class="text-right">Diskon dokumen</th><th class="text-right">{{ number_format($pricing['totals']['global_discount_amount'], 2, ',', '.') }}</th><th></th></tr>
                                    <tr><th colspan="8" class="text-right">Total faktur <small class="text-muted">(ditagih ke {{ $owners[$buyout->setting_id] ?? '-' }} saja)</small></th><th class="text-right">{{ number_format($pricing['totals']['total_amount'], 2, ',', '.') }}</th><th class="text-right">{{ number_format($pricing['totals']['inventory_cost_total'], 2, ',', '.') }}</th></tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>

                @if(($pricing && ! $pricing['valid']) || $documentBlockers)
                    <div class="card border-danger shadow-sm mb-3">
                        <div class="card-header bg-danger text-white">Penghalang</div>
                        <ul class="list-group list-group-flush">
                            @foreach($pricing['blockers'] ?? [] as $m)<li class="list-group-item small">{{ $m }}</li>@endforeach
                            @foreach($documentBlockers as $m)<li class="list-group-item small">{{ $m }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                @if($buyout->purchase)
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header">Pembayaran</div>
                        <div class="card-body">
                            @php($p = $buyout->purchase)
                            <div class="row text-center">
                                <div class="col"><div class="text-muted small">Total</div><strong>{{ number_format((float) $p->total_amount, 2, ',', '.') }}</strong></div>
                                <div class="col"><div class="text-muted small">Dibayar</div><strong>{{ number_format((float) $p->paid_amount, 2, ',', '.') }}</strong></div>
                                <div class="col"><div class="text-muted small">Sisa tagihan</div><strong>{{ number_format($p->isCancelled() ? 0 : (float) $p->due_amount, 2, ',', '.') }}</strong></div>
                                <div class="col"><div class="text-muted small">Pembayaran tercatat</div><strong>{{ $p->purchasePayments->count() }}</strong></div>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header">Cadangan &amp; bukti</div>
                    <div class="card-body small">
                        <div>Cadangan aktif: <strong>{{ $buyout->reservations->where('status', 'ACTIVE')->count() }}</strong>
                            · terpakai (final): <strong>{{ $buyout->reservations->where('status', 'CONSUMED')->count() }}</strong>
                            · dilepas: <strong>{{ $buyout->reservations->where('status', 'RELEASED')->count() }}</strong></div>
                        @if($buyout->approved_revision_hash)<div class="text-muted">Hash revisi disetujui: <code>{{ substr(strtolower($buyout->approved_revision_hash), 0, 16) }}…</code></div>@endif
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header">Riwayat</div>
                    <ul class="list-group list-group-flush">
                        @foreach($buyout->auditEvents->sortBy('id') as $ev)
                            <li class="list-group-item small">
                                <strong>{{ $ev->action }}</strong> {{ $ev->from_status ? $ev->from_status . ' → ' : '' }}{{ $ev->to_status }}
                                · {{ $actors[$ev->actor_id] ?? '-' }} · {{ optional($ev->created_at)->format('d/m/Y H:i') }}
                                @if($ev->reason) <div class="text-muted">{{ $ev->reason }}</div>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="col-lg-4">
                {{-- Status-appropriate actions: each one needs the feature enabled AND the dedicated permission, and asks for explicit confirmation. --}}
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header">Aksi</div>
                    <div class="card-body">
                        @php($hidden = '<input type="hidden" name="revision" value="' . $buyout->revision . '">')
                        @if(in_array($status, ['DRAFT', 'REJECTED']) && $can('consignments.buyouts.submit'))
                            <form method="POST" action="{{ route('consignments.buyouts.submit', $buyout->id) }}" class="mb-2" onsubmit="return confirm('Ajukan dokumen ini untuk persetujuan?')">
                                @csrf {!! $hidden !!}<button class="btn btn-primary btn-block">Ajukan Persetujuan</button></form>
                        @endif
                        @if($status === 'WAITING_APPROVAL' && $can('consignments.buyouts.approve'))
                            <form method="POST" action="{{ route('consignments.buyouts.approve', $buyout->id) }}" class="mb-2" onsubmit="return confirm('Setujui dan cadangkan semua sumber daya dokumen ini?')">
                                @csrf {!! $hidden !!}<button class="btn btn-success btn-block">Setujui &amp; Cadangkan</button></form>
                        @endif
                        @if($status === 'WAITING_APPROVAL' && $can('consignments.buyouts.reject'))
                            <form method="POST" action="{{ route('consignments.buyouts.reject', $buyout->id) }}" class="mb-2">
                                @csrf {!! $hidden !!}<input name="reason" class="form-control mb-1" placeholder="Alasan penolakan" required><button class="btn btn-outline-danger btn-block">Tolak</button></form>
                        @endif
                        @if(in_array($status, ['DRAFT', 'WAITING_APPROVAL', 'APPROVED', 'REJECTED']) && $can('consignments.buyouts.cancel'))
                            <form method="POST" action="{{ route('consignments.buyouts.cancel', $buyout->id) }}" class="mb-2">
                                @csrf {!! $hidden !!}<input name="reason" class="form-control mb-1" placeholder="Alasan pembatalan" required>
                                <div class="form-check mb-1"><input type="checkbox" name="confirm" value="1" class="form-check-input" id="c1" required><label for="c1" class="form-check-label small">Saya yakin; cadangan akan dilepas.</label></div>
                                <button class="btn btn-outline-secondary btn-block">Batalkan Dokumen</button></form>
                        @endif
                        @if($status === 'APPROVED' && $can('consignments.buyouts.finalize'))
                            <form method="POST" action="{{ route('consignments.buyouts.finalize', $buyout->id) }}" class="mb-2">
                                @csrf
                                <div class="form-check mb-1"><input type="checkbox" name="confirm" value="1" class="form-check-input" id="c2" required><label for="c2" class="form-check-label small">Buat faktur &amp; pindahkan stok yang belum terjual ke stok milik.</label></div>
                                <button class="btn btn-warning btn-block">Finalisasi</button></form>
                        @endif
                        @if($status === 'FINALIZED' && $can('consignments.buyouts.reverse'))
                            @if($reversalPreview && ! $reversalPreview['eligible'])
                                <div class="alert alert-warning small">Reversal belum dapat dilakukan:
                                    <ul class="mb-0 pl-3">@foreach($reversalPreview['blockers'] as $bl)<li>[{{ $bl['type'] }}] {{ $bl['reason'] }}</li>@endforeach</ul></div>
                            @else
                                <form method="POST" action="{{ route('consignments.buyouts.reverse', $buyout->id) }}" class="mb-2">
                                    @csrf <input name="reason" class="form-control mb-1" placeholder="Alasan reversal" required>
                                    <div class="form-check mb-1"><input type="checkbox" name="confirm" value="1" class="form-check-input" id="c3" required><label for="c3" class="form-check-label small">Batalkan seluruh dokumen (faktur, stok, biaya).</label></div>
                                    <button class="btn btn-danger btn-block">Reversal</button></form>
                            @endif
                        @endif
                        @if($status === 'FINALIZED' && $reversalPreview && ! $mutationsEnabled)
                            <div class="small text-muted">Kelayakan reversal: {{ $reversalPreview['eligible'] ? 'memenuhi syarat' : 'terhalang (' . count($reversalPreview['blockers']) . ')' }}</div>
                        @endif
                        @if(! $mutationsEnabled)<div class="text-muted small">Tidak ada aksi yang tersedia saat fitur dinonaktifkan.</div>@endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

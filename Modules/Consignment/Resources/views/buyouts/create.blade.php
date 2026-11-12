@extends('layouts.app')

@section('title', 'Persiapan Pembelian Putus Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.buyouts.index') }}">Pembelian Putus</a></li>
        <li class="breadcrumb-item active">Persiapan</li>
    </ol>
@endsection

@php
    $canSave = $mutationsEnabled && \Illuminate\Support\Facades\Gate::allows('consignments.buyouts.create');
    // Field names exist only when a draft can actually be saved: otherwise the screen is inspection-only.
    $n = fn (string $name) => $canSave ? new \Illuminate\Support\HtmlString('name="' . e($name) . '"') : '';
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',') ?: '0';
    $oldLines = (array) old('lines', []);
    $prepData = [
        'supplierId' => $supplierId,
        'rows' => $prep['rows'] ?? [],
        'pools' => $prep['pools'] ?? [],
        'poolOrder' => $prep['pool_order'] ?? [],
        'soldLimits' => $prep['sold_limits'] ?? [],
        'holdings' => $prep['holdings'] ?? [],
        'destinations' => $initialDestinations,
        'old' => $oldLines,
        'oldDiscount' => ['type' => old('pricing_intent.global_discount_type', 'fixed'), 'value' => old('pricing_intent.global_discount_value', 0)],
        'startStep' => $oldLines ? 3 : (request()->integer('step') === 1 ? 1 : ($supplierId ? 2 : 1)),
    ];
@endphp

@section('content')
<script type="application/json" id="buyout-prep-data">{!! json_encode($prepData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<div class="container-fluid" x-data="buyoutPrep(JSON.parse(document.getElementById('buyout-prep-data').textContent))" x-cloak>

    @unless($mutationsEnabled)
        <div class="alert alert-info py-2">Pembuatan draft belum diaktifkan; halaman ini hanya persiapan dan pemeriksaan.</div>
    @endunless

    {{-- Stepper --}}
    <ul class="nav nav-pills mb-3" role="tablist">
        @foreach([1 => 'Supplier & Tujuan', 2 => 'Pilih Barang', 3 => 'Tinjau & Simpan Draft'] as $i => $label)
            <li class="nav-item">
                <a href="#" class="nav-link" :class="{ 'active': step === {{ $i }} }" @click.prevent="go({{ $i }})">
                    <span class="badge badge-light mr-1">{{ $i }}</span>{{ $label }}
                </a>
            </li>
        @endforeach
    </ul>

    <div class="alert alert-warning py-2" x-show="error" x-text="error"></div>

    {{-- ================= STEP 1 ================= --}}
    <div class="card border-0 shadow-sm mb-3" x-show="step === 1">
        <div class="card-body">
            <h5 class="mb-3">1. Supplier dan lokasi tujuan</h5>
            <form method="GET" action="{{ route('consignments.buyouts.create') }}" class="form-row align-items-end mb-3" id="buyout-populate-form">
                <input type="hidden" name="step" value="1" id="buyout-supplier-step" disabled>
                @foreach(($prep['holdings'] ?? []) as $sid => $holding)
                    <input type="hidden" name="remote_destinations[{{ $sid }}]" :value="dest[{{ $sid }}] || ''">
                @endforeach
                <div class="col-md-6">
                    <label class="mb-1">Supplier</label>
                    @include('consignment::partials.ajax-select', [
                        'name' => 'supplier_id', 'url' => route('consignments.select.suppliers'), 'selectedId' => $supplierId ?: null,
                        'selectedText' => $selectedSupplierText ?? null, 'placeholder' => '-- Pilih Supplier --',
                    ])
                </div>
                <div class="col-12 small text-muted mt-1">Mengganti supplier memuat ulang daftar barang supplier tersebut.</div>
            </form>

            <div class="alert alert-light border py-2 mb-3">
                Tagihan supplier diterbitkan untuk: <strong>{{ $prep['billing_business'] ?? $originName }}</strong>
                <span class="small text-muted d-block">Bisnis penerima barang titipan asli. Barang yang belum terjual menjadi milik bisnis yang memegangnya.</span>
            </div>

            @if($prep)
                <h6>Lokasi tujuan stok milik (satu per bisnis pemegang)</h6>
                <div class="form-row">
                    @foreach($prep['holdings'] as $sid => $h)
                        <div class="col-md-4 mb-2">
                            <label class="mb-1">{{ $h['name'] }} @if(! $h['local'])<span class="badge badge-info">bisnis lain</span>@endif</label>
                            <select class="form-control" x-model.number="dest[{{ $sid }}]" {{ $n("destinations[{$sid}]") }} form="buyout-draft-form">
                                <option value="">-- Pilih lokasi tujuan --</option>
                                @foreach($h['destinations'] as $d)<option value="{{ $d['id'] }}">{{ $d['name'] }}</option>@endforeach
                            </select>
                            @if(! $h['destinations'])<div class="small text-danger">Bisnis ini tidak memiliki lokasi standar aktif.</div>@endif
                        </div>
                    @endforeach
                </div>
                <p class="small text-muted mb-0">Sistem tidak memilih lokasi tujuan otomatis. Pilihan ini tetap tersimpan saat berpindah langkah.</p>
                <button type="button" class="btn btn-primary mt-3" @click="go(2)">Lanjut: Pilih Barang <i class="bi bi-arrow-right"></i></button>
            @endif
        </div>
    </div>

    @if($prep)
    <form method="POST" action="{{ $canSave ? route('consignments.buyouts.store') : '#' }}" id="buyout-draft-form" @submit="submit($event)">
        @if($canSave) @csrf <input type="hidden" name="supplier_id" value="{{ $supplierId }}"> @endif

        {{-- ================= STEP 2 ================= --}}
        <div class="card border-0 shadow-sm mb-3" x-show="step === 2">
            <div class="card-body">
                <h5 class="mb-3">2. Pilih barang</h5>
                <p class="small text-muted">Belum terjual = stok titipan yang dibeli dan dipindahkan ke stok milik. Terjual belum ditagih = sudah terjual ke pelanggan, hanya ditagih ke supplier (stok tidak berpindah). Jumlah pajak/non-pajak dihitung otomatis dari stok yang dipilih.</p>

                @php($rowsBy = $prep['rows'])
                @forelse($prep['tree'] as $ri => $rc)
                    @php($rcKeys = collect($rc['products'])->flatMap(fn ($pg) => array_merge(collect($pg['unsold'])->flatMap(fn ($g) => $g['keys'])->all(), $pg['sold']))->values()->all())
                    <div class="border rounded mb-3 buyout-receipt" data-receipt="{{ $rc['receipt'] }}">
                        <div class="bg-light px-3 py-2 d-flex align-items-center">
                            <input type="checkbox" class="mr-2" aria-label="Pilih semua di penerimaan {{ $rc['receipt'] }}"
                                   x-effect="$el.checked = groupState(@js($rcKeys)) === 'all'; $el.indeterminate = groupState(@js($rcKeys)) === 'some'"
                                   @change="toggleGroup(@js($rcKeys), $el.checked)">
                            <strong class="text-dark">Penerimaan: {{ $rc['receipt'] }}</strong>
                            <span class="ml-auto small text-muted" x-text="groupCount(@js($rcKeys))"></span>
                        </div>

                        @foreach($rc['products'] as $pg)
                            @php($pKeys = array_merge(collect($pg['unsold'])->flatMap(fn ($g) => $g['keys'])->all(), $pg['sold']))
                            <div class="px-3 pt-2 pb-1 border-top buyout-product">
                                <div class="d-flex align-items-center mb-1">
                                    <input type="checkbox" class="mr-2" aria-label="Pilih semua {{ $pg['product'] }}"
                                           x-effect="$el.checked = groupState(@js($pKeys)) === 'all'; $el.indeterminate = groupState(@js($pKeys)) === 'some'"
                                           @change="toggleGroup(@js($pKeys), $el.checked)">
                                    <span class="font-weight-bold">Produk: {{ $pg['product'] }}</span>
                                    @if($pg['product_code'])<span class="small text-muted ml-1">{{ $pg['product_code'] }}</span>@endif
                                    <span class="ml-auto small text-muted" x-text="groupCount(@js($pKeys))"></span>
                                </div>

                                @foreach(['unsold' => 'Belum terjual', 'sold' => 'Terjual belum ditagih'] as $kind => $kindLabel)
                                    @php($sections = $kind === 'unsold' ? $pg['unsold'] : ($pg['sold'] ? [['keys' => $pg['sold']]] : []))
                                    @continue(! $sections)
                                    <div class="ml-4 mb-2 buyout-kind" data-kind="{{ $kind }}">
                                        <div class="small font-weight-bold text-uppercase text-muted mb-1">{{ $kindLabel }}</div>
                                        @foreach($sections as $sec)
                                            @if($kind === 'unsold' && (count($pg['unsold']) > 1 || $sec['remote']))
                                                <div class="small mb-1">Lokasi saat ini: <strong>{{ $sec['location'] }}</strong> · menjadi milik <strong>{{ $sec['owner'] }}</strong>
                                                    @if($sec['remote'])<span class="badge badge-info">bisnis lain</span>@endif</div>
                                            @elseif($kind === 'unsold')
                                                <div class="small text-muted mb-1">Lokasi saat ini: {{ $sec['location'] }}</div>
                                            @endif
                                            @foreach($sec['keys'] as $key)
                                                @php($r = $rowsBy[$key])
                                                <div class="ml-2 buyout-row" data-key="{{ $key }}">
                                                    <input type="hidden" {{ $n("lines[{$key}][row_key]") }} value="{{ $key }}" :disabled="!isOn('{{ $key }}')">
                                                    @foreach($r['fields'] as $field => $value)
                                                        <input type="hidden" {{ $n("lines[{$key}][{$field}]") }} value="{{ $value }}" :disabled="!isOn('{{ $key }}')">
                                                    @endforeach
                                                    @php($doc = $r['document'] ?? null)
                                                    @php($docHtml = $doc ? ($doc['url'] ? '<a href="' . e($doc['url']) . '" target="_blank" rel="noopener">' . e($doc['label']) . '</a>' : e($doc['label'])) . ($doc['secondary'] ? ' <span class="text-muted">(' . e($doc['secondary']) . ')</span>' : '') . (! empty($doc['pos_business']) ? ' <span class="badge badge-light">POS: ' . e($doc['pos_business']) . '</span>' : '') . ($doc['selling_business'] ? ' <span class="badge badge-light">Penjualan: ' . e($doc['selling_business']) . '</span>' : '') : '')
                                                    @if($r['serialized'])
                                                        @foreach($r['serials'] as $sn)
                                                            <label class="d-block mb-1 buyout-serial" data-serial="{{ $sn['label'] }}">
                                                                <input type="checkbox" value="{{ $sn['id'] }}" {{ $n("lines[{$key}][serial_ids][]") }}
                                                                       x-model="sel['{{ $key }}'].serials"
                                                                       :disabled="!sel['{{ $key }}'].serials.includes('{{ $sn['id'] }}') && sel['{{ $key }}'].serials.length >= room('{{ $key }}')">
                                                                Serial <span class="text-monospace">{{ $sn['label'] }}</span>@if($kind === 'sold') — {!! $docHtml !!}@endif
                                                            </label>
                                                        @endforeach
                                                        <input type="hidden" {{ $n("lines[{$key}][base_quantity]") }} :value="sel['{{ $key }}'].serials.length" :disabled="!isOn('{{ $key }}')">
                                                    @else
                                                        <div class="form-inline mb-1">
                                                            <input type="checkbox" class="mr-2" x-model="sel['{{ $key }}'].on" @change="toggled('{{ $key }}')" aria-label="Pilih">
                                                            <input type="number" step="0.001" min="0" max="{{ $r['max'] }}" class="form-control form-control-sm mr-2" style="width:7rem"
                                                                   {{ $n("lines[{$key}][base_quantity]") }} x-model="sel['{{ $key }}'].qty" :disabled="!sel['{{ $key }}'].on">
                                                            <span class="small">dari {{ $fmt($r['max']) }} tersedia @if($kind === 'sold') — {!! $docHtml !!}@endif</span>
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @empty
                    <div class="text-center text-muted py-3">Tidak ada barang supplier ini yang dapat dipilih saat ini.</div>
                @endforelse

                <div class="mb-2"><strong>Dipilih:</strong> belum terjual <span x-text="fmt(totalQty('unsold'))"></span> · terjual belum ditagih <span x-text="fmt(totalQty('sold'))"></span></div>

                @if($prep['blocked'])
                    <div class="card border-warning mt-3" id="blocked-goods">
                        <div class="card-header bg-warning text-dark">Barang yang belum dapat dipilih ({{ count($prep['blocked']) }})</div>
                        <div class="card-body p-0">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Penerimaan / Produk</th><th>Dokumen penjualan</th><th>Lokasi</th><th>Alasan dan tindakan</th></tr></thead>
                                <tbody>
                                @foreach($prep['blocked'] as $b)
                                    <tr>
                                        <td>{{ $b['receipt'] ?? '—' }} <span class="d-block">{{ $b['product'] }}</span> <span class="small text-muted d-block">{{ $b['kind'] === 'sold' ? 'Terjual belum ditagih' : 'Belum terjual' }}</span></td>
                                        <td class="small">@if(! empty($b['document'])){{ $b['document']['label'] }}@if($b['document']['secondary'])<div class="text-muted">{{ $b['document']['secondary'] }}</div>@endif @elseif($b['sale_reference'])Penjualan {{ $b['sale_reference'] }}@else — @endif
                                            @isset($b['quantity'])<div>{{ $fmt($b['quantity']) }} unit</div>@endisset</td>
                                        <td class="small">{{ $b['location'] }}</td>
                                        <td class="small">
                                            @foreach($b['explanations'] as $e)
                                                <div>{{ $e['text'] }} @if($e['action'])<a href="{{ $e['action']['url'] }}">{{ $e['action']['label'] }}</a>@endif</div>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
                @if($prep['empty_stock'])
                    <details class="mt-2 small text-muted" id="empty-stock-summary">
                        <summary>Tidak ada stok tersisa di lokasi ini ({{ count($prep['empty_stock']) }} baris produk/lokasi)</summary>
                        <ul class="mb-0">@foreach($prep['empty_stock'] as $empty)<li>{{ $empty['receipt'] }} — {{ $empty['product'] }} — {{ $empty['location'] }}</li>@endforeach</ul>
                    </details>
                @endif
                @if($prep['settled'])
                    <details class="mt-2 small text-muted">
                        <summary>{{ count($prep['settled']) }} penjualan sudah ditagih atau sedang dalam dokumen lain (tidak perlu dipilih)</summary>
                        <ul class="mb-0">@foreach($prep['settled'] as $st)<li>{{ $st['receipt'] }} — {{ $st['product'] }} — {{ $st['document']['label'] ?? ('Penjualan ' . $st['sale_reference']) }}</li>@endforeach</ul>
                    </details>
                @endif

                <div class="mt-3">
                    <button type="button" class="btn btn-outline-secondary" @click="go(1)"><i class="bi bi-arrow-left"></i> Kembali</button>
                    <button type="button" class="btn btn-primary" @click="go(3)">Lanjut: Tinjau <i class="bi bi-arrow-right"></i></button>
                </div>
            </div>
        </div>

        {{-- ================= STEP 3 ================= --}}
        <div class="card border-0 shadow-sm mb-3" x-show="step === 3">
            <div class="card-body">
                <h5 class="mb-3">3. Tinjau dan simpan draft</h5>
                <div class="row mb-3">
                    <div class="col-md-6"><div class="alert alert-light border py-2 mb-2">Tagihan supplier: <strong>{{ $prep['billing_business'] }}</strong></div></div>
                    <div class="col-md-6"><div class="alert alert-light border py-2 mb-2">Pemilik stok yang dibeli:
                        <template x-for="o in owners()" :key="o.sid"><div><strong x-text="o.name"></strong> → <span x-text="o.dest"></span></div></template>
                        <template x-if="!owners().length"><span class="text-muted">—</span></template>
                    </div></div>
                </div>

                <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light"><tr><th>Dokumen penjualan / Lokasi</th><th>Jenis</th><th class="text-right">Jumlah</th><th>Serial</th><th class="text-right">Harga</th><th class="text-right">Pajak</th><th class="text-right">Total</th><th>Milik / tujuan</th></tr></thead>
                    @foreach($prep['tree'] as $reviewReceipt)
                        @php($receiptKeys = collect($reviewReceipt['products'])->flatMap(fn ($p) => array_merge(collect($p['unsold'])->flatMap(fn ($g) => $g['keys'])->all(), $p['sold']))->values()->all())
                        <tbody x-show="hasSelected(@js($receiptKeys))">
                            <tr class="table-primary"><th colspan="8">Penerimaan: {{ $reviewReceipt['receipt'] }}</th></tr>
                        </tbody>
                        @foreach($reviewReceipt['products'] as $reviewProduct)
                            @php($productKeys = array_merge(collect($reviewProduct['unsold'])->flatMap(fn ($g) => $g['keys'])->all(), $reviewProduct['sold']))
                            <tbody x-show="hasSelected(@js($productKeys))">
                                <tr class="table-light"><th colspan="8">Produk: {{ $reviewProduct['product'] }}</th></tr>
                            </tbody>
                            @foreach(['unsold' => 'Belum terjual', 'sold' => 'Terjual belum ditagih'] as $reviewKind => $reviewLabel)
                                @php($reviewKeys = $reviewKind === 'unsold' ? collect($reviewProduct['unsold'])->flatMap(fn ($g) => $g['keys'])->values()->all() : $reviewProduct['sold'])
                                @continue(! $reviewKeys)
                    <tbody x-show="hasSelected(@js($reviewKeys))">
                        <tr><th colspan="8" class="text-muted">{{ $reviewLabel }}</th></tr>
                        <template x-for="r in selectedRows().filter(row => @js($reviewKeys).includes(row.key))" :key="r.key">
                            <tr>
                                <td><span x-text="r.kind === 'unsold' ? r.location : (r.document ? r.document.label : (r.sale_reference ? 'Penjualan ' + r.sale_reference : '—'))"></span><span class="small text-muted d-block" x-show="r.document && r.document.pos_business" x-text="r.document && r.document.pos_business ? 'POS: ' + r.document.pos_business : ''"></span><span class="small text-muted d-block" x-text="r.document && r.document.selling_business ? 'Penjualan: ' + r.document.selling_business : ''"></span></td>
                                <td x-text="r.kind === 'sold' ? 'Terjual belum ditagih' : 'Belum terjual'"></td>
                                <td class="text-right"><span x-text="fmt(qtyOf(r.key))"></span>
                                    <template x-if="r.kind === 'unsold' && !r.serialized"><span class="small text-muted d-block" x-text="bucketText(r.key)"></span></template></td>
                                <td class="small"><template x-for="sn in r.serials.filter(sn => sel[r.key].serials.includes(String(sn.id)))" :key="sn.id"><div class="text-monospace" x-text="sn.label"></div></template><span x-show="!r.serialized">—</span></td>
                                <td class="text-right" x-text="money(r.unit_price)"></td>
                                <td class="text-right" x-text="money(r.unit_tax * qtyOf(r.key))"></td>
                                <td class="text-right" x-text="money(rowTotal(r))"></td>
                                <td class="small"><span x-text="r.kind === 'sold' ? '— (hanya tagihan)' : r.owner"></span> <span class="d-block text-muted" x-text="destName(r.owner_setting_id)"></span></td>
                            </tr>
                        </template>
                    </tbody>
                            @endforeach
                        @endforeach
                    @endforeach
                    <tbody x-show="!selectedRows().length"><tr><td colspan="8" class="text-center text-muted">Belum ada barang dipilih.</td></tr></tbody>
                    <tfoot>
                        <tr><th colspan="6" class="text-right">Subtotal belum terjual</th><th class="text-right" x-text="money(subtotal('unsold'))"></th><th></th></tr>
                        <tr><th colspan="6" class="text-right">Subtotal terjual belum ditagih</th><th class="text-right" x-text="money(subtotal('sold'))"></th><th></th></tr>
                        <tr><th colspan="6" class="text-right">Diskon dokumen</th><th class="text-right" x-text="'- ' + money(discountAmount())"></th><th></th></tr>
                        <tr><th colspan="6" class="text-right">Perkiraan utang ke supplier</th><th class="text-right" x-text="money(payable())"></th><th></th></tr>
                    </tfoot>
                </table>
                </div>
                <p class="small text-muted">Harga dari penerimaan titipan. Angka ini perkiraan; harga, pajak, dan ketersediaan dihitung ulang dan divalidasi server saat disimpan, diajukan, dan disetujui.</p>

                <div class="form-row mb-3">
                    <div class="col-md-3"><label>Tanggal</label><input type="date" {{ $n('date') }} class="form-control" value="{{ old('date', now()->toDateString()) }}"></div>
                    <div class="col-md-3"><label>No. Faktur Supplier</label><input {{ $n('invoice_metadata[supplier_invoice_number]') }} class="form-control" value="{{ old('invoice_metadata.supplier_invoice_number') }}"></div>
                    <div class="col-md-3"><label>Tanggal Faktur</label><input type="date" {{ $n('invoice_metadata[invoice_date]') }} class="form-control" value="{{ old('invoice_metadata.invoice_date', now()->toDateString()) }}"></div>
                    <div class="col-md-3"><label>Diskon Dokumen</label>
                        <div class="input-group">
                            <select {{ $n('pricing_intent[global_discount_type]') }} class="form-control" x-model="discount.type"><option value="fixed">Rp</option><option value="percentage">%</option></select>
                            <input type="number" step="0.01" min="0" {{ $n('pricing_intent[global_discount_value]') }} class="form-control" x-model.number="discount.value">
                        </div>
                    </div>
                    <div class="col-12 mt-2"><label>Catatan</label><textarea {{ $n('notes') }} class="form-control" rows="2">{{ old('notes') }}</textarea></div>
                </div>

                <button type="button" class="btn btn-outline-secondary" @click="go(2)"><i class="bi bi-arrow-left"></i> Kembali</button>
                @if($canSave)
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Draft</button>
                    <span class="small text-muted ml-2">Hanya membuat draft: belum ada cadangan, pergerakan stok, atau tagihan.</span>
                @elseif($mutationsEnabled)
                    <span class="small text-muted ml-2">Anda tidak memiliki izin membuat draft pembelian putus.</span>
                @endif
            </div>
        </div>
    </form>
    @endif
</div>
@endsection

@push('page_css')
    <style>[x-cloak]{display:none!important}</style>
@endpush

@push('page_scripts')
    @include('consignment::partials.ajax-select-scripts')
    <script>
        $(function () {
            const form = document.getElementById('buyout-populate-form');
            const supplier = $(form).find('[name="supplier_id"]');
            let previousSupplier = supplier.val() || '';
            supplier.on('change.buyoutPreparation', function () {
                const selectedSupplier = supplier.val() || '';
                if (selectedSupplier === previousSupplier) return;
                previousSupplier = selectedSupplier;
                document.getElementById('buyout-supplier-step').disabled = false;
                form.requestSubmit();
            });
        });

        function buyoutPrep(cfg) {
            const m = v => Math.round((parseFloat(v) || 0) * 1000);
            // Old input is re-indexed by server validation, so rows are matched by their carried row_key.
            const oldByKey = {};
            Object.entries(cfg.old || {}).forEach(([k, o]) => { if (o) oldByKey[o.row_key || k] = o; });
            const sel = {};
            Object.values(cfg.rows).forEach(r => {
                const o = oldByKey[r.key];
                sel[r.key] = {
                    on: !!o,
                    qty: o && !r.serialized ? (o.base_quantity ?? '') : '',
                    serials: o && Array.isArray(o.serial_ids) ? o.serial_ids.map(String) : [],
                };
            });
            const dest = {};
            Object.keys(cfg.holdings).forEach(sid => dest[sid] = cfg.destinations[sid] ?? '');

            return {
                step: cfg.startStep,
                rows: cfg.rows, sel, dest, error: '',
                discount: { type: cfg.oldDiscount.type || 'fixed', value: parseFloat(cfg.oldDiscount.value) || 0 },

                init() {
                    if (this.step > 1 && this.validateSetup()) this.step = 1;
                    else if (this.step > 2 && this.validateGoods()) this.step = 2;
                },

                setSerials(key, ids) { this.sel[key].serials = ids.map(String); },
                isOn(key) { return this.rows[key].serialized ? this.sel[key].serials.length > 0 : !!this.sel[key].on; },
                // Group state over units: each serial is one unit; a quantity row counts as one selectable unit.
                groupState(keys) {
                    let total = 0, picked = 0;
                    keys.forEach(k => {
                        const r = this.rows[k];
                        if (r.serialized) { total += r.serials.length; picked += this.sel[k].serials.length; }
                        else { total += 1; if (this.isOn(k) && this.qtyOf(k) > 0) picked += (m(this.qtyOf(k)) >= m(r.max) ? 1 : 0.5); }
                    });
                    return picked === 0 ? 'none' : (picked >= total ? 'all' : 'some');
                },
                groupCount(keys) {
                    const q = keys.reduce((a, k) => a + m(this.qtyOf(k)), 0) / 1000;
                    return q > 0 ? this.fmt(q) + ' dipilih' : '';
                },
                toggleGroup(keys, on) {
                    keys.forEach(k => { if (!on) { this.sel[k].on = false; this.sel[k].qty = ''; this.setSerials(k, []); } });
                    if (on) keys.forEach(k => this.fill(k));
                },
                // Select a row up to its own maximum and the shared pool / sold-source room left by other rows.
                fill(key) {
                    const r = this.rows[key], left = this.room(key);
                    if (left <= 0) return;
                    if (r.serialized) {
                        const keep = this.sel[key].serials, extra = r.serials.map(x => String(x.id)).filter(id => !keep.includes(id));
                        this.setSerials(key, keep.concat(extra).slice(0, Math.max(keep.length, Math.floor(left))));
                    } else { this.sel[key].on = true; this.sel[key].qty = left; }
                },
                qtyOf(key) {
                    const r = this.rows[key], s = this.sel[key];
                    if (r.serialized) return s.serials.length;
                    return s.on ? (parseFloat(s.qty) || 0) : 0;
                },
                toggled(key) {
                    const r = this.rows[key], s = this.sel[key];
                    if (!s.on) return;
                    const left = this.room(key);
                    if (r.serialized) {
                        if (!s.serials.length) this.setSerials(key, r.serials.slice(0, Math.floor(left)).map(x => x.id));
                    } else if (!(parseFloat(s.qty) > 0)) {
                        s.qty = left;
                    }
                },
                // Remaining shared room for a row (own row excluded): pool for unsold, sold source for sold.
                room(key) {
                    const r = this.rows[key];
                    let cap = m(r.max), used = 0;
                    Object.values(this.rows).forEach(o => {
                        if (o.key === key) return;
                        if (r.kind === 'unsold' && o.pool_key === r.pool_key) used += m(this.qtyOf(o.key));
                        if (r.kind === 'sold' && o.source_key === r.source_key) used += m(this.qtyOf(o.key));
                    });
                    const shared = r.kind === 'unsold'
                        ? m(cfg.pools[r.pool_key].taxed) + m(cfg.pools[r.pool_key].non_taxed)
                        : m(cfg.soldLimits[r.source_key]);
                    return Math.max(0, Math.min(cap, shared - used)) / 1000;
                },
                selectedRows() { return Object.values(this.rows).filter(r => this.isOn(r.key) && this.qtyOf(r.key) > 0); },
                hasSelected(keys) { return this.selectedRows().some(r => keys.includes(r.key)); },
                totalQty(kind) { return this.selectedRows().filter(r => r.kind === kind).reduce((a, r) => a + m(this.qtyOf(r.key)), 0) / 1000; },

                validateGoods() {
                    const chosen = Object.values(this.rows).filter(r => this.isOn(r.key));
                    if (!chosen.length) return 'Pilih minimal satu barang.';
                    const pools = {}, sources = {};
                    for (const r of chosen) {
                        const q = m(this.qtyOf(r.key));
                        const label = r.product + ' (' + r.receipt + ')';
                        if (q <= 0) return 'Isi jumlah untuk ' + label + ' atau hilangkan centangnya.';
                        if (q > m(r.max)) return 'Jumlah ' + label + ' melebihi yang tersedia (' + r.max + ').';
                        if (r.serialized && q % 1000 !== 0) return 'Jumlah barang serial harus bilangan bulat.';
                        if (r.kind === 'unsold') pools[r.pool_key] = (pools[r.pool_key] || 0) + q;
                        else sources[r.source_key] = (sources[r.source_key] || 0) + q;
                    }
                    for (const k in pools) if (pools[k] > m(cfg.pools[k].taxed) + m(cfg.pools[k].non_taxed)) return 'Total barang belum terjual di satu lokasi melebihi stok fisik yang tersedia.';
                    for (const k in sources) if (sources[k] > m(cfg.soldLimits[k])) return 'Total satu penjualan yang dipilih melebihi jumlah yang dapat ditagih.';
                    return '';
                },
                validateSetup() {
                    if (!cfg.supplierId) return 'Pilih supplier terlebih dahulu di langkah 1.';
                    for (const [sid, holding] of Object.entries(cfg.holdings)) {
                        const selected = parseInt(this.dest[sid], 10);
                        if (!(holding.destinations || []).some(d => parseInt(d.id, 10) === selected)) {
                            return 'Pilih lokasi tujuan untuk ' + holding.name + ' di langkah 1.';
                        }
                    }
                    return '';
                },
                validateDestinations() { return this.validateSetup(); },
                go(n) {
                    this.error = '';
                    if (n > this.step) {
                        this.error = this.validateSetup() || (n === 3 ? this.validateGoods() : '');
                        if (this.error) return;
                    }
                    this.step = n;
                },
                submit(e) {
                    this.error = this.validateSetup() || this.validateGoods();
                    if (this.error) { e.preventDefault(); return false; }
                    return true;
                },

                // Tax buckets, existing rule: per pool in receipt order, non-taxed first, then taxed (server derives authoritatively).
                buckets() {
                    const out = {};
                    Object.entries(cfg.poolOrder).forEach(([pk, keys]) => {
                        let nt = m(cfg.pools[pk].non_taxed);
                        keys.forEach(k => {
                            const q = m(this.qtyOf(k)), a = Math.max(0, Math.min(q, nt));
                            nt -= a; out[k] = { non_taxed: a / 1000, taxed: (q - a) / 1000 };
                        });
                    });
                    return out;
                },
                bucketText(key) { const b = this.buckets()[key]; return b ? ('non-pajak ' + this.fmt(b.non_taxed) + ' · pajak ' + this.fmt(b.taxed)) : ''; },
                serialLabels(r) {
                    const ids = this.sel[r.key].serials;
                    return r.serials.filter(s => ids.includes(String(s.id))).map(s => s.label).join(', ');
                },
                rowTotal(r) { const q = this.qtyOf(r.key); return Math.round((q * r.unit_price + q * r.unit_tax) * 100) / 100; },
                subtotal(kind) { return this.selectedRows().filter(r => r.kind === kind).reduce((a, r) => a + this.rowTotal(r), 0); },
                discountAmount() {
                    const sum = this.subtotal('sold') + this.subtotal('unsold'), v = parseFloat(this.discount.value) || 0;
                    return Math.min(sum, this.discount.type === 'percentage' ? Math.round(sum * v) / 100 : v);
                },
                payable() { return Math.max(0, this.subtotal('sold') + this.subtotal('unsold') - this.discountAmount()); },
                owners() {
                    const seen = {};
                    this.selectedRows().filter(r => r.kind === 'unsold').forEach(r => seen[r.owner_setting_id] = { sid: r.owner_setting_id, name: r.owner, dest: this.destName(r.owner_setting_id) });
                    return Object.values(seen);
                },
                destName(sid) {
                    const d = (cfg.holdings[sid]?.destinations || []).find(x => x.id === parseInt(this.dest[sid], 10));
                    return d ? d.name : '(lokasi tujuan belum dipilih)';
                },
                fmt(v) { return (Math.round((parseFloat(v) || 0) * 1000) / 1000).toLocaleString('id-ID'); },
                money(v) { return 'Rp ' + (Math.round((parseFloat(v) || 0) * 100) / 100).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            };
        }
    </script>
@endpush

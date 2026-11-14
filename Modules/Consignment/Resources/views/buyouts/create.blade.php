@extends('layouts.app')

@section('title', isset($buyout) ? 'Ubah Pembelian Putus ' . $buyout->reference : 'Persiapan Pembelian Putus Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.buyouts.index') }}">Pembelian Putus</a></li>
        <li class="breadcrumb-item active">{{ isset($buyout) ? 'Ubah ' . $buyout->reference : 'Persiapan' }}</li>
    </ol>
@endsection

@php
    $editing = isset($buyout);
    $canSave = $mutationsEnabled && \Illuminate\Support\Facades\Gate::allows($editing ? 'consignments.buyouts.edit' : 'consignments.buyouts.create');
    $savedInvoice = $editing ? ($buyout->invoice_metadata ?? []) : [];
    // Product intent as saved, or the product projection of a legacy per-line intent (conflicts need explicit entry).
    $savedIntent = $editing ? (\Modules\Consignment\Services\ConsignmentBuyoutPricingService::isProductIntent($buyout->pricing_intent) ? $buyout->pricing_intent : ($legacyIntent ?? [])) : [];
    $legacyConflicts = $legacyIntent['conflicts'] ?? [];
    $hasOld = (bool) old('lines');
    $inv = fn (string $k, $default = null) => old("invoice_metadata.{$k}", $savedInvoice[$k] ?? $default);
    $savedAttachments = $editing ? $buyout->getMedia(\Modules\Consignment\Entities\ConsignmentBuyout::ATTACHMENT_COLLECTION) : collect();
    // Field names exist only when a draft can actually be saved: otherwise the screen is inspection-only.
    $n = fn (string $name) => $canSave ? new \Illuminate\Support\HtmlString('name="' . e($name) . '"') : '';
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',') ?: '0';
    $oldLines = (array) old('lines', $savedLines ?? []);
    $oldIntent = $hasOld ? (array) old('pricing_intent', []) : $savedIntent;
    $prepData = [
        'supplierId' => $supplierId,
        'rows' => $prep['rows'] ?? [],
        'pools' => $prep['pools'] ?? [],
        'poolOrder' => $prep['pool_order'] ?? [],
        'soldLimits' => $prep['sold_limits'] ?? [],
        'holdings' => $prep['holdings'] ?? [],
        'destinations' => $initialDestinations,
        'old' => $oldLines,
        'oldDiscount' => ['type' => $oldIntent['global_discount_type'] ?? 'fixed', 'value' => $oldIntent['global_discount_value'] ?? 0],
        'terms' => (object) ($oldIntent['products'] ?? []),
        'taxIncluded' => (bool) filter_var($oldIntent['is_tax_included'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'isPkp' => (bool) ($isPkp ?? false),
        'previewUrl' => route('consignments.buyouts.preview'),
        'paymentTerms' => ($paymentTerms ?? collect())->mapWithKeys(fn ($t) => [$t->id => (int) $t->longevity]),
        'invoice' => [
            'invoice_date' => $inv('invoice_date', now()->toDateString()),
            'payment_term_id' => (string) ($inv('payment_term_id') ?? ''),
            'due_date' => $inv('due_date', ''),
            'due_date_manual' => (bool) filter_var($inv('due_date_manual', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'unavailable' => $unavailable ?? [],
        'canSave' => $canSave,
        'conflicts' => array_values($legacyConflicts),
        'taxRates' => (object) ($activeTaxes ?? collect())->mapWithKeys(fn ($t) => [$t->id => (float) $t->value])->all(),
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
                    @if($editing)
                        <div class="form-control-plaintext font-weight-bold">{{ $selectedSupplierText }}</div>
                        <div class="small text-muted">Supplier dokumen tidak dapat diganti saat mengubah draft.</div>
                    @else
                    @include('consignment::partials.ajax-select', [
                        'name' => 'supplier_id', 'url' => route('consignments.select.suppliers'), 'selectedId' => $supplierId ?: null,
                        'selectedText' => $selectedSupplierText ?? null, 'placeholder' => '-- Pilih Supplier --',
                    ])
                    @endif
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
    <form method="POST" enctype="multipart/form-data" id="buyout-draft-form" @submit="submit($event)"
          action="{{ $canSave ? ($editing ? route('consignments.buyouts.update', $buyout->id) : route('consignments.buyouts.store')) : '#' }}">
        @if($canSave)
            @csrf <input type="hidden" name="supplier_id" value="{{ $supplierId }}">
            @if($editing) @method('PUT') <input type="hidden" name="revision" value="{{ $buyout->revision }}"> @endif
        @endif

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

        <div hidden x-effect="schedulePreview(JSON.stringify(previewPayload()))"></div>
        {{-- ================= STEP 3 ================= --}}
        <div class="card border-0 shadow-sm mb-3" x-show="step === 3" id="buyout-step3">
            <div class="card-body">
                <h5 class="mb-3">3. Faktur supplier dan simpan draft</h5>
                <div class="row mb-3">
                    <div class="col-md-6"><div class="alert alert-light border py-2 mb-2">Tagihan supplier: <strong>{{ $prep['billing_business'] }}</strong></div></div>
                    <div class="col-md-6"><div class="alert alert-light border py-2 mb-2">Pemilik stok yang dibeli:
                        <template x-for="o in owners()" :key="o.sid"><div><strong x-text="o.name"></strong> → <span x-text="o.dest"></span></div></template>
                        <template x-if="!owners().length"><span class="text-muted">—</span></template>
                    </div></div>
                </div>

                @if(! empty($unavailable))
                    <div class="alert alert-warning" id="buyout-unavailable">
                        <strong>Sebagian barang pada draft ini tidak lagi tersedia</strong> dan tidak dipilih ulang secara otomatis:
                        <ul class="mb-1">@foreach($unavailable as $u)<li>{{ $u['receipt'] ?? '—' }} — {{ $u['product'] ?? '—' }} ({{ $u['kind'] === 'sold' ? 'terjual belum ditagih' : 'belum terjual' }}, {{ $fmt($u['quantity']) }})</li>@endforeach</ul>
                        <label class="mb-0"><input type="checkbox" x-model="ackUnavailable"> Saya memahami barang tersebut tidak lagi menjadi bagian draft saat disimpan.</label>
                    </div>
                @endif

                @if($isPkp ?? false)
                    <div class="custom-control custom-switch mb-2">
                        <input type="hidden" {{ $n('pricing_intent[is_tax_included]') }} :value="taxIncluded ? 1 : 0">
                        <input type="checkbox" class="custom-control-input" id="buyout-tax-included" x-model="taxIncluded">
                        <label class="custom-control-label" for="buyout-tax-included">Harga termasuk pajak</label>
                    </div>
                @endif

                <div class="table-responsive">
                <table class="table table-bordered table-sm" id="buyout-product-table">
                    <thead class="thead-light"><tr>
                        <th>Produk</th><th class="text-right">Jumlah</th><th style="min-width:9rem">Harga satuan</th><th style="min-width:11rem">Diskon / unit</th>
                        @if($isPkp ?? false)<th style="min-width:9rem">Pajak</th>@endif
                        <th style="min-width:10rem">Total baris</th>
                    </tr></thead>
                    <template x-for="p in products()" :key="p.product_id">
                        <tbody class="buyout-product-row" :data-product="p.product_id">
                            <tr>
                                <td>
                                    <button type="button" class="btn btn-link btn-sm p-0 mr-1" @click="expanded[p.product_id] = !expanded[p.product_id]" :aria-expanded="!!expanded[p.product_id]">
                                        <i class="bi" :class="expanded[p.product_id] ? 'bi-chevron-down' : 'bi-chevron-right'"></i></button>
                                    <span class="font-weight-bold" x-text="p.name"></span>
                                    <span class="d-block small text-muted" x-text="p.rows.length + ' sumber · ' + p.receipts.join(', ')"></span>
                                    <span class="badge badge-warning buyout-needs-price" x-show="needsPrice(p)" x-text="conflicts.includes(p.product_id) ? 'Harga tersimpan berbeda — isi satu harga atau total' : 'Harga penerimaan berbeda — isi harga atau total'"></span>
                                    <span class="badge badge-warning" x-show="serverProduct(p.product_id) && serverProduct(p.product_id).needs_tax">Pajak penerimaan berbeda — pilih pajak</span>
                                </td>
                                <td class="text-right" x-text="fmt(p.qty)"></td>
                                <td>
                                    <input type="text" inputmode="decimal" class="form-control form-control-sm buyout-unit-price nominal-field"
                                           :class="{ 'is-invalid': invalid['price' + p.product_id] !== undefined }"
                                           :value="fieldText('price' + p.product_id, term(p.product_id).unit_price, 6, 2)"
                                           @focus="focusField('price' + p.product_id, term(p.product_id).unit_price, 6)"
                                           @blur="blurField('price' + p.product_id, $event.target.value, 6, term(p.product_id).unit_price, v => editTerm(p.product_id, 'unit_price', v))"
                                           :placeholder="defaultPrice(p) !== null ? nominal.format(defaultPrice(p), 2) : 'Wajib diisi'">
                                    <input type="hidden" :name="canSave && term(p.product_id).unit_price !== '' ? 'pricing_intent[products][' + p.product_id + '][unit_price]' : null" :value="term(p.product_id).unit_price">
                                    <span class="small text-muted d-block" x-show="term(p.product_id).row_total_override !== '' && serverProduct(p.product_id)"
                                          x-text="'Harga hasil total: ' + money(serverProduct(p.product_id) ? serverProduct(p.product_id).accepted.unit_price : 0)"></span>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <select class="form-control" :name="canSave ? 'pricing_intent[products][' + p.product_id + '][discount_type]' : null"
                                                :value="term(p.product_id).discount_type" @change="editTerm(p.product_id, 'discount_type', $event.target.value)">
                                            <option value="fixed">Rp</option><option value="percentage">%</option></select>
                                        <input type="text" inputmode="decimal" class="form-control nominal-field"
                                               :class="{ 'is-invalid': invalid['disc' + p.product_id] !== undefined }"
                                               :value="fieldText('disc' + p.product_id, term(p.product_id).discount_value, 2, 2)"
                                               @focus="focusField('disc' + p.product_id, term(p.product_id).discount_value, 2)"
                                               @blur="blurField('disc' + p.product_id, $event.target.value, 2, term(p.product_id).discount_value, v => editTerm(p.product_id, 'discount_value', v))">
                                        <input type="hidden" :name="canSave && term(p.product_id).discount_value !== '' ? 'pricing_intent[products][' + p.product_id + '][discount_value]' : null" :value="term(p.product_id).discount_value">
                                    </div>
                                </td>
                                @if($isPkp ?? false)
                                <td>
                                    <select class="form-control form-control-sm" :name="canSave && term(p.product_id).tax_id !== '' ? 'pricing_intent[products][' + p.product_id + '][tax_id]' : null"
                                            :value="term(p.product_id).tax_id" @change="editTerm(p.product_id, 'tax_id', $event.target.value)">
                                        <option value="">Ikuti penerimaan</option>
                                        <option value="{{ \Modules\Consignment\Services\ConsignmentBuyoutPricingService::NO_TAX }}">Tanpa pajak</option>
                                        @foreach($activeTaxes ?? [] as $tax)<option value="{{ $tax->id }}">{{ $tax->name }} ({{ rtrim(rtrim(number_format($tax->value, 2, '.', ''), '0'), '.') }}%)</option>@endforeach
                                    </select>
                                </td>
                                @endif
                                <td>
                                    <input type="text" inputmode="decimal" class="form-control form-control-sm buyout-row-total nominal-field"
                                           :class="{ 'border-primary': term(p.product_id).row_total_override !== '', 'is-invalid': invalid['total' + p.product_id] !== undefined }"
                                           :value="fieldText('total' + p.product_id, rowTotal(p.product_id), 2, 2)"
                                           @focus="focusField('total' + p.product_id, rowTotal(p.product_id), 2)"
                                           @blur="blurField('total' + p.product_id, $event.target.value, 2, rowTotal(p.product_id), v => editTotal(p.product_id, v))">
                                    <input type="hidden" :name="canSave && term(p.product_id).row_total_override !== '' ? 'pricing_intent[products][' + p.product_id + '][row_total_override]' : null" :value="term(p.product_id).row_total_override">
                                    <span class="small text-muted" x-show="term(p.product_id).row_total_override !== ''">total manual</span>
                                </td>
                            </tr>
                            <tr x-show="expanded[p.product_id]" class="buyout-product-sources">
                                <td colspan="{{ ($isPkp ?? false) ? 6 : 5 }}" class="bg-light">
                                    <table class="table table-sm mb-0 small">
                                        <thead><tr><th>Penerimaan</th><th>Jenis</th><th>Dokumen / Lokasi</th><th class="text-right">Jumlah</th><th>Serial</th><th class="text-right">Harga asli</th><th>Milik / tujuan</th></tr></thead>
                                        <tbody>
                                            <template x-for="r in p.rows" :key="r.key">
                                                <tr>
                                                    <td x-text="r.receipt"></td>
                                                    <td x-text="r.kind === 'sold' ? 'Terjual belum ditagih' : 'Belum terjual'"></td>
                                                    <td x-text="r.kind === 'unsold' ? r.location : (r.document ? r.document.label : (r.sale_reference ? 'Penjualan ' + r.sale_reference : '—'))"></td>
                                                    <td class="text-right"><span x-text="fmt(qtyOf(r.key))"></span>
                                                        <template x-if="r.kind === 'unsold' && !r.serialized"><span class="d-block text-muted" x-text="bucketText(r.key)"></span></template></td>
                                                    <td><span x-text="serialLabels(r) || '—'"></span></td>
                                                    <td class="text-right" x-text="money(r.unit_price)"></td>
                                                    <td><span x-text="r.kind === 'sold' ? '— (hanya tagihan)' : r.owner"></span> <span class="d-block text-muted" x-text="r.kind === 'sold' ? '' : destName(r.owner_setting_id)"></span></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    </template>
                    <tbody x-show="!products().length"><tr><td colspan="6" class="text-center text-muted">Belum ada barang dipilih.</td></tr></tbody>
                    <tfoot>
                        <tr><th colspan="{{ ($isPkp ?? false) ? 5 : 4 }}" class="text-right">Total baris</th><th class="text-right" x-text="money(preview ? preview.totals.gross_total : 0)"></th></tr>
                        @if($isPkp ?? false)<tr><th colspan="5" class="text-right">Termasuk pajak</th><th class="text-right" x-text="money(preview ? preview.totals.tax_amount : 0)"></th></tr>@endif
                        <tr><th colspan="{{ ($isPkp ?? false) ? 5 : 4 }}" class="text-right align-middle">Diskon dokumen
                                <div class="d-inline-flex ml-2" style="max-width:14rem">
                                    <select {{ $n('pricing_intent[global_discount_type]') }} class="form-control form-control-sm" x-model="discount.type"><option value="fixed">Rp</option><option value="percentage">%</option></select>
                                    <input type="text" inputmode="decimal" class="form-control form-control-sm nominal-field" id="buyout-global-discount"
                                           :class="{ 'is-invalid': invalid.global !== undefined }"
                                           :value="fieldText('global', discount.value, 2, 2)" @focus="focusField('global', discount.value, 2)"
                                           @blur="blurField('global', $event.target.value, 2, discount.value, v => discount.value = v === '' ? '0' : v)">
                                    <input type="hidden" {{ $n('pricing_intent[global_discount_value]') }} :value="discount.value">
                                </div></th>
                            <th class="text-right" x-text="'- ' + money(preview ? preview.totals.global_discount_amount : 0)"></th></tr>
                        <tr><th colspan="{{ ($isPkp ?? false) ? 5 : 4 }}" class="text-right">Utang ke supplier</th><th class="text-right" id="buyout-payable" x-text="money(preview ? preview.totals.total_amount : 0)"></th></tr>
                    </tfoot>
                </table>
                </div>
                <div class="small text-muted mb-2" x-show="previewLoading">Menghitung ulang…</div>
                <div class="alert alert-warning py-2" x-show="preview && preview.blockers.length" id="buyout-preview-blockers">
                    <template x-for="b in (preview ? preview.blockers : [])"><div x-text="b"></div></template>
                </div>
                <p class="small text-muted">Satu harga, diskon, dan pajak per produk untuk seluruh sumber. Harga kosong memakai harga penerimaan bila semua sumber sama. Mengubah total baris menghitung ulang harga satuan; mengubah harga, diskon, atau pajak menghapus total manual. Angka dihitung oleh server.</p>

                <h6 class="mt-3">Informasi faktur</h6>
                <div class="form-row mb-3">
                    <div class="col-md-3"><label>No. Faktur Supplier</label><input {{ $n('invoice_metadata[supplier_invoice_number]') }} class="form-control" value="{{ $inv('supplier_invoice_number') }}"></div>
                    <div class="col-md-3"><label>Tanggal Faktur <span class="text-danger">*</span></label>
                        <input type="date" {{ $n('invoice_metadata[invoice_date]') }} class="form-control" x-model="invoice.invoice_date" @change="recomputeDue()" required></div>
                    <div class="col-md-3"><label>No. Faktur Pajak</label><input {{ $n('invoice_metadata[tax_ref_no]') }} class="form-control" value="{{ $inv('tax_ref_no') }}"></div>
                    <div class="col-md-3 mt-2"><label>Syarat Pembayaran</label>
                        <select {{ $n('invoice_metadata[payment_term_id]') }} class="form-control" x-model="invoice.payment_term_id" @change="recomputeDue()">
                            <option value="">— Tanpa termin —</option>
                            @foreach($paymentTerms ?? [] as $term)<option value="{{ $term->id }}">{{ $term->name }} ({{ $term->longevity }} hari)</option>@endforeach
                        </select></div>
                    <div class="col-md-3 mt-2"><label>Jatuh Tempo</label>
                        <input type="date" {{ $n('invoice_metadata[due_date]') }} class="form-control" x-model="invoice.due_date" @input="invoice.due_date_manual = true" id="buyout-due-date">
                        <input type="hidden" {{ $n('invoice_metadata[due_date_manual]') }} :value="invoice.due_date_manual ? 1 : 0">
                        <span class="small text-muted" x-show="invoice.due_date_manual">diubah manual · <a href="#" @click.prevent="invoice.due_date_manual = false; recomputeDue()">ikuti termin</a></span></div>
                    <div class="col-md-6 mt-2"><label>Catatan</label><textarea {{ $n('notes') }} class="form-control" rows="2">{{ old('notes', $editing ? $buyout->notes : '') }}</textarea></div>
                </div>

                <h6>Lampiran faktur</h6>
                <div class="mb-3">
                    @foreach($savedAttachments as $media)
                        <label class="d-block small mb-1"><input type="checkbox" {{ $n('keep_attachments[]') }} value="{{ $media->id }}" {{ in_array($media->id, (array) old('keep_attachments', $hasOld ? [] : $savedAttachments->pluck('id')->all())) ? 'checked' : '' }}>
                            Simpan {{ $media->getCustomProperty('original_name') ?: $media->file_name }}</label>
                    @endforeach
                    <input type="file" {{ $n('attachments[]') }} class="form-control-file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp">
                    <span class="small text-muted d-block">PDF/JPG/PNG/WEBP, maks. 10 MB per file, maks. 10 lampiran.</span>
                    @if($hasOld)<span class="small text-warning d-block" id="buyout-reselect-attachments">Penyimpanan sebelumnya gagal: pilih ulang lampiran baru (berkas tidak dapat dipulihkan oleh browser).</span>@endif
                    @error('attachments')<span class="small text-danger d-block">{{ $message }}</span>@enderror
                    @error('attachments.*')<span class="small text-danger d-block">{{ $message }}</span>@enderror
                </div>
                @if($errors->any())
                    <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
                @endif

                <button type="button" class="btn btn-outline-secondary" @click="go(2)"><i class="bi bi-arrow-left"></i> Kembali</button>
                @if($canSave)
                    <button type="submit" class="btn btn-primary" id="buyout-save"><i class="bi bi-save"></i> Simpan Draft</button>
                    <span class="small text-muted ml-2">Hanya {{ $editing ? 'merevisi' : 'membuat' }} draft: belum ada cadangan, pergerakan stok, atau tagihan.</span>
                @elseif($mutationsEnabled)
                    <span class="small text-muted ml-2">Anda tidak memiliki izin {{ $editing ? 'mengubah' : 'membuat' }} draft pembelian putus.</span>
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
    @include('consignment::partials.nominal-format-scripts')
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

            const blankTerm = () => ({ unit_price: '', discount_type: 'fixed', discount_value: '', tax_id: '', row_total_override: '' });
            const terms = {};
            Object.entries(cfg.terms || {}).forEach(([pid, t]) => {
                const b = blankTerm();
                Object.keys(b).forEach(k => { if (t && t[k] !== undefined && t[k] !== null) b[k] = String(t[k]); });
                terms[pid] = b;
            });

            return {
                step: cfg.startStep,
                rows: cfg.rows, sel, dest, error: '',
                canSave: !!cfg.canSave,
                conflicts: (cfg.conflicts || []).map(Number),
                discount: { type: cfg.oldDiscount.type || 'fixed', value: cfg.oldDiscount.value ?? 0 },
                terms, expanded: {}, taxIncluded: cfg.taxIncluded,
                invoice: Object.assign({}, cfg.invoice),
                ackUnavailable: !(cfg.unavailable || []).length,
                nominal: window.consignmentNominal, invalid: {}, focused: null, focusText: null,
                preview: null, previewLoading: false, previewSeq: 0, previewTimer: null, lastPreviewKey: '',

                // ---- tab 3: one financial row per product -------------------------------------------------
                products() {
                    const by = {};
                    this.selectedRows().forEach(r => {
                        const g = by[r.product_id] ??= { product_id: r.product_id, name: r.product, qty: 0, rows: [], receipts: [] };
                        g.qty = (m(g.qty) + m(this.qtyOf(r.key))) / 1000;
                        g.rows.push(r);
                        if (r.receipt && !g.receipts.includes(r.receipt)) g.receipts.push(r.receipt);
                    });
                    return Object.values(by).sort((a, b) => a.product_id - b.product_id);
                },
                term(pid) { return this.terms[pid] || blankTerm(); },
                // ---- nominal fields (same rules as consignment billing): formatted when idle, raw while editing,
                // canonical value committed on blur; unparsable text stays visible, flagged, and blocks saving.
                fieldText(key, canonical, precision, display) {
                    if (this.invalid[key] !== undefined) return this.invalid[key];
                    return this.focused === key ? this.editText(canonical, precision) : this.nominal.format(canonical, display);
                },
                // Editing text uses the Indonesian decimal comma ("1,234"), so re-parsing it can never read a decimal
                // as thousands grouping.
                editText(canonical, precision) { return this.nominal.raw(canonical, precision).replace('.', ','); },
                focusField(key, canonical, precision) {
                    if (this.invalid[key] !== undefined) return;
                    this.focused = key;
                    this.focusText = this.editText(canonical, precision);
                },
                blurField(key, text, precision, current, commit) {
                    // Untouched since focus: keep the exact canonical value (no re-parse, no rounding, no edit).
                    if (this.focused === key && text === this.focusText && this.invalid[key] === undefined) { this.focused = null; return; }
                    const canonical = this.nominal.parse(text, precision);
                    if (canonical === null) { this.invalid[key] = text; this.focused = null; return; }
                    delete this.invalid[key];
                    this.focused = null;
                    const same = canonical === '' ? String(current ?? '') === '' : (String(current ?? '') !== '' && parseFloat(current) === parseFloat(canonical));
                    if (!same) commit(canonical);
                },
                rowTotal(pid) {
                    const t = this.term(pid), sp = this.serverProduct(pid);
                    return t.row_total_override !== '' ? t.row_total_override : (sp ? String(sp.accepted.total) : '');
                },
                ensureTerm(pid) { if (!this.terms[pid]) this.terms[pid] = blankTerm(); return this.terms[pid]; },
                // Price, discount or tax edits clear a manual total; a total edit makes it authoritative.
                editTerm(pid, key, value) {
                    const t = this.ensureTerm(pid);
                    // Leaving a manual total: first carry its derived unit price into the price field, so a following
                    // discount or tax edit applies to the agreed amount rather than the stale pre-override price.
                    if (key !== 'unit_price' && t.row_total_override !== '') t.unit_price = Number(this.derivedUnitPrice(pid)).toFixed(6);
                    t[key] = value;
                    t.row_total_override = '';
                },
                editTotal(pid, value) { this.ensureTerm(pid).row_total_override = value; },
                // Unit price implied by the manual total: the server's back-solved value when the preview reflects the
                // current inputs, otherwise the same back-solve locally (tax-inclusive or untaxed basis).
                derivedUnitPrice(pid) {
                    const t = this.term(pid), sp = this.serverProduct(pid);
                    if (sp && this.previewCurrent()) return sp.accepted.unit_price;
                    const p = this.products().find(x => x.product_id === pid), qty = p ? p.qty : 0;
                    if (!(qty > 0)) return t.unit_price;
                    // Tax-exclusive PKP prices are net of tax: remove the row's tax from the total before dividing.
                    const rate = cfg.isPkp && !this.taxIncluded ? this.taxRate(pid) : 0;
                    const eff = (parseFloat(t.row_total_override) || 0) / (1 + rate / 100) / qty, d = parseFloat(t.discount_value) || 0;
                    const unit = t.discount_type === 'percentage' ? (d >= 100 ? 0 : eff / (1 - d / 100)) : eff + d;
                    return Math.round(unit * 1e6) / 1e6;
                },
                // Rate of the row's current tax choice: explicit "no tax", an explicit active tax, else the receipt tax
                // rate reported by the server for this product.
                taxRate(pid) {
                    const t = this.term(pid), sp = this.serverProduct(pid);
                    if (t.tax_id === '{{ \Modules\Consignment\Services\ConsignmentBuyoutPricingService::NO_TAX }}') return 0;
                    if (t.tax_id !== '') return cfg.taxRates[t.tax_id] ?? 0;
                    // "Ikuti penerimaan": the receipt's own rate, never the rate of an earlier invoice tax choice.
                    return sp ? (parseFloat(sp.default_tax_rate) || 0) : 0;
                },
                previewCurrent() { return !this.previewLoading && this.preview && this.preview.key === this.lastPreviewKey; },
                serverProduct(pid) { return this.preview ? (this.preview.products.find(p => p.product_id === pid) || null) : null; },
                defaultPrice(p) {
                    const sp = this.serverProduct(p.product_id);
                    if (sp) return sp.default_unit_price;
                    const prices = [...new Set(p.rows.map(r => Number(r.unit_price).toFixed(6)))];
                    return prices.length === 1 ? parseFloat(prices[0]) : null;
                },
                needsPrice(p) {
                    const t = this.term(p.product_id);
                    const conflict = this.conflicts.includes(p.product_id);   // reopened legacy draft with differing saved terms
                    return (conflict || this.defaultPrice(p) === null) && t.unit_price === '' && t.row_total_override === '';
                },
                intentPayload() {
                    const products = {};
                    this.products().forEach(p => {
                        const t = this.term(p.product_id), out = {};
                        Object.keys(t).forEach(k => { if (t[k] !== '' && t[k] !== null) out[k] = t[k]; });
                        if (!cfg.isPkp) delete out.tax_id;
                        products[p.product_id] = out;
                    });
                    return { version: 2, products, global_discount_type: this.discount.type, global_discount_value: this.discount.value || 0, is_tax_included: this.taxIncluded ? 1 : 0 };
                },
                previewPayload() {
                    return {
                        supplier_id: cfg.supplierId,
                        lines: this.selectedRows().map(r => ({ line_type: r.fields.line_type, consignment_receiving_detail_id: r.fields.consignment_receiving_detail_id, base_quantity: this.qtyOf(r.key) })),
                        pricing_intent: this.intentPayload(),
                    };
                },
                // Debounced authoritative server preview; only the response to the latest request is applied.
                schedulePreview(key) {
                    if (key === this.lastPreviewKey) return;
                    this.lastPreviewKey = key;
                    clearTimeout(this.previewTimer);
                    // Invalidate immediately: any in-flight response now belongs to superseded inputs.
                    const seq = ++this.previewSeq;
                    if (!this.selectedRows().length) { this.preview = null; this.previewLoading = false; return; }
                    this.previewLoading = true;
                    this.previewTimer = setTimeout(() => this.runPreview(key, seq), 250);
                },
                async runPreview(key, seq) {
                    if (seq !== this.previewSeq) return;
                    try {
                        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const res = await fetch(cfg.previewUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                            body: JSON.stringify(Object.assign(JSON.parse(key), { seq })) });
                        const data = await res.json();
                        if (seq !== this.previewSeq) return;            // a newer input superseded this response
                        if (res.ok) data.key = key;
                        this.preview = res.ok ? data : { valid: false, blockers: [data.message || 'Pratinjau gagal.'], products: [], totals: { gross_total: 0, tax_amount: 0, global_discount_amount: 0, total_amount: 0 } };
                    } catch (e) {
                        if (seq === this.previewSeq) this.preview = null;
                    } finally {
                        if (seq === this.previewSeq) this.previewLoading = false;
                    }
                },
                recomputeDue() {
                    if (this.invoice.due_date_manual) return;
                    const days = cfg.paymentTerms[this.invoice.payment_term_id];
                    if (days === undefined || !this.invoice.invoice_date) return;
                    const d = new Date(this.invoice.invoice_date + 'T00:00:00');
                    d.setDate(d.getDate() + days);
                    this.invoice.due_date = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                },

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
                    this.error = this.validateSetup() || this.validateGoods()
                        || (Object.keys(this.invalid).length ? 'Perbaiki nilai nominal yang tidak valid (format contoh: 1.250.000,50).' : '')
                        || (!this.ackUnavailable ? 'Konfirmasi barang draft yang tidak lagi tersedia sebelum menyimpan.' : '')
                        || (this.products().some(p => this.needsPrice(p)) ? 'Isi harga satuan atau total baris untuk produk dengan harga penerimaan berbeda.' : '')
                        || (this.preview && !this.previewLoading && !this.preview.valid ? 'Periksa kembali harga faktur: ' + this.preview.blockers.join(' ') : '');
                    if (this.error) { e.preventDefault(); window.scrollTo(0, 0); return false; }
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

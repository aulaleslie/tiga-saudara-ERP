@php
    use Modules\Adjustment\Entities\Transfer;
    $destinations = $workspace['destinations'];
    $rowIndex = 0;
@endphp
@extends('layouts.app')

@section('title', 'Alokasi & Persetujuan Transfer Stok')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfer Stok</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.show', $transfer) }}">{{ $transfer->document_number }}</a></li>
        <li class="breadcrumb-item active">Alokasi &amp; Persetujuan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid v3-approval" id="v3-approval-root">
        @include('utils.alerts')
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-1">Alokasi &amp; Persetujuan — {{ $transfer->document_number }}</h5>
                <p class="text-muted mb-3">
                    Kondisi: <strong>{{ $transfer->stock_condition === Transfer::CONDITION_BREAKAGE ? 'Barang Rusak' : 'Barang Baik' }}</strong>
                    · Revisi pengajuan #{{ $workspace['request_revision_number'] }}
                    · Revisi alokasi #{{ $workspace['configuration_revision'] }}.
                    Simpan Progres tidak mencadangkan stok; stok diperiksa ulang saat persetujuan.
                </p>

                @if($summary)
                    <div class="d-flex flex-wrap align-items-center mb-3">
                        <button type="button" class="btn btn-outline-success mr-2" id="v3-open-summary">Lihat Ringkasan Persetujuan</button>
                        <small class="text-muted">Alokasi telah disimpan. Periksa ringkasan, lalu konfirmasi untuk menyetujui dan mengirim.</small>
                    </div>

                    {{-- Shown when the modal cannot be opened (script/plugin unavailable), and without JavaScript. --}}
                    <section id="v3-summary-fallback" class="border border-success rounded p-3 mb-3" tabindex="-1" role="region" aria-labelledby="v3-summary-fallback-title" hidden>
                        <div class="alert alert-warning" role="alert">
                            @if($summary['errors'] === [])
                                Jendela ringkasan tidak dapat dibuka. Periksa ringkasan di bawah ini, lalu klik <strong>Konfirmasi Setujui dan Kirim</strong>. Transfer belum disetujui atau dikirim sampai Anda mengonfirmasi.
                            @else
                                Jendela ringkasan tidak dapat dibuka. Alokasi di bawah ini belum dapat disetujui; klik <strong>Batal</strong> untuk kembali memperbaiki alokasi.
                            @endif
                        </div>
                        <h6 id="v3-summary-fallback-title">Ringkasan Persetujuan &amp; Pengiriman</h6>
                        @include('adjustment::transfers.v3.partials.summary-body')
                        <div class="d-flex flex-wrap mt-3">
                            <a href="{{ route('transfers.v3.approval', $transfer) }}" class="btn btn-secondary mr-2 mb-2">Batal</a>
                            @if($summary['errors'] === [])
                                @include('adjustment::transfers.v3.partials.summary-confirm')
                            @endif
                        </div>
                    </section>
                    <noscript><style>#v3-summary-fallback[hidden] { display: block !important; }</style></noscript>
                @endif

                <form action="{{ route('transfers.v3.approval.progress', $transfer) }}" method="POST" id="v3-allocation-form">
                    @csrf
                    <input type="hidden" name="request_revision_id" value="{{ $workspace['request_revision_id'] }}">
                    <input type="hidden" name="configuration_revision" value="{{ $workspace['configuration_revision'] }}">

                    @foreach($workspace['items'] as $item)
                        <div class="border rounded p-3 mb-3" data-product="{{ $item['product_id'] }}">
                            @unless($item['serialized'])
                                {{-- Built from sourceOptions(): tax/non_tax exist only for approvers with Lihat Stok Sistem. --}}
                                @php
                                    $stockMap = [];
                                    foreach ($item['sources'] as $source) {
                                        $stockMap[(string) $source['location_id']] = array_intersect_key($source, array_flip(['available', 'tax', 'non_tax']));
                                    }
                                @endphp
                                <script type="application/json" class="v3-stock-map">{!! json_encode((object) $stockMap, JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
                            @endunless
                            <h6 class="mb-2">
                                {{ $item['product_name'] }} <small class="text-muted">{{ $item['product_code'] }}</small>
                                <span class="badge badge-primary ml-2">Diminta: {{ $item['quantity'] }}</span>
                                @if($item['serialized'])<span class="badge badge-light border">Bernomor seri</span>@endif
                            </h6>

                            @if($item['serialized'])
                                <div class="v3-table-scroll">
                                <table class="table table-sm table-bordered mb-0 v3-alloc-table v3-serial-table">
                                    <colgroup>
                                        <col class="v3-col-source">
                                        <col class="v3-col-serials">
                                        <col class="v3-col-count">
                                        <col class="v3-col-destination">
                                    </colgroup>
                                    <thead>
                                    <tr>
                                        <th>Lokasi Sumber</th>
                                        <th>Nomor Seri</th>
                                        <th class="text-center">Jumlah</th>
                                        <th>Lokasi Tujuan</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($item['groups'] as $group)
                                        <tr class="{{ $group['source_location_id'] ? '' : 'table-danger' }}">
                                            <td class="v3-wrap">{{ $group['source_label'] ?? 'Tidak tersedia — tolak dokumen untuk dikoreksi' }}</td>
                                            <td class="v3-wrap">
                                                @foreach($group['serials'] as $serial)
                                                    <span class="badge badge-light border v3-serial">{{ $serial['serial_number'] }}</span>
                                                @endforeach
                                            </td>
                                            <td class="text-center">{{ count($group['serials']) }}</td>
                                            <td>
                                                @if($group['source_location_id'])
                                                    <select class="form-control v3-searchable" name="serial_destinations[{{ $item['product_id'] }}:{{ $group['source_location_id'] }}]">
                                                        <option value="">— Pilih lokasi tujuan —</option>
                                                        @foreach($destinations as $destination)
                                                            @continue($destination['id'] === $group['source_location_id'])
                                                            <option value="{{ $destination['id'] }}" @selected($group['destination_location_id'] === $destination['id'])>{{ $destination['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                                </div>
                            @else
                                <div class="v3-table-scroll mb-2">
                                <table class="table table-sm table-bordered mb-0 v3-alloc-table v3-bulk-table">
                                    <colgroup>
                                        <col class="v3-col-source">
                                        <col class="v3-col-destination">
                                        <col class="v3-col-quantity">
                                        <col class="v3-col-remove">
                                    </colgroup>
                                    <thead>
                                    <tr>
                                        <th>Lokasi Sumber</th>
                                        <th>Lokasi Tujuan</th>
                                        <th>Jumlah</th>
                                        <th><span class="sr-only">Hapus</span></th>
                                    </tr>
                                    </thead>
                                    <tbody class="v3-rows">
                                    @php $rows = $item['rows'] !== [] ? $item['rows'] : [['source_location_id' => null, 'destination_location_id' => null, 'quantity' => null]]; @endphp
                                    @foreach($rows as $row)
                                        <tr>
                                            <td>
                                                <input type="hidden" name="rows[{{ $rowIndex }}][product_id]" value="{{ $item['product_id'] }}">
                                                <select class="form-control v3-searchable v3-source-select" name="rows[{{ $rowIndex }}][source_location_id]">
                                                    <option value="">— Pilih lokasi sumber —</option>
                                                    @foreach($item['sources'] as $source)
                                                        <option value="{{ $source['location_id'] }}" @selected($row['source_location_id'] === $source['location_id'])>{{ $source['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                @php $selectedSource = collect($item['sources'])->firstWhere('location_id', $row['source_location_id']); @endphp
                                                <small class="form-text text-muted v3-stock-info" aria-live="polite">@if($selectedSource)Stok tersedia: {{ $selectedSource['available'] }}@isset($selectedSource['tax']) (pajak {{ $selectedSource['tax'] }}, non-pajak {{ $selectedSource['non_tax'] }})@endisset @endif</small>
                                            </td>
                                            <td>
                                                <select class="form-control v3-searchable" name="rows[{{ $rowIndex }}][destination_location_id]">
                                                    <option value="">— Pilih lokasi tujuan —</option>
                                                    @foreach($destinations as $destination)
                                                        <option value="{{ $destination['id'] }}" @selected($row['destination_location_id'] === $destination['id'])>{{ $destination['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td><input type="number" min="1" step="1" class="form-control" name="rows[{{ $rowIndex }}][quantity]" value="{{ $row['quantity'] }}"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger v3-remove-row" title="Hapus baris" aria-label="Hapus baris">&times;</button></td>
                                        </tr>
                                        @php $rowIndex++; @endphp
                                    @endforeach
                                    </tbody>
                                </table>
                                </div>
                                @if($item['sources'] === [])
                                    <div class="text-danger small mb-2">Tidak ada lokasi dengan stok yang memenuhi kondisi ini.</div>
                                @endif
                                <button type="button" class="btn btn-sm btn-outline-primary v3-add-row">+ Tambah Sumber</button>
                            @endif
                        </div>
                    @endforeach

                    <div class="d-flex flex-wrap">
                        <button type="submit" name="intent" value="save" class="btn btn-secondary mr-2 mb-2">Simpan Progres</button>
                        <button type="submit" name="intent" value="review" class="btn btn-success mr-2 mb-2">Setujui dan Kirim</button>
                        <button type="button" class="btn btn-outline-danger mr-2 mb-2" data-toggle="modal" data-target="#v3-reject-modal">Tolak</button>
                        <a href="{{ route('transfers.show', $transfer) }}" class="btn btn-light mb-2">Kembali</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="v3-reject-modal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form class="modal-content" action="{{ route('transfers.v3.reject', $transfer) }}" method="POST">
                @csrf
                <div class="modal-header"><h5 class="modal-title">Tolak Transfer</h5></div>
                <div class="modal-body">
                    <p>Tolak dokumen agar pembuat memperbaiki barang atau nomor seri. Barang tidak dapat diubah melalui alokasi.</p>
                    <label for="v3-reject-reason">Alasan Penolakan <span class="text-danger">*</span></label>
                    <textarea id="v3-reject-reason" name="reason" class="form-control" maxlength="255" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Tolak</button>
                </div>
            </form>
        </div>
    </div>

    @if($summary)
        <div class="modal fade" id="v3-summary-modal" tabindex="-1" role="dialog" aria-hidden="true" aria-labelledby="v3-summary-modal-title">
            <div class="modal-dialog modal-lg modal-dialog-scrollable v3-approval" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="v3-summary-modal-title">Ringkasan Persetujuan &amp; Pengiriman</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body">
                        @include('adjustment::transfers.v3.partials.summary-body')
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        @if($summary['errors'] === [])
                            @include('adjustment::transfers.v3.partials.summary-confirm')
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('page_css')
    <style>
        /* v3 approval workspace only: fixed column sizing so long location or
           business labels cannot widen the tables; narrow screens scroll
           inside the table wrapper, never the page. */
        .v3-approval .v3-table-scroll { max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .v3-approval .v3-alloc-table { table-layout: fixed; width: 100%; }
        .v3-approval .v3-bulk-table { min-width: 36rem; }
        .v3-approval .v3-serial-table { min-width: 36rem; }
        .v3-approval .v3-summary-table { min-width: 34rem; }
        .v3-approval .v3-col-source { width: 40%; }
        .v3-approval .v3-bulk-table .v3-col-destination { width: auto; }
        .v3-approval .v3-col-quantity { width: 7.5rem; }
        .v3-approval .v3-col-remove { width: 3.25rem; }
        .v3-approval .v3-serial-table .v3-col-source { width: 26%; }
        .v3-approval .v3-serial-table .v3-col-serials { width: auto; }
        .v3-approval .v3-col-count { width: 5.5rem; }
        .v3-approval .v3-serial-table .v3-col-destination { width: 32%; }
        .v3-approval .v3-summary-table .v3-col-product { width: 20%; }
        .v3-approval .v3-summary-table .v3-col-from,
        .v3-approval .v3-summary-table .v3-col-to { width: 24%; }
        .v3-approval .v3-summary-table .v3-col-serials { width: auto; }
        .v3-approval .v3-alloc-table td { vertical-align: top; }
        .v3-approval .v3-alloc-table td,
        .v3-approval .v3-alloc-table th { min-width: 0; }
        .v3-approval .v3-wrap { overflow-wrap: anywhere; word-break: break-word; white-space: normal; }
        .v3-approval .v3-serial { display: inline-block; max-width: 100%; white-space: normal; overflow-wrap: anywhere; text-align: left; }
        .v3-approval .v3-stock-info { overflow-wrap: anywhere; }
        /* Compact, truncated selection; full label stays in the title tooltip and the open dropdown. */
        .v3-approval .select2-container { width: 100% !important; max-width: 100%; }
        .v3-approval .select2-selection__rendered { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* Open dropdown is attached inside #v3-approval-root, so this stays scoped. */
        #v3-approval-root { position: relative; }
        #v3-approval-root .select2-dropdown { max-width: calc(100vw - 2rem); }
        #v3-approval-root .select2-results__option { white-space: normal; overflow-wrap: anywhere; }
    </style>
@endpush

@push('page_scripts')
    <script>
        if (!window.V3TransferApproval) {
            {!! file_get_contents(resource_path('js/transfer/v3-approval-allocation.js')) !!}
        }

        (function () {
            var Approval = window.V3TransferApproval;
            var nextIndex = {{ $rowIndex }};
            var root = document.getElementById('v3-approval-root');

            function stockMap(productEl) {
                var el = productEl ? productEl.querySelector('script.v3-stock-map') : null;
                if (!el) { return {}; }
                try { return JSON.parse(el.textContent || '{}'); } catch (e) { return {}; }
            }

            function refreshStockInfo(select) {
                var cell = select.closest('td');
                var info = cell ? cell.querySelector('.v3-stock-info') : null;
                if (info) {
                    info.textContent = Approval.stockText(stockMap(select.closest('[data-product]')), select.value);
                }
            }

            function initSearchable(scope) {
                if (window.jQuery && jQuery.fn.select2) {
                    jQuery(scope).find('select.v3-searchable').each(function () {
                        var $select = jQuery(this);
                        // The open dropdown renders inside the v3 root so its
                        // wrapping rules stay scoped to this page.
                        var parent = $select.closest('.modal').length ? $select.closest('.modal') : jQuery(root);
                        $select.select2({ width: '100%', dropdownAutoWidth: false, dropdownParent: parent });
                    });
                }
            }

            initSearchable(document);

            // Select2 fires jQuery 'change'; native selects fire DOM 'change'.
            if (window.jQuery) {
                jQuery(document).on('change', '#v3-approval-root select.v3-source-select', function () { refreshStockInfo(this); });
            } else {
                document.addEventListener('change', function (event) {
                    if (event.target.matches && event.target.matches('select.v3-source-select')) { refreshStockInfo(event.target); }
                });
            }

            document.querySelectorAll('.v3-add-row').forEach(function (button) {
                button.addEventListener('click', function () {
                    var body = button.closest('[data-product]').querySelector('.v3-rows');
                    var template = body.querySelector('tr');
                    if (window.jQuery && jQuery.fn.select2) {
                        jQuery(template).find('select.v3-searchable').select2('destroy');
                    }
                    var clone = template.cloneNode(true);
                    initSearchable(template);
                    clone.querySelectorAll('[name]').forEach(function (field) {
                        field.name = Approval.renumber(field.name, nextIndex);
                        if (field.type !== 'hidden') { field.value = ''; }
                    });
                    clone.querySelectorAll('.v3-stock-info').forEach(function (info) { info.textContent = ''; });
                    nextIndex++;
                    body.appendChild(clone);
                    initSearchable(clone);
                });
            });

            document.addEventListener('click', function (event) {
                var remove = event.target.closest('.v3-remove-row');
                if (!remove) { return; }
                var body = remove.closest('.v3-rows');
                var row = remove.closest('tr');
                if (body.querySelectorAll('tr').length > 1) {
                    row.remove();
                } else {
                    row.querySelectorAll('select, input[type=number]').forEach(function (field) { field.value = ''; });
                    if (window.jQuery) { jQuery(row).find('select').trigger('change'); }
                    row.querySelectorAll('.v3-stock-info').forEach(function (info) { info.textContent = ''; });
                }
            });

            @if($summary)
                // Wait for deferred module scripts (app.js registers the CoreUI
                // modal plugin), then open the modal or reveal the fallback.
                var openSummary = function () {
                    Approval.showSummary({
                        jQuery: window.jQuery,
                        modal: document.getElementById('v3-summary-modal'),
                        fallback: document.getElementById('v3-summary-fallback'),
                    });
                };
                Approval.whenScriptsReady(document, openSummary);
                document.getElementById('v3-open-summary').addEventListener('click', openSummary);
            @endif
        })();
    </script>
@endpush

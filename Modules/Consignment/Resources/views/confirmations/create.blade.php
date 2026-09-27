@extends('layouts.app')

@section('title', 'Buat Draft Konfirmasi Alokasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.confirmations.index') }}">Konfirmasi Alokasi</a></li>
        <li class="breadcrumb-item active">Buat Draft</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h4 class="card-title">Buat Draft Konfirmasi Alokasi Penjualan (1 Supplier)</h4>
                <p class="text-muted small">Pilih supplier dan alokasikan sumber penjualan konsinyasi yang eligible.</p>

                {{--
                    Source filters live in their own GET form, outside the POST form
                    below, so applying a filter never submits or discards allocations
                    the user has already checked in the draft.
                --}}
                <form method="GET" action="{{ route('consignments.confirmations.create') }}" class="form-row align-items-end mb-3">
                    <input type="hidden" name="supplier_id" value="{{ $selectedSupplierId ?? '' }}">
                    <div class="form-group col-md-3">
                        <label class="small text-muted mb-1">Filter Produk</label>
                        @include('consignment::partials.ajax-select', [
                            'name' => 'filter_product_id',
                            'url' => route('consignments.select.products'),
                            'selectedId' => request('filter_product_id'),
                            'selectedText' => $selectedFilterProductText ?? null,
                            'placeholder' => '-- Semua Produk --',
                        ])
                    </div>
                    <div class="form-group col-md-3">
                        <label class="small text-muted mb-1">Filter Lokasi</label>
                        @include('consignment::partials.ajax-select', [
                            'name' => 'filter_location_id',
                            'url' => route('consignments.select.locations'),
                            'selectedId' => request('filter_location_id'),
                            'selectedText' => $selectedFilterLocationText ?? null,
                            'placeholder' => '-- Semua Lokasi --',
                        ])
                    </div>
                    <div class="form-group col-md-3">
                        <label class="small text-muted mb-1">Cari Referensi / No. Seri</label>
                        <input type="text" name="source_q" class="form-control" value="{{ request('source_q') }}" placeholder="Referensi penjualan atau nomor seri">
                    </div>
                    <div class="form-group col-md-3">
                        <button type="submit" class="btn btn-secondary"><i class="bi bi-filter"></i> Filter Sumber</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('consignments.confirmations.store') }}">
                    @csrf

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Supplier <span class="text-danger">*</span></label>
                            @include('consignment::partials.ajax-select', [
                                'name' => 'supplier_id',
                                'url' => route('consignments.select.suppliers') . '?active_only=1',
                                'selectedId' => $selectedSupplierId ?: null,
                                'selectedText' => $selectedSupplierText ?? null,
                                'placeholder' => '-- Pilih Supplier --',
                                'selectId' => 'confirmation_supplier_id',
                                'required' => true,
                            ])
                        </div>
                        <div class="form-group col-md-6">
                            <label>Tanggal <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Catatan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Catatan opsional..."></textarea>
                    </div>

                    <h5 class="mt-4">Pilih Sumber Terjual</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>Pilih</th>
                                    <th>Ref Penjualan</th>
                                    <th>Lokasi</th>
                                    <th>Produk</th>
                                    <th>Sisa Eligible Qty</th>
                                    <th>Alokasi Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($eligibleSources as $src)
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="lines[{{ $src->id }}][selected]" value="1">
                                            <input type="hidden" name="lines[{{ $src->id }}][consignment_sold_source_id]" value="{{ $src->id }}">
                                            <input type="hidden" name="lines[{{ $src->id }}][product_id]" value="{{ $src->product_id }}">
                                            <input type="hidden" name="lines[{{ $src->id }}][location_id]" value="{{ $src->location_id }}">
                                        </td>
                                        <td>{{ $src->sale->reference ?? "Dispatch #{$src->dispatch_detail_id}" }}</td>
                                        <td>{{ $src->location->name ?? '-' }}</td>
                                        <td>{{ $src->product->product_name ?? '-' }}</td>
                                        <td>{{ number_format($src->eligibility['remaining_quantity'], 3) }}</td>
                                        <td>
                                            <input type="number" step="0.001" name="lines[{{ $src->id }}][allocated_base_quantity]" class="form-control form-control-sm" value="{{ $src->eligibility['remaining_quantity'] }}">
                                        </td>
                                    </tr>
                                    @if(! empty($src->receipt_pools))
                                        <tr class="bg-light">
                                            <td></td>
                                            <td colspan="5">
                                                <small class="text-muted font-weight-bold"><i class="bi bi-box-seam"></i> Alokasi Lot Penerimaan (Receipt Pool):</small>
                                                @foreach($src->receipt_pools as $pIdx => $pool)
                                                    <div class="form-inline mt-1">
                                                        <input type="hidden" name="lines[{{ $src->id }}][receipt_allocations][{{ $pIdx }}][consignment_receiving_detail_id]" value="{{ $pool['consignment_receiving_detail_id'] }}">
                                                        <label class="mr-2 small">Receiving {{ $pool['receiving_number'] }} (Capacity: {{ number_format($pool['remaining_quantity'], 3) }}):</label>
                                                        <input type="number" step="0.001" name="lines[{{ $src->id }}][receipt_allocations][{{ $pIdx }}][allocated_base_quantity]" class="form-control form-control-sm" value="{{ $pIdx === 0 ? min($src->eligibility['remaining_quantity'], $pool['remaining_quantity']) : 0 }}" style="width: 120px;">
                                                    </div>
                                                @endforeach
                                                @if(! empty($src->resolved_serials))
                                                    <div class="mt-2">
                                                        <small class="text-muted font-weight-bold"><i class="bi bi-barcode"></i> Serial Numbers Eligible:</small>
                                                        @foreach($src->resolved_serials as $sIdx => $snData)
                                                            <div class="form-check form-inline mt-1 small">
                                                                <input type="checkbox" class="form-check-input" checked name="lines[{{ $src->id }}][serialized_allocations][{{ $sIdx }}][selected]" value="1" id="serial_{{ $src->id }}_{{ $sIdx }}">
                                                                <input type="hidden" name="lines[{{ $src->id }}][serialized_allocations][{{ $sIdx }}][product_serial_number_id]" value="{{ $snData['product_serial_number_id'] }}">
                                                                <input type="hidden" name="lines[{{ $src->id }}][serialized_allocations][{{ $sIdx }}][consignment_receiving_detail_id]" value="{{ $snData['consignment_receiving_detail_id'] }}">
                                                                <label class="form-check-label mr-2" for="serial_{{ $src->id }}_{{ $sIdx }}">Serial: <strong>{{ $snData['serial_number'] }}</strong> (Receiving: {{ $snData['receiving_number'] }})</label>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">Tidak ada sumber terjual konsinyasi yang eligible saat ini.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($eligibleSources instanceof \Illuminate\Contracts\Pagination\Paginator)
                        <div class="mt-2">
                            {{ $eligibleSources->links() }}
                        </div>
                    @endif

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan Draft</button>
                        <a href="{{ route('consignments.confirmations.index') }}" class="btn btn-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    @include('consignment::partials.ajax-select-scripts')
    <script>
        // ------------------------------------------------------------------
        // Cross-page draft state.
        //
        // The source table is filtered and paginated, so the visible rows are
        // never the whole draft. Every line input on this page is keyed by
        // consignment_sold_source_id (never by row index, which pagination
        // reuses). Before navigating away we snapshot the page's inputs into
        // sessionStorage; on load we restore them; on submit we re-emit every
        // stored selection that is not present on this page as hidden inputs,
        // so sources chosen on other pages are posted together.
        // ------------------------------------------------------------------
        (function () {
            const SUPPLIER_KEY = 'consignment.confirmation.create.supplier';
            const DRAFT_KEY = 'consignment.confirmation.create.draft';
            const supplierId = @json((string) ($selectedSupplierId ?: ''));

            function readDraft() {
                try {
                    return JSON.parse(sessionStorage.getItem(DRAFT_KEY) || '{}');
                } catch (e) {
                    return {};
                }
            }

            function writeDraft(draft) {
                try {
                    sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
                } catch (e) {
                    /* storage unavailable: fall back to per-page behaviour */
                }
            }

            function clearDraft() {
                try {
                    sessionStorage.removeItem(DRAFT_KEY);
                } catch (e) { /* ignore */ }
            }

            const form = document.querySelector('form[action="{{ route('consignments.confirmations.store') }}"]');
            if (!form) return;

            // Supplier changes invalidate receipt pools and serial lineage, so any
            // stored selections belong to a different supplier and must be dropped.
            let storedSupplier = null;
            try {
                storedSupplier = sessionStorage.getItem(SUPPLIER_KEY);
            } catch (e) { /* ignore */ }

            if (storedSupplier !== null && storedSupplier !== supplierId) {
                const stale = readDraft();
                if (Object.keys(stale).length > 0) {
                    const warning = document.createElement('div');
                    warning.className = 'alert alert-warning';
                    warning.textContent =
                        'Supplier diubah. Pilihan sumber terjual sebelumnya telah dikosongkan karena alokasi lot dan nomor seri hanya berlaku untuk satu supplier.';
                    form.prepend(warning);
                }
                clearDraft();
            }
            try {
                sessionStorage.setItem(SUPPLIER_KEY, supplierId);
            } catch (e) { /* ignore */ }

            // Which sources are rendered on this page.
            const visibleIds = new Set(
                Array.from(form.querySelectorAll('input[name^="lines["][name$="[consignment_sold_source_id]"]'))
                    .map(el => String(el.value))
            );

            // Collect this page's line inputs, grouped by sold source id.
            function collectPage() {
                const page = {};
                form.querySelectorAll('input[name^="lines["], select[name^="lines["]').forEach(el => {
                    const match = el.name.match(/^lines\[([^\]]+)\]/);
                    if (!match) return;
                    const sourceId = match[1];
                    if (el.type === 'checkbox' && !el.checked) return;
                    (page[sourceId] = page[sourceId] || {})[el.name] = el.value;
                });

                // Keep only sources the user actually selected.
                Object.keys(page).forEach(sourceId => {
                    const selectedKey = 'lines[' + sourceId + '][selected]';
                    if (!(selectedKey in page[sourceId])) delete page[sourceId];
                });
                return page;
            }

            // Restore previously stored values for rows visible on this page.
            const draft = readDraft();
            visibleIds.forEach(sourceId => {
                const saved = draft[sourceId];
                if (!saved) return;
                Object.keys(saved).forEach(name => {
                    const el = form.querySelector('[name="' + CSS.escape(name) + '"]');
                    if (!el) return;
                    if (el.type === 'checkbox') {
                        el.checked = true;
                    } else {
                        el.value = saved[name];
                    }
                });
            });

            // Snapshot before leaving the page (filtering or paginating).
            function snapshot() {
                const merged = readDraft();
                // Drop stale entries for rows shown here, then re-add current state,
                // so deselecting a visible row actually removes it.
                visibleIds.forEach(id => { delete merged[id]; });
                Object.assign(merged, collectPage());
                writeDraft(merged);
            }

            window.addEventListener('pagehide', snapshot);
            document.querySelectorAll('a').forEach(link => link.addEventListener('click', snapshot));

            // On submit, re-emit stored selections from other pages as hidden inputs.
            form.addEventListener('submit', function () {
                const merged = readDraft();
                visibleIds.forEach(id => { delete merged[id]; });

                Object.keys(merged).forEach(sourceId => {
                    Object.keys(merged[sourceId]).forEach(name => {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = name;
                        hidden.value = merged[sourceId][name];
                        form.appendChild(hidden);
                    });
                });

                clearDraft();
            });
        })();

        // Changing the supplier reloads the page: receipt pools and serial lineage
        // are resolved per supplier on the server.
        $(function () {
            $('#confirmation_supplier_id').on('change', function () {
                const value = $(this).val();
                if (value) {
                    window.location.href = @json(route('consignments.confirmations.create')) + '?supplier_id=' + value;
                }
            });
        });
    </script>
@endpush

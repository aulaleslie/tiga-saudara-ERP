{{--
    Serial-impact detail for one product row. Only reached when
    $canViewSystemStock is true (see stock-opname-show.blade.php), so it is
    safe to render source/destination/tax/condition facts here.

    $product is either a reviewer ProductReconciliation::toReviewerArray()
    entry (preview, $isApproved = false) or one applied-product entry from
    the immutable approval_result (locked, $isApproved = true) — the two
    shapes carry different keys for the same concepts, handled below.
--}}
@php
    $statusLabels = [
        'retained' => ['Dipertahankan', 'badge-secondary'],
        'moved' => ['Dipindahkan', 'badge-primary'],
        'new' => ['Baru', 'badge-success'],
        'condition_changed' => ['Perubahan Kondisi', 'badge-warning'],
        'tax_changed' => ['Perubahan Pajak', 'badge-info'],
        'omitted' => ['Hilang saat Dihitung', 'badge-danger'],
        'conflicting' => ['Konflik', 'badge-danger'],
        'created' => ['Baru', 'badge-success'],
        'missing' => ['Hilang saat Dihitung', 'badge-danger'],
    ];

    $conditionLabel = fn ($c) => $c === 'bad' ? 'Rusak' : ($c === 'good' ? 'Bagus' : '-');
    $taxLabel = fn ($t) => $t === null ? '-' : ($t ? 'Kena Pajak' : 'Tidak Kena Pajak');
@endphp

@if(!$isApproved)
    @php $serials = $product['serials'] ?? []; $omitted = $product['omitted_serials'] ?? []; @endphp

    @if(!empty($serials))
        <div class="table-responsive mb-2">
            <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                <tr>
                    <th>Nomor Seri</th>
                    <th>Status</th>
                    <th>Lokasi Asal</th>
                    <th>Lokasi Tujuan</th>
                    <th>Kondisi (Sebelum &rarr; Sesudah)</th>
                    <th>Pajak (Sebelum &rarr; Sesudah)</th>
                </tr>
                </thead>
                <tbody>
                @foreach($serials as $serial)
                    @php
                        $statuses = $serial['statuses'] ?? [$serial['status'] ?? null];
                    @endphp
                    <tr>
                        <td><code>{{ $serial['serial_number'] }}</code></td>
                        <td>
                            @foreach($statuses as $status)
                                <span class="badge {{ $statusLabels[$status][1] ?? 'badge-secondary' }}">{{ $statusLabels[$status][0] ?? $status }}</span>
                            @endforeach
                            @if(!empty($serial['same_text_other_product']))
                                <span class="badge badge-warning">Nomor seri sama dipakai produk lain</span>
                            @endif
                        </td>
                        <td>{{ $serial['source_location_name'] ?? '-' }}</td>
                        <td>{{ $vm['location_name'] ?? '-' }}</td>
                        <td>{{ $conditionLabel($serial['source_condition'] ?? null) }} &rarr; {{ $conditionLabel($serial['entered_condition'] ?? null) }}</td>
                        <td>{{ $taxLabel($serial['source_is_tax'] ?? null) }} &rarr; {{ $taxLabel($serial['destination_is_tax'] ?? null) }}</td>
                    </tr>
                    @if(!empty($serial['conflict_reason']))
                        <tr>
                            <td colspan="6" class="text-danger small">Konflik: {{ $serial['conflict_reason'] }}</td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(!empty($omitted))
        <div class="alert alert-warning small mb-0">
            <strong>Nomor seri terdaftar di lokasi ini namun tidak ikut dihitung (akan ditandai hilang saat disetujui):</strong>
            <ul class="mb-0">
                @foreach($omitted as $serial)
                    <li>
                        <code>{{ $serial['serial_number'] }}</code>
                        &mdash; {{ $conditionLabel($serial['source_condition'] ?? null) }},
                        {{ $taxLabel($serial['source_is_tax'] ?? null) }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(empty($serials) && empty($omitted))
        <span class="text-muted small">Tidak ada rincian nomor seri.</span>
    @endif
@else
    @php $serials = $product['serials'] ?? []; $omitted = $product['omitted_serials'] ?? []; @endphp

    @if(!empty($serials))
        <div class="table-responsive mb-2">
            <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                <tr>
                    <th>Nomor Seri</th>
                    <th>Status</th>
                    <th>Lokasi Asal</th>
                    <th>Lokasi Tujuan</th>
                    <th>Kondisi (Sebelum &rarr; Diterapkan)</th>
                    <th>Pajak (Sebelum &rarr; Diterapkan)</th>
                </tr>
                </thead>
                <tbody>
                @foreach($serials as $serial)
                    @php $action = $serial['action'] ?? null; @endphp
                    <tr>
                        <td><code>{{ $serial['serial_number'] }}</code></td>
                        <td>
                            <span class="badge {{ $statusLabels[$action][1] ?? 'badge-secondary' }}">{{ $statusLabels[$action][0] ?? $action }}</span>
                            @if(!empty($serial['same_text_other_product']))
                                <span class="badge badge-warning">Nomor seri sama dipakai produk lain</span>
                            @endif
                        </td>
                        <td>{{ $serial['source_location_name'] ?? '-' }}</td>
                        <td>{{ $vm['location_name'] ?? '-' }}</td>
                        <td>{{ $conditionLabel($serial['source_condition'] ?? null) }} &rarr; {{ $conditionLabel($serial['applied_condition'] ?? null) }}</td>
                        <td>{{ $taxLabel($serial['source_is_tax'] ?? null) }} &rarr; {{ $taxLabel($serial['applied_is_tax'] ?? null) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(!empty($omitted))
        <div class="alert alert-warning small mb-0">
            <strong>Nomor seri yang hilang saat perhitungan (ditandai hilang saat persetujuan):</strong>
            <ul class="mb-0">
                @foreach($omitted as $serial)
                    <li>
                        <code>{{ $serial['serial_number'] }}</code>
                        &mdash; {{ $conditionLabel($serial['previous_condition'] ?? null) }},
                        {{ $taxLabel($serial['previous_is_tax'] ?? null) }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(empty($serials) && empty($omitted))
        <span class="text-muted small">Tidak ada rincian nomor seri.</span>
    @endif
@endif

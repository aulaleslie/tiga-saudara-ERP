{{--
    Stock Opname (normal versioned adjustment) detail view.

    $adjustment here is the safe header/lifecycle projection built by
    AdjustmentController@buildStockOpnameDocument — an array of IDs and
    metadata only, never the Eloquent model (so count_draft/approval_result
    casts can never leak into this partial's scope).

    $stockOpnameViewModel, $canViewSystemStock, $canApprove, $canEdit,
    $isDraft/$isWaitingApproval/$isRejected/$isApproved, and
    $showSubmit/$showApprove/$showReject/$showEdit/$showDelete are all
    decided in AdjustmentController@buildStockOpnameViewModel — this partial
    only renders what it is given and never recomputes permission or
    lifecycle logic from raw permission strings.
--}}
@php
    $vm = $stockOpnameViewModel;
    $statusLabels = [
        'DRAFT' => ['Draf', 'badge-secondary'],
        'WAITING_APPROVAL' => ['Menunggu Persetujuan', 'badge-warning'],
        'REJECTED' => ['Ditolak', 'badge-danger'],
        'APPROVED' => ['Disetujui', 'badge-success'],
    ];
    $statusValue = $adjustment['status'];
    [$statusLabel, $statusBadgeClass] = $statusLabels[$statusValue] ?? [$statusValue, 'badge-secondary'];
@endphp

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
        <a href="{{ route('adjustments.index') }}" class="btn btn-secondary">
            Kembali
        </a>

        <div>
            @if($showEdit)
                <a href="{{ route('adjustments.edit', $adjustment['id']) }}" class="btn btn-outline-primary">Ubah</a>
            @endif

            @if($showSubmit)
                <form action="{{ route('adjustments.submit', $adjustment['id']) }}" method="POST" class="d-inline">
                    @csrf @method('PATCH')
                    <button type="submit" class="btn btn-primary">Ajukan Persetujuan</button>
                </form>
            @endif

            @if($showApprove)
                <form action="{{ route('adjustments.approve', $adjustment['id']) }}" method="POST" class="d-inline">
                    @csrf @method('PATCH')
                    <button type="submit" class="btn btn-success">Setuju</button>
                </form>
            @endif

            @if($showReject)
                <form action="{{ route('adjustments.reject', $adjustment['id']) }}" method="POST" class="d-inline"
                      onsubmit="return promptStockOpnameRejectionReason(this);">
                    @csrf @method('PATCH')
                    <input type="hidden" name="rejection_reason" class="js-rejection-reason">
                    <button type="submit" class="btn btn-danger">Tolak</button>
                </form>
            @endif

            @if($showDelete)
                <form action="{{ route('adjustments.destroy', $adjustment['id']) }}" method="POST" class="d-inline"
                      onsubmit="return confirm('Yakin ingin menghapus proposal stock opname ini?');">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">Hapus</button>
                </form>
            @endif
        </div>
    </div>
</div>

@if($showReject)
    <script>
        function promptStockOpnameRejectionReason(form) {
            const reason = window.prompt('Masukkan alasan penolakan (wajib diisi):', '');
            if (reason === null || reason.trim() === '') {
                alert('Alasan penolakan wajib diisi.');
                return false;
            }
            form.querySelector('.js-rejection-reason').value = reason.trim();
            return true;
        }
    </script>
@endif

<!-- Header -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Rincian Stock Opname</h5>
                <span class="badge {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <tr>
                            <th>Tanggal</th>
                            <td>{{ $adjustment['date'] }}</td>
                            <th>Referensi</th>
                            <td>{{ $adjustment['reference'] }}</td>
                        </tr>
                        <tr>
                            <th>Lokasi</th>
                            <td>{{ $vm['location_name'] ?? '-' }}</td>
                            <th>Catatan</th>
                            <td>{{ $adjustment['note'] ?: '-' }}</td>
                        </tr>
                        @if($isDraft && $adjustment['submitted_at'])
                            <tr>
                                <th>Diajukan Oleh</th>
                                <td colspan="3">
                                    {{ $adjustment['submitted_by_name'] ?? '-' }}
                                    pada {{ \Carbon\Carbon::parse($adjustment['submitted_at'])->format('d M Y, H:i') }}
                                </td>
                            </tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@if($isRejected || ($isDraft && $adjustment['rejected_at']))
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert alert-danger mb-0">
                <strong>Riwayat Penolakan</strong>
                <div class="mt-1">
                    Ditolak oleh <strong>{{ $adjustment['rejected_by_name'] ?? '-' }}</strong>
                    pada {{ $adjustment['rejected_at'] ? \Carbon\Carbon::parse($adjustment['rejected_at'])->format('d M Y, H:i') : '-' }}.
                </div>
                @if(!empty($adjustment['rejection_reason']))
                    <div class="mt-1">Alasan: {{ $adjustment['rejection_reason'] }}</div>
                @endif
                @if($isDraft)
                    <div class="mt-1 small text-muted">
                        Dokumen ini telah diubah menjadi draf. Perbaiki dan ajukan kembali untuk persetujuan.
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif

@if($isApproved)
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert alert-success mb-0">
                Disetujui oleh <strong>{{ $vm['approved_by_name'] ?? $adjustment['approved_by_name'] ?? '-' }}</strong>
                pada {{ isset($vm['approved_at']) ? \Carbon\Carbon::parse($vm['approved_at'])->format('d M Y, H:i') : ($adjustment['approved_at'] ? \Carbon\Carbon::parse($adjustment['approved_at'])->format('d M Y, H:i') : '-') }}.
                Rincian di bawah ini adalah hasil yang benar-benar diterapkan saat persetujuan dan tidak berubah meskipun stok berubah setelahnya.
            </div>
        </div>
    </div>
@endif

@if($canViewSystemStock && !$isApproved)
    @php
        $products = $vm['products'] ?? [];
        $diffCount = collect($products)->filter(fn ($p) => ($p['difference']['good'] ?? 0) !== 0 || ($p['difference']['bad'] ?? 0) !== 0)->count();
        $increaseCount = collect($products)->filter(fn ($p) => $p['exceeds_all_location_total'] ?? false)->count();
        $driftCount = collect($products)->filter(fn ($p) => (float) ($p['drift'] ?? 0) != 0.0)->count();
        $movedOrNewCount = collect($products)->flatMap(fn ($p) => $p['serials'] ?? [])
            ->filter(fn ($s) => in_array($s['status'] ?? null, ['moved', 'new'], true))->count();
        $taxChangedCount = collect($products)->flatMap(fn ($p) => $p['serials'] ?? [])
            ->filter(fn ($s) => in_array('tax_changed', $s['statuses'] ?? [($s['status'] ?? null)], true))->count();
        $conflictCount = count($vm['conflicts'] ?? []);
    @endphp

    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Ringkasan Peninjauan</h5></div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>{{ $diffCount }} produk memiliki selisih antara hasil hitung dan stok saat ini.</li>
                        <li>{{ $increaseCount }} produk berpotensi menyebabkan kenaikan stok global (melebihi total stok di semua lokasi terkait).</li>
                        <li>{{ $movedOrNewCount }} nomor seri akan dipindahkan atau didaftarkan sebagai baru.</li>
                        <li>{{ $taxChangedCount }} nomor seri akan mengalami perubahan klasifikasi pajak.</li>
                        <li>{{ $driftCount }} produk mengalami perubahan stok (drift) sejak baseline perhitungan diambil.</li>
                        <li>{{ $conflictCount }} konflik ditemukan{{ $conflictCount > 0 ? ' — persetujuan akan diblokir sampai konflik ini diselesaikan' : '' }}.</li>
                    </ul>

                    @if(!empty($vm['warnings']))
                        <div class="alert alert-warning mt-3 mb-0">
                            <strong>Peringatan:</strong>
                            <ul class="mb-0">
                                @foreach($vm['warnings'] as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if(!empty($vm['conflicts']))
                        <div class="alert alert-danger mt-3 mb-0">
                            <strong>Konflik:</strong>
                            <ul class="mb-0">
                                @foreach($vm['conflicts'] as $conflict)
                                    <li>{{ $conflict }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

<!-- Product comparison table -->
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Rincian Produk</h5></div>
            <div class="card-body">
                <div class="table-responsive">
                    @if($canViewSystemStock)
                        @php $products = $vm['products'] ?? []; @endphp
                        <table class="table table-bordered table-striped align-middle">
                            <thead class="thead-light">
                            <tr>
                                <th rowspan="2" class="align-middle">Produk</th>
                                @if(!$isApproved)
                                    <th colspan="2" class="text-center">Saat Mulai Dihitung</th>
                                    <th colspan="3" class="text-center">Saat Ini</th>
                                    <th colspan="4" class="text-center">Hasil Hitung</th>
                                @else
                                    <th colspan="2" class="text-center">Sebelum Disetujui</th>
                                    <th colspan="2" class="text-center">Dihitung</th>
                                @endif
                                <th class="text-center" rowspan="2">Setelah Disetujui</th>
                                <th rowspan="2" class="align-middle">Detail Seri</th>
                            </tr>
                            <tr>
                                @if(!$isApproved)
                                    <th class="text-center">Bagus</th>
                                    <th class="text-center">Rusak</th>
                                    <th class="text-center">Bagus</th>
                                    <th class="text-center">Rusak</th>
                                    <th class="text-center">Total Semua Lokasi</th>
                                    <th class="text-center">Bagus</th>
                                    <th class="text-center">Rusak</th>
                                    <th class="text-center">Selisih Bagus</th>
                                    <th class="text-center">Selisih Rusak</th>
                                @else
                                    <th class="text-center">Bagus</th>
                                    <th class="text-center">Rusak</th>
                                    <th class="text-center">Bagus</th>
                                    <th class="text-center">Rusak</th>
                                @endif
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($products as $i => $product)
                                @php
                                    $rowId = 'stock-opname-serials-' . $i;
                                    $hasSerials = !empty($product['serials']) || !empty($product['omitted_serials']);
                                    $isConditionOnly = $product['is_condition_reclassification_only'] ?? false;
                                @endphp
                                <tr>
                                    <td>
                                        <div class="font-weight-bold">{{ $product['product_name'] }}</div>
                                        <div class="small text-muted">{{ $product['product_code'] ?? '' }} @if(!empty($product['base_unit'])) &middot; {{ $product['base_unit'] }} @endif</div>
                                        @if($isConditionOnly)
                                            <span class="badge badge-info mt-1">Reklasifikasi kondisi, total tidak berubah</span>
                                        @endif
                                    </td>

                                    @if(!$isApproved)
                                        <td class="text-center">{{ $product['baseline']['good'] ?? 0 }}</td>
                                        <td class="text-center">{{ $product['baseline']['bad'] ?? 0 }}</td>
                                        <td class="text-center">{{ $product['current']['good'] ?? 0 }}</td>
                                        <td class="text-center">{{ $product['current']['bad'] ?? 0 }}</td>
                                        <td class="text-center">
                                            {{ $product['all_location_current_total'] ?? 0 }}
                                            @if($product['exceeds_all_location_total'] ?? false)
                                                <span class="badge badge-danger d-block mt-1">
                                                    Potensi kenaikan global +{{ $product['potential_global_increase'] ?? 0 }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-center">{{ $product['entered']['good'] ?? 0 }}</td>
                                        <td class="text-center">{{ $product['entered']['bad'] ?? 0 }}</td>
                                        <td class="text-center">
                                            @php $gd = $product['difference']['good'] ?? 0; @endphp
                                            <span class="badge {{ $gd >= 0 ? 'badge-success' : 'badge-danger' }}">{{ $gd > 0 ? "+{$gd}" : $gd }}</span>
                                        </td>
                                        <td class="text-center">
                                            @php $bd = $product['difference']['bad'] ?? 0; @endphp
                                            <span class="badge {{ $bd >= 0 ? 'badge-warning' : 'badge-danger' }}">{{ $bd > 0 ? "+{$bd}" : $bd }}</span>
                                        </td>
                                        <td class="text-center">{{ $product['projected_global_total'] ?? 0 }}</td>
                                    @else
                                        <td class="text-center">{{ $product['before']['good'] ?? '-' }}</td>
                                        <td class="text-center">{{ $product['before']['bad'] ?? '-' }}</td>
                                        <td class="text-center">{{ $product['entered']['good'] ?? '-' }}</td>
                                        <td class="text-center">{{ $product['entered']['bad'] ?? '-' }}</td>
                                        <td class="text-center">
                                            Bagus: {{ $product['applied']['good'] ?? '-' }},
                                            Rusak: {{ $product['applied']['bad'] ?? '-' }}
                                        </td>
                                    @endif

                                    <td>
                                        @if($hasSerials)
                                            <button type="button" class="btn btn-sm btn-outline-secondary toggle-serial-details"
                                                    data-details-target="{{ $rowId }}" aria-expanded="false">
                                                <i class="bi bi-plus-circle"></i> Lihat Rincian Seri
                                            </button>
                                        @else
                                            <span class="text-muted">Non-Serial</span>
                                        @endif
                                    </td>
                                </tr>

                                @if($hasSerials)
                                    <tr id="{{ $rowId }}" class="d-none">
                                        <td colspan="{{ $isApproved ? 7 : 11 }}">
                                            @include('adjustment::partials.stock-opname-serials', ['product' => $product, 'isApproved' => $isApproved])
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center text-muted">Tidak ada produk pada dokumen ini.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    @else
                        @php $products = $vm['products'] ?? []; @endphp
                        <table class="table table-bordered table-striped align-middle">
                            <thead class="thead-light">
                            <tr>
                                <th>Nama Produk</th>
                                <th>Kode</th>
                                <th class="text-center">Bagus</th>
                                <th class="text-center">Rusak</th>
                                <th>Nomor Seri Terhitung</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($products as $product)
                                <tr>
                                    <td>{{ $product['product_name'] }}</td>
                                    <td>{{ $product['product_code'] ?? '' }}</td>
                                    <td class="text-center">{{ $product['entered']['good'] ?? 0 }}</td>
                                    <td class="text-center">{{ $product['entered']['bad'] ?? 0 }}</td>
                                    <td>
                                        @if(!empty($product['serials']))
                                            <ul class="mb-0 ps-3 pl-3 small">
                                                @foreach($product['serials'] as $serial)
                                                    <li>
                                                        <code>{{ $serial['serial_number'] }}</code>
                                                        <span class="badge badge-sm {{ ($serial['condition'] ?? 'good') === 'good' ? 'badge-success' : 'badge-warning' }}">
                                                            {{ ($serial['condition'] ?? 'good') === 'good' ? 'BAGUS' : 'RUSAK' }}
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <span class="text-muted">Non-Serial</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted">Tidak ada produk pada dokumen ini.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@push('page_scripts')
    <script>
        (function () {
            function initStockOpnameSerialToggle() {
                document.querySelectorAll('button.toggle-serial-details').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const targetId = button.getAttribute('data-details-target');
                        const row = document.getElementById(targetId);
                        if (!row) {
                            return;
                        }
                        const icon = button.querySelector('i');
                        const isHidden = row.classList.contains('d-none');
                        row.classList.toggle('d-none');
                        button.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
                        if (icon) {
                            icon.classList.toggle('bi-plus-circle', !isHidden);
                            icon.classList.toggle('bi-dash-circle', isHidden);
                        }
                    });
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initStockOpnameSerialToggle);
            } else {
                initStockOpnameSerialToggle();
            }
        })();
    </script>
@endpush

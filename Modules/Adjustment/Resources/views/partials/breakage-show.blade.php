{{--
    Breakage adjustment detail view (design.md "Add a Bahasa Indonesia review
    experience"). $adjustment is the Eloquent model here (unlike the Stock
    Opname partial); breakage's approval_result/count_draft are not exposed
    directly to this partial -- only $breakagePlan (pending, from
    BreakageMovementPlanner::plan(), already permission-scrubbed) or
    $breakageApprovalResult (approved, from the immutable stored DTO shape,
    also already permission-scrubbed) is passed in by
    AdjustmentController@buildBreakageViewModel.
--}}
@php
    $statusLabels = [
        'PENDING' => ['Menunggu Persetujuan', 'badge-warning'],
        'APPROVED' => ['Disetujui', 'badge-success'],
        'REJECTED' => ['Ditolak', 'badge-danger'],
    ];
    $statusValue = \Modules\Adjustment\Entities\AdjustmentStatus::normalize($adjustment->status)->value;
    [$statusLabel, $statusBadgeClass] = $statusLabels[$statusValue] ?? [$statusValue, 'badge-secondary'];
@endphp

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
        <a href="{{ route('adjustments.index') }}" class="btn btn-secondary">
            Kembali
        </a>

        <div>
            @if($showEdit)
                <a href="{{ route('adjustments.editBreakage', $adjustment->id) }}" class="btn btn-outline-primary">Ubah</a>
            @endif

            @if($showApprove)
                <form action="{{ route('adjustments.approve', $adjustment->id) }}" method="POST" class="d-inline"
                      onsubmit="return confirm('Menyetujui akan mengubah stok baik menjadi stok rusak sesuai rincian di bawah ini. Lanjutkan?');">
                    @csrf @method('PATCH')
                    <button type="submit" class="btn btn-success" {{ !empty($breakagePlan['conflicts']) ? 'disabled' : '' }}>
                        Setuju
                    </button>
                </form>
            @endif

            @if($showReject)
                <form action="{{ route('adjustments.reject', $adjustment->id) }}" method="POST" class="d-inline"
                      onsubmit="return confirm('Yakin ingin menolak dokumen barang rusak ini?');">
                    @csrf @method('PATCH')
                    <button type="submit" class="btn btn-danger">Tolak</button>
                </form>
            @endif

            @if($showDelete)
                <form action="{{ route('adjustments.destroy', $adjustment->id) }}" method="POST" class="d-inline"
                      onsubmit="return confirm('Yakin ingin menghapus dokumen barang rusak ini?');">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">Hapus</button>
                </form>
            @endif
        </div>
    </div>
</div>

@if($showApprove && !empty($breakagePlan['conflicts']))
    <div class="alert alert-danger">
        Tombol "Setuju" dinonaktifkan karena ada konflik yang harus diselesaikan terlebih dahulu (lihat rincian di bawah).
    </div>
@endif

<!-- Header -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Rincian Barang Rusak</h5>
                <span class="badge {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <tr>
                            <th>Tanggal</th>
                            <td>{{ $adjustment->date }}</td>
                            <th>Referensi</th>
                            <td>{{ $adjustment->reference }}</td>
                        </tr>
                        <tr>
                            <th>Lokasi</th>
                            <td>{{ $adjustment->location->name ?? '-' }}</td>
                            <th>Kelompok Pajak Lokasi</th>
                            <td>
                                @php
                                    $isPkpValue = $isApproved
                                        ? ($breakageApprovalResult['is_pkp'] ?? null)
                                        : ($breakagePlan['is_pkp'] ?? null);
                                @endphp
                                @if($isPkpValue === null)
                                    <span class="text-muted">-</span>
                                @else
                                    <span class="badge {{ $isPkpValue ? 'badge-info' : 'badge-secondary' }}">
                                        {{ $isPkpValue ? 'PKP' : 'Non-PKP' }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @if($adjustment->note)
                            <tr>
                                <th>Catatan</th>
                                <td colspan="3">{{ $adjustment->note }}</td>
                            </tr>
                        @endif
                        @if($isApproved && !$isLegacyApproved)
                            <tr>
                                <th>Disetujui Oleh</th>
                                <td>{{ $breakageApprovalResult['approved_by_name'] ?? $adjustment->approvedBy?->name ?? '-' }}</td>
                                <th>Waktu Persetujuan</th>
                                <td>{{ $breakageApprovalResult['approved_at'] ?? '-' }}</td>
                            </tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@if($isLegacyApproved)
    <div class="alert alert-warning mt-3">
        <i class="bi bi-exclamation-triangle-fill mr-1"></i>
        Dokumen barang rusak ini disetujui sebelum pencatatan bukti persetujuan (audit) diaktifkan. Rincian penerapan
        yang tepat tidak tersedia; tabel di bawah hanya menampilkan data produk yang tercatat pada dokumen ini.
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Produk pada Dokumen (Fallback Lama)</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="thead-light">
                            <tr>
                                <th>Produk</th>
                                <th>Kode</th>
                                <th class="text-center">Kuantitas</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($adjustment->adjustedProducts as $ap)
                                <tr>
                                    <td>{{ $ap->product->product_name ?? '-' }}</td>
                                    <td>{{ $ap->product->product_code ?? '-' }}</td>
                                    <td class="text-center">{{ $ap->quantity }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@elseif($isApproved)
    {{-- Approved: render exclusively from immutable approval_result --}}
    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Perubahan yang Diterapkan (Bagus &rarr; Rusak)</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="thead-light">
                            <tr>
                                <th>Produk</th>
                                @if($canViewSystemStock)
                                    <th class="text-center">Sebelum (Bagus / Rusak)</th>
                                @endif
                                <th class="text-center">Pergerakan</th>
                                @if($canViewSystemStock)
                                    <th class="text-center">Sesudah (Bagus / Rusak)</th>
                                @endif
                                <th>Nomor Seri (Bagus &rarr; Rusak)</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($breakageApprovalResult['products'] ?? [] as $product)
                                <tr>
                                    <td>
                                        <div class="font-weight-bold">{{ $product['product_name'] }}</div>
                                        <div class="text-muted small">
                                            <code>{{ $product['product_code'] }}</code>
                                            <span class="badge badge-secondary ml-1">{{ $product['base_unit'] }}</span>
                                        </div>
                                    </td>
                                    @if($canViewSystemStock)
                                        <td class="text-center">
                                            {{ $product['before']['good'] ?? '-' }} / {{ $product['before']['bad'] ?? '-' }}
                                        </td>
                                    @endif
                                    <td class="text-center font-weight-bold text-danger">
                                        {{ $product['movement'] ?? 0 }} {{ $product['base_unit'] }}
                                    </td>
                                    @if($canViewSystemStock)
                                        <td class="text-center">
                                            {{ $product['after']['good'] ?? '-' }} / {{ $product['after']['bad'] ?? '-' }}
                                        </td>
                                    @endif
                                    <td>
                                        @if(!empty($product['serials']))
                                            <ol class="mb-0 ps-3">
                                                @foreach($product['serials'] as $serial)
                                                    <li>
                                                        {{ $serial['serial_number'] }}
                                                        <span class="text-muted">
                                                            ({{ $serial['condition_before'] ?? 'Bagus' }} &rarr; {{ $serial['condition_after'] ?? 'Rusak' }})
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ol>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $canViewSystemStock ? 5 : 3 }}" class="text-center text-muted py-3">Tidak ada produk.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if(!empty($breakageApprovalResult['warnings']))
                        <div class="alert alert-warning mt-3 mb-0">
                            <strong>Peringatan pada saat persetujuan:</strong>
                            <ul class="mb-0">
                                @foreach($breakageApprovalResult['warnings'] as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@else
    {{-- Pending: live preview from BreakageMovementPlanner. This is
         informational and may become stale; approval always revalidates
         under locks and cannot be bypassed from this preview. --}}
    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Pratinjau Persetujuan (Bagus &rarr; Rusak)</h5>
                    @if(!empty($breakagePlan['approvable']))
                        <span class="badge badge-success">Siap Disetujui</span>
                    @else
                        <span class="badge badge-danger">Ada Konflik</span>
                    @endif
                </div>
                <div class="card-body">
                    @if(!empty($breakagePlan['conflicts']))
                        <div class="alert alert-danger">
                            <strong>Dokumen ini belum dapat disetujui:</strong>
                            <ul class="mb-0">
                                @foreach($breakagePlan['conflicts'] as $conflict)
                                    <li>{{ $conflict }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="thead-light">
                            <tr>
                                <th>Produk</th>
                                @if($canViewSystemStock)
                                    <th class="text-center">Stok Saat Ini (Bagus / Rusak)</th>
                                @endif
                                <th class="text-center">Permintaan Rusak</th>
                                @if($canViewSystemStock)
                                    <th class="text-center">Proyeksi (Bagus / Rusak)</th>
                                @endif
                                <th>Nomor Seri &amp; Konflik</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($breakagePlan['products'] ?? [] as $product)
                                <tr>
                                    <td>
                                        <div class="font-weight-bold">{{ $product['product_name'] }}</div>
                                        <div class="text-muted small">
                                            <code>{{ $product['product_code'] }}</code>
                                            <span class="badge badge-secondary ml-1">{{ $product['base_unit'] }}</span>
                                        </div>
                                    </td>
                                    @if($canViewSystemStock)
                                        <td class="text-center">
                                            {{ $product['current']['good'] ?? '-' }} / {{ $product['current']['bad'] ?? '-' }}
                                        </td>
                                    @endif
                                    <td class="text-center font-weight-bold text-danger">
                                        {{ $product['movement'] ?? 0 }} {{ $product['base_unit'] }}
                                    </td>
                                    @if($canViewSystemStock)
                                        <td class="text-center">
                                            {{ $product['projected']['good'] ?? '-' }} / {{ $product['projected']['bad'] ?? '-' }}
                                        </td>
                                    @endif
                                    <td>
                                        @if(!empty($product['serials']))
                                            <ol class="mb-0 ps-3">
                                                @foreach($product['serials'] as $serial)
                                                    <li>{{ $serial['serial_number'] }}</li>
                                                @endforeach
                                            </ol>
                                        @endif
                                        @if(!empty($product['serial_conflicts']))
                                            <ul class="mb-0 ps-3 text-danger small">
                                                @foreach($product['serial_conflicts'] as $conflict)
                                                    <li>{{ $conflict['serial_number'] ?? 'Nomor seri' }}: {{ $conflict['reason'] }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        @if(empty($product['serials']) && empty($product['serial_conflicts']))
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $canViewSystemStock ? 5 : 3 }}" class="text-center text-muted py-3">Tidak ada produk.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

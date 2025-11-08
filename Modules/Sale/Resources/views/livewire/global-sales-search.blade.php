<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="bi bi-search me-2"></i>
            Pencarian Penjualan Global
        </h5>
    </div>

    <div class="card-body">
        <!-- Search Form -->
        <div class="row mb-3">
            <div class="col-md-8">
                <div class="input-group">
                    <div class="input-group-prepend">
                        <div class="input-group-text">
                            <i class="bi bi-search"></i>
                        </div>
                    </div>
                    <input
                        wire:model.live.debounce.300ms="query"
                        type="text"
                        class="form-control"
                        placeholder="Cari berdasarkan nomor seri, referensi penjualan, atau pelanggan..."
                        autocomplete="off"
                    >
                    <div class="input-group-append">
                        <button
                            wire:click="clearSearch"
                            class="btn btn-outline-secondary"
                            type="button"
                            title="Bersihkan pencarian"
                        >
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <select wire:model.live="searchType" class="form-control">
                    <option value="all">Semua Tipe</option>
                    <option value="serial">Nomor Seri</option>
                    <option value="reference">Referensi Penjualan</option>
                    <option value="customer">Pelanggan</option>
                </select>
            </div>
        </div>

        <!-- Loading Indicator -->
        <div wire:loading class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Mencari...</span>
            </div>
            <p class="mt-2 text-muted">Mencari data penjualan...</p>
        </div>

        <!-- Search Results -->
        @if(!empty($searchResultsData))
            <div class="row mb-3">
                <div class="col-md-6">
                    <p class="mb-0 text-muted">
                        Menampilkan {{ $paginationInfo['from'] ?? 0 }}-{{ $paginationInfo['to'] ?? 0 }}
                        dari {{ $paginationInfo['total'] ?? 0 }} hasil
                    </p>
                </div>
                <div class="col-md-6 text-right">
                    <button
                        wire:click="exportResults"
                        class="btn btn-outline-success btn-sm"
                        type="button"
                    >
                        <i class="bi bi-download"></i> Ekspor
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th wire:click="sortBy('reference')" style="cursor: pointer;">
                                Referensi
                                @if($sortBy === 'reference')
                                    <i class="bi bi-chevron-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </th>
                            <th>Pelanggan</th>
                            <th>Nomor Seri</th>
                            <th>Tenant</th>
                            <th>Penjual</th>
                            <th wire:click="sortBy('status')" style="cursor: pointer;">
                                Status
                                @if($sortBy === 'status')
                                    <i class="bi bi-chevron-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </th>
                                                        <th wire:click="sortBy('created_at')" style="cursor: pointer;">
                                Tanggal
                                @if($sortBy === 'created_at')
                                    <i class="bi bi-chevron-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                @endif
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($searchResultsData as $sale)
                            <tr>
                                <td>
                                    <strong>{{ $sale->reference }}</strong>
                                </td>
                                <td>
                                    {{ $sale->customer->customer_name ?? 'N/A' }}
                                </td>
                                <td>
                                    @php
                                        $serialCount = $sale->dispatchDetails->sum(function($dispatchDetail) {
                                            $serials = json_decode($dispatchDetail->serial_numbers, true);
                                            return is_array($serials) ? count($serials) : 0;
                                        });
                                    @endphp
                                    <span class="badge badge-info">{{ $serialCount }} seri</span>
                                </td>
                                <td>
                                    {{ $sale->tenantSetting->company_name ?? 'N/A' }}
                                </td>
                                <td>
                                    {{ $sale->seller->name ?? 'N/A' }}
                                </td>
                                <td>
                                    <span class="badge badge-{{ $this->getStatusBadgeClass($sale->status) }}">
                                        {{ $sale->status }}
                                    </span>
                                </td>
                                <td>
                                    {{ $sale->created_at->format('M d, Y') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if(isset($paginationInfo['has_pages']) && $paginationInfo['has_pages'])
                <div class="d-flex justify-content-center mt-3">
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            {{-- Previous Page Link --}}
                            @if($paginationInfo['current_page'] > 1)
                                <li class="page-item">
                                    <button wire:click="gotoPage({{ $paginationInfo['current_page'] - 1 }})" class="page-link" type="button">Sebelumnya</button>
                                </li>
                            @else
                                <li class="page-item disabled">
                                    <span class="page-link">Sebelumnya</span>
                                </li>
                            @endif

                            {{-- Page Numbers --}}
                            @for ($i = 1; $i <= $paginationInfo['last_page']; $i++)
                                @if ($i == $paginationInfo['current_page'])
                                    <li class="page-item active"><span class="page-link">{{ $i }}</span></li>
                                @else
                                    <li class="page-item">
                                        <button wire:click="gotoPage({{ $i }})" class="page-link" type="button">{{ $i }}</button>
                                    </li>
                                @endif
                            @endfor

                            {{-- Next Page Link --}}
                            @if($paginationInfo['current_page'] < $paginationInfo['last_page'])
                                <li class="page-item">
                                    <button wire:click="gotoPage({{ $paginationInfo['current_page'] + 1 }})" class="page-link" type="button">Selanjutnya</button>
                                </li>
                            @else
                                <li class="page-item disabled">
                                    <span class="page-link">Selanjutnya</span>
                                </li>
                            @endif
                        </ul>
                    </nav>
                </div>
            @endif

        @elseif(!empty($query))
            <div class="text-center py-5">
                <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                <h5 class="mt-3 text-muted">Tidak ada hasil ditemukan</h5>
                <p class="text-muted">Coba sesuaikan kriteria pencarian</p>
            </div>
        @else
            <div class="text-center py-5">
                <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                <h5 class="mt-3 text-muted">Mulai pencarian</h5>
                <p class="text-muted">Masukkan nomor seri, referensi penjualan, atau nama pelanggan untuk memulai</p>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('livewire:loaded', () => {
    // Auto-focus search input when component loads
    const searchInput = document.querySelector('input[wire\\:model\\.live*="query"]');
    if (searchInput) {
        searchInput.focus();
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+Shift+S to focus search
        if (e.ctrlKey && e.shiftKey && e.key === 'S') {
            e.preventDefault();
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });
});
</script>
@endpush
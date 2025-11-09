<div class="global-search-container">
    <!-- Search Header -->
    <div class="card">
        <div class="card-header">
            <h4 class="card-title">
                <i class="bi bi-search me-2"></i> Pencarian Pembelian dan Penjualan Global
            </h4>
            <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
        </div>

        <div class="card-body">
            <!-- Search Controls -->
            <div class="row mb-3">
                <div class="col-md-8">
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <div class="input-group-text">
                                <i class="bi bi-search"></i>
                            </div>
                        </div>
                        <input type="text"
                               class="form-control @error('query') is-invalid @enderror"
                               wire:model.live.debounce.300ms="query"
                               placeholder="Masukkan kata kunci pencarian..."
                               autocomplete="off">
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

                        @error('query')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- Autocomplete Suggestions -->
                    @if(false && $showSuggestions && !empty($suggestions))
                        <div class="autocomplete-suggestions">
                            <ul class="list-group">
                                @foreach($suggestions as $suggestion)
                                    <li class="list-group-item list-group-item-action"
                                        wire:click="selectSuggestion('{{ $suggestion }}')">
                                        <i class="fas fa-search text-muted mr-2"></i>
                                        {{ $suggestion }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="col-md-4">
                    <select wire:model.live="searchType" class="form-control">
                        @foreach($searchTypes as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- Loading Indicator -->
            <div wire:loading class="text-center py-3">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Mencari...</span>
                </div>
                <p class="mt-2 text-muted">Mencari data...</p>
            </div>

            <!-- Search Stats -->
        @if(!empty($searchResults['results'] ?? []))
            <div class="row mb-3">
                <div class="col-md-6">
                    <p class="mb-0 text-muted">
                        Menampilkan {{ count($searchResults['results'] ?? []) }} dari {{ $totalResults }} hasil
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
        @endif
        </div>
    </div>

    <!-- Search Results -->
    @if(!empty($searchResults['results'] ?? []))
        <div class="row mb-3">
            <div class="col-md-6">
                <p class="mb-0 text-muted">
                    Menampilkan {{ count($searchResults['results'] ?? []) }} dari {{ $totalResults }} hasil
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
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Tipe</th>
                        <th>Referensi</th>
                        <th>Pihak</th>
                        <th>Jumlah</th>
                        <th>Status</th>
                        <th>Lokasi</th>
                        <th>Penjual</th>
                        <th>Tanggal</th>
                        <th>Jumlah Serial</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($searchResults['results'] ?? [] as $result)
                        <tr>
                            <td>
                                <span class="badge badge-{{ $result['type'] === 'purchase' ? 'success' : 'primary' }}">
                                    {{ $result['type'] === 'purchase' ? 'Pembelian' : 'Penjualan' }}
                                </span>
                            </td>
                            <td>
                                <strong>{{ $result['reference'] }}</strong>
                            </td>
                            <td>{{ $result['party_name'] }}</td>
                            <td>{{ number_format($result['amount'], 2) }}</td>
                            <td>
                                <span class="badge badge-{{ $this->getStatusBadgeClass($result['status']) }}">
                                    {{ $this->translateStatus($result['status']) }}
                                </span>
                            </td>
                            <td>{{ $result['location'] ?? '-' }}</td>
                            <td>{{ $result['seller_name'] ?? '-' }}</td>
                            <td>{{ $result['date'] }}</td>
                            <td>
                                @if($result['serial_count'] > 0)
                                    <span class="badge badge-info">
                                        {{ $result['serial_count'] }}
                                    </span>
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    @if($result['type'] === 'purchase')
                                        <a href="{{ route('purchases.show', $result['id']) }}"
                                           class="btn btn-outline-primary"
                                           target="_blank">
                                            <i class="fas fa-eye"></i> Lihat
                                        </a>
                                    @else
                                        <a href="{{ route('sales.show', $result['id']) }}"
                                           class="btn btn-outline-primary"
                                           target="_blank">
                                            <i class="fas fa-eye"></i> Lihat
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
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
            <p class="text-muted">Masukkan kata kunci pencarian untuk memulai</p>
        </div>
    @endif
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
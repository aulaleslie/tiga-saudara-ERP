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
                    <option value="product_name">Nama/Kode Produk</option>
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
                            <th>Tipe</th>
                            <th>Referensi</th>
                            <th>Pelanggan</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Tanggal</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($searchResultsData as $row)
                            <tr>
                                <td>
                                    @if($row['type'] === 'sale')
                                        <span class="badge badge-primary">SALE</span>
                                    @else
                                        <span class="badge badge-warning">POS</span>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ $row['reference'] }}</strong>
                                </td>
                                <td>
                                    {{ $row['customer_name'] }}
                                </td>
                                <td>
                                    {{ format_currency($row['total_amount']) }}
                                </td>
                                <td>
                                    <span class="badge badge-{{ $this->getStatusBadgeClass($row['status']) }}">
                                        {{ $row['status_label'] }}
                                    </span>
                                </td>
                                <td>
                                    {{ \Carbon\Carbon::parse($row['date'])->format('M d, Y') }}
                                </td>
                                <td>
                                    <button
                                        wire:click="viewSale('{{ $row['id'] }}', '{{ $row['type'] }}')"
                                        class="btn btn-sm btn-primary"
                                        title="Lihat Detail"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Detail Modal -->
            <div wire:ignore.self class="modal fade" id="detailModal" tabindex="-1" role="dialog" aria-labelledby="detailModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                    <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
                        <div class="modal-header bg-primary text-white py-3" style="border-radius: 15px 15px 0 0;">
                            <h5 class="modal-title fw-bold" id="detailModalLabel">
                                <i class="bi bi-receipt me-2"></i>
                                Detail {{ ($itemDetails['type'] ?? '') === 'sale' ? 'Penjualan' : 'Transaksi POS' }}
                            </h5>
                            <button type="button" class="close btn-close btn-close-white" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body p-4">
                            @if($itemDetails)
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="p-3 bg-light rounded shadow-sm">
                                            <div class="text-muted small mb-1 text-uppercase fw-bold">Referensi</div>
                                            <div class="h5 mb-3 text-primary"><strong>{{ $itemDetails['reference'] }}</strong></div>
                                            
                                            <div class="text-muted small mb-1 text-uppercase fw-bold">Pelanggan</div>
                                            <div class="h6 mb-0">{{ $itemDetails['customer'] }}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="p-3 bg-light rounded shadow-sm">
                                            <div class="text-muted small mb-1 text-uppercase fw-bold">Tanggal & Waktu</div>
                                            <div class="h6 mb-3">{{ \Carbon\Carbon::parse($itemDetails['date'])->format('d M Y, H:i') }}</div>
                                            
                                            <div class="text-muted small mb-1 text-uppercase fw-bold">Status</div>
                                            <div class="h6 mb-0">
                                                <span class="badge badge-{{ $this->getStatusBadgeClass($itemDetails['status']) }} rounded-pill px-3">
                                                    {{ $itemDetails['status_label'] }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <h6 class="fw-bold mb-3 border-bottom pb-2">Daftar Produk</h6>
                                <div class="table-responsive rounded shadow-sm border">
                                    <table class="table table-hover mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th class="border-0">Produk</th>
                                                <th class="border-0 text-center">Qty</th>
                                                <th class="border-0 text-right">Harga Unit</th>
                                                <th class="border-0 text-right">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($itemDetails['items'] as $item)
                                                <tr>
                                                    <td class="align-middle">
                                                        <div class="fw-bold">{{ $item['product'] }}</div>
                                                        <div class="d-flex align-items-center gap-2 mb-1">
                                                            <small class="text-muted text-uppercase">{{ $item['code'] }}</small>
                                                        </div>
                                                        @if(!empty($item['serials']))
                                                            <div class="mt-1 d-flex flex-wrap gap-1">
                                                                @foreach($item['serials'] as $serial)
                                                                    <span class="badge badge-secondary small font-weight-normal" style="font-size: 0.75rem; background-color: #6c757d; color: white; padding: 0.25rem 0.5rem; border-radius: 4px;">{{ $serial }}</span>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </td>
                                                    <td class="align-middle text-center">{{ $item['quantity'] }}</td>
                                                    <td class="align-middle text-right">{{ format_currency($item['price']) }}</td>
                                                    <td class="align-middle text-right fw-bold">{{ format_currency($item['subtotal']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot class="bg-light fw-bold">
                                            <tr>
                                                <td colspan="3" class="text-right py-3 h5 mb-0">Grand Total:</td>
                                                <td class="text-right text-primary py-3 h5 mb-0 font-weight-bold">{{ format_currency($itemDetails['total']) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            @else
                                <div class="text-center py-5">
                                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                                        <span class="sr-only">Memuat...</span>
                                    </div>
                                    <h5 class="text-muted">Mengambil data detail...</h5>
                                </div>
                            @endif
                        </div>
                        <div class="modal-footer bg-light border-0 p-3" style="border-radius: 0 0 15px 15px;">
                            <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal" data-bs-dismiss="modal">Tutup</button>
                        </div>
                    </div>
                </div>
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
document.addEventListener('livewire:init', () => {
    // Auto-focus search input when component loads
    const searchInput = document.querySelector('input[wire\\:model\\.live*="query"]');
    if (searchInput) {
        searchInput.focus();
    }

    // Modal control
    Livewire.on('open-detail-modal', () => {
        $('#detailModal').modal('show');
    });

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

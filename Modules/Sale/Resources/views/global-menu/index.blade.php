@extends('layouts.app')

@section('title', 'Menu Global - Pencarian Penjualan')

@section('content')
<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-search me-2"></i>
                        Menu Global
                    </h1>
                    <p class="text-muted mb-0">Cari dan kelola pesanan penjualan berdasarkan nomor seri</p>
                </div>
                <div>
                    <a href="{{ route('home') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Kembali ke Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Section -->
    <div class="row">
        <div class="col-12">
            @livewire('sale::global-menu-search')
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('livewire:loaded', () => {
    // Listen for filter applied events
    Livewire.on('filtersApplied', (filters) => {
        console.log('Filter diterapkan:', filters);
        // The search component will handle the actual search
    });

    Livewire.on('filtersCleared', () => {
        console.log('Filter dibersihkan');
    });

    Livewire.on('viewSale', (saleId) => {
        window.open(`{{ url('sales') }}/${saleId}`, '_blank');
    });

    Livewire.on('showSerialNumbers', (saleId) => {
        showSerialNumbersModal(saleId);
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+Shift+F to focus search
        if (e.ctrlKey && e.shiftKey && e.key === 'F') {
            e.preventDefault();
            const searchInput = document.querySelector('input[wire\\:model*="query"]');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });
});

function refreshResults() {
    Livewire.emit('refreshResults');
}

function exportResults() {
    Livewire.emit('exportResults');
}

function showSerialNumbersModal(saleId) {
    $('#serialNumbersModal').modal('show');

    // Load serial numbers via AJAX
    fetch(`{{ url('api/global-menu/sales') }}/${saleId}/serials`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderSerialNumbers(data.data);
            } else {
                $('#serialNumbersModalBody').html('<div class="alert alert-danger">Gagal memuat nomor seri</div>');
            }
        })
        .catch(error => {
            console.error('Error loading serial numbers:', error);
            $('#serialNumbersModalBody').html('<div class="alert alert-danger">Kesalahan memuat nomor seri</div>');
        });
}

function renderSerialNumbers(serials) {
    if (!serials || serials.length === 0) {
        $('#serialNumbersModalBody').html('<div class="alert alert-info">Tidak ada nomor seri ditemukan untuk penjualan ini</div>');
        return;
    }

    let html = '<div class="table-responsive"><table class="table table-hover"><thead><tr>';
    html += '<th>Nomor Seri</th><th>Produk</th><th>Status</th><th>Lokasi</th>';
    html += '</tr></thead><tbody>';

    serials.forEach(serial => {
        html += '<tr>';
        html += `<td><code>${serial.serial_number}</code></td>`;
        html += `<td>${serial.product?.product_name || 'N/A'}</td>`;
        html += `<td><span class="badge badge-info">Dialokasikan</span></td>`;
        html += `<td>${serial.location?.name || 'N/A'}</td>`;
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    $('#serialNumbersModalBody').html(html);
}
</script>
@endpush

@push('styles')
<style>
/* Custom styles for the global menu */
.card-header .card-title {
    font-weight: 600;
}

.badge {
    font-size: 0.75rem;
}

.table th {
    border-top: none;
    font-weight: 600;
    font-size: 0.875rem;
}

.modal-lg {
    max-width: 900px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between {
        flex-direction: column;
        align-items: stretch !important;
    }

    .d-flex.justify-content-between > div {
        margin-bottom: 1rem;
    }

    .btn-group {
        display: flex;
        flex-wrap: wrap;
    }

    .btn-group .btn {
        flex: 1;
        margin-bottom: 0.25rem;
    }
}
</style>
@endpush
@endsection
{{ $dataTable->table() }}

@push('scripts')
{{ $dataTable->scripts() }}

<script>
$(document).ready(function() {
    // Initialize DataTable with custom settings
    $('#global-sales-search-table').DataTable({
        responsive: true,
        autoWidth: false,
        scrollX: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[6, 'desc']], // Urutkan berdasarkan tanggal descending secara default
        columnDefs: [
            { width: '120px', targets: 0 }, // Referensi
            { width: '150px', targets: 1 }, // Pelanggan
            { width: '120px', targets: 2 }, // Nomor Seri
            { width: '120px', targets: 3 }, // Tenant
            { width: '120px', targets: 4 }, // Penjual
            { width: '100px', targets: 5 }, // Status
            { width: '100px', targets: 6 }, // Tanggal
        ]
    });

    // Dengarkan event Livewire
    Livewire.on('refreshDataTable', () => {
        $('#global-sales-search-table').DataTable().ajax.reload();
    });

    // Tangani modal nomor seri
    Livewire.on('showSerialNumbers', (saleId) => {
        // Ini akan membuka modal dengan nomor seri
        console.log('Tampilkan nomor seri untuk penjualan:', saleId);
    });
});
</script>
@endpush
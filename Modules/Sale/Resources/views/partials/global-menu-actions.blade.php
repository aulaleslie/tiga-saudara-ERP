<div class="btn-group" role="group">
    <button type="button"
            class="btn btn-sm btn-outline-info"
            title="Lihat Nomor Seri"
            onclick="showSerialNumbers({{ $data->id }})">
        <i class="bi bi-upc-scan"></i>
    </button>

    @if($data->status === 'APPROVED' || $data->status === 'DRAFTED')
        <a href="{{ route('sales.edit', $data->id) }}"
           class="btn btn-sm btn-outline-warning"
           title="Edit Penjualan"
           target="_blank">
            <i class="bi bi-pencil"></i>
        </a>
    @endif
</div>

<script>
function showSerialNumbers(saleId) {
    // Emit event to parent Livewire component
    Livewire.emit('showSerialNumbers', saleId);
}
</script>
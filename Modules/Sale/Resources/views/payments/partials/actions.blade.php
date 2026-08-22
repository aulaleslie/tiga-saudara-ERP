@php($isEligibleForDelete = $data->isEligibleForDeletion())
@php($globalMode = $globalMode ?? false)

@if($globalMode)
    <!-- Global mode: read-only actions only -->
    <a href="{{ route('sales.global-payments.show', $data->sale->id) }}" class="btn btn-sm btn-info" title="Lihat Penjualan">
        <i class="bi bi-eye"></i>
    </a>
@else
    <!-- Normal mode: detail and direct delete actions -->
    @can('salePayments.access')
        <a href="{{ route('sale-payments.edit', [$data->sale->id, $data->id]) }}" class="btn btn-info btn-sm" title="Lihat Detail Pembayaran">
            <i class="bi bi-eye"></i>
        </a>
    @endcan

    @if(!($data->sale->isArchived()))
        @can('salePayments.delete')
            @if($isEligibleForDelete)
                <button id="delete" class="btn btn-danger btn-sm" title="Hapus Pembayaran" onclick="
                    event.preventDefault();
                    if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                    document.getElementById('destroy{{ $data->id }}').submit()
                    }
                    ">
                    <i class="bi bi-trash"></i>
                    <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('sale-payments.destroy', $data->id) }}" method="POST">
                        @csrf
                        @method('delete')
                    </form>
                </button>
            @endif
        @endcan
    @endif
@endif

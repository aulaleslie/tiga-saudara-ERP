@php($isEligibleForDelete = $data->isEligibleForDeletion())
@php($isArchived = $data->purchase ? $data->purchase->isArchived() : false)
@php($globalMode = $globalMode ?? false)

@if($globalMode)
    <!-- Global mode: read-only actions only -->
    <a href="{{ route('purchases.global-payments.show', $data->purchase->id) }}" class="btn btn-sm btn-info" title="Lihat Pembelian">
        <i class="bi bi-eye"></i>
    </a>
@else
    @can('purchasePayments.access')
        <a href="{{ route('purchase-payments.edit', [$data->purchase->id, $data->id]) }}" class="btn btn-info btn-sm" title="Lihat Detail Pembayaran">
            <i class="bi bi-eye"></i>
        </a>
    @endcan

    @if(!$isArchived)
        @can('purchasePayments.delete')
            @if($isEligibleForDelete)
                <button id="delete" class="btn btn-danger btn-sm" title="Hapus Pembayaran" onclick="
                    event.preventDefault();
                    if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                    document.getElementById('destroy{{ $data->id }}').submit()
                    }
                    ">
                    <i class="bi bi-trash"></i>
                    <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('purchase-payments.destroy', $data->id) }}" method="POST">
                        @csrf
                        @method('delete')
                    </form>
                </button>
            @endif
        @endcan
    @endif
@endif

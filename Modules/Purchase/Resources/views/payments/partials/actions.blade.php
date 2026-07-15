@if(!(isset($globalMode) && $globalMode))
    @can('purchasePayments.edit')
        @if($data->isActive())
            <a href="{{ route('purchase-payments.edit', [$data->purchase->id, $data->id]) }}" class="btn btn-info btn-sm">
                <i class="bi bi-pencil"></i>
            </a>
        @endif
    @endcan

    @can('purchasePayments.delete')
        @if($data->isActive())
            {{-- Show Invalidate button for active payments --}}
            <button class="btn btn-warning btn-sm" onclick="
                event.preventDefault();
                if (confirm('Apakah Anda yakin untuk membatalkan pembayaran ini?')) {
                    document.getElementById('invalidate{{ $data->id }}').submit()
                }
            ">
                <i class="bi bi-x-circle"></i>
            </button>
            <form id="invalidate{{ $data->id }}" class="d-none" action="{{ route('purchase-payments.invalidate', $data->id) }}" method="POST">
                @csrf
            </form>
        @else
            {{-- Show Delete button only for invalidated payments --}}
            <button id="delete" class="btn btn-danger btn-sm" onclick="
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

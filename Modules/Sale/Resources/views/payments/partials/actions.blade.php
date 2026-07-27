@php($hasCredit = ($data->credit_applications_count ?? 0) > 0)
@php($globalMode = $globalMode ?? false)

@if($globalMode)
    <!-- Global mode: read-only actions only -->
    <a href="{{ route('sales.global-payments.show', $data->sale->id) }}" class="btn btn-sm btn-info" title="Lihat Penjualan">
        <i class="bi bi-eye"></i>
    </a>
@else
    <!-- Normal mode: full CRUD actions -->
    @if(!($data->sale->isArchived()))
    @can('salePayments.edit')
        @unless($hasCredit)
            <a href="{{ route('sale-payments.edit', [$data->sale->id, $data->id]) }}" class="btn btn-info btn-sm">
                <i class="bi bi-pencil"></i>
            </a>
        @endunless
    @endcan
    @can('salePayments.delete')
        @unless($hasCredit)
            <button id="delete" class="btn btn-danger btn-sm" onclick="
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
        @endunless
    @endcan
    @endif
@endif

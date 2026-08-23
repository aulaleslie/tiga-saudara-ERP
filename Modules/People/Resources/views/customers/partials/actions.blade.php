@can('customers.edit')
    <a href="{{ route('customers.edit', $data->id) }}" class="btn btn-info btn-sm">
        <i class="bi bi-pencil"></i>
    </a>
@endcan
@can('customers.show')
    <a href="{{ route('customers.show', $data->id) }}" class="btn btn-primary btn-sm">
        <i class="bi bi-eye"></i>
    </a>
@endcan
@if(auth()->user()->can('customers.edit') || auth()->user()->can('customers.delete'))
    @if($data->is_active)
        <button type="button" class="btn btn-warning btn-sm" title="Nonaktifkan Pelanggan" onclick="
            event.preventDefault();
            if (confirm('Nonaktifkan pelanggan &quot;{{ $data->customer_name }}&quot;? Data tidak akan dihapus dan tetap tercatat di transaksi historis.')) {
                document.getElementById('toggle-status-{{ $data->id }}').submit();
            }
        ">
            <i class="bi bi-pause-circle"></i>
        </button>
    @else
        <button type="button" class="btn btn-success btn-sm" title="Aktifkan Kembali" onclick="
            event.preventDefault();
            if (confirm('Aktifkan kembali pelanggan &quot;{{ $data->customer_name }}&quot;?')) {
                document.getElementById('toggle-status-{{ $data->id }}').submit();
            }
        ">
            <i class="bi bi-play-circle"></i>
        </button>
    @endif
    <form id="toggle-status-{{ $data->id }}" class="d-none" action="{{ route('customers.toggle-status', $data->id) }}" method="POST">
        @csrf
        @method('patch')
    </form>
@endif


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
    <form class="d-inline contact-status-form" action="{{ route('customers.toggle-status', $data->id) }}" method="POST"
          data-contact-name="{{ $data->customer_name }}" data-contact-active="{{ $data->is_active ? '1' : '0' }}"
          data-contact-confirm="Data tidak akan dihapus dan tetap tercatat di transaksi historis." data-contact-label="pelanggan">
        @csrf
        @method('patch')
        @if($data->is_active)
            <button type="submit" class="btn btn-warning btn-sm" title="Nonaktifkan Pelanggan"><i class="bi bi-pause-circle"></i></button>
        @else
            <button type="submit" class="btn btn-success btn-sm" title="Aktifkan Kembali"><i class="bi bi-play-circle"></i></button>
        @endif
    </form>
@endif

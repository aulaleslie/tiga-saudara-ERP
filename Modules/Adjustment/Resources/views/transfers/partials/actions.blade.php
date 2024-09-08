@can('show_transfers')
    <a href="{{ route('transfers.show', $data->id) }}" class="btn btn-primary btn-sm">
        <i class="bi bi-eye"></i>
    </a>
@endcan

@if ($data->status === 'PENDING')
    @can('edit_transfers')
        <a href="{{ route('transfers.edit', $data->id) }}" class="btn btn-info btn-sm">
            <i class="bi bi-pencil"></i>
        </a>
    @endcan

    @can('delete_transfers')
        <button id="delete" class="btn btn-danger btn-sm" onclick="
            event.preventDefault();
            if (confirm('Are you sure you want to delete this transfer?')) {
            document.getElementById('destroy{{ $data->id }}').submit()
            }
            ">
            <i class="bi bi-trash"></i>
            <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('transfers.destroy', $data->id) }}"
                  method="POST">
                @csrf
                @method('delete')
            </form>
        </button>
    @endcan
@endif

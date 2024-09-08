<a href="{{ route('businesses.edit', $data->id) }}" class="btn btn-info btn-sm">
    <i class="bi bi-pencil"></i>
</a>
@if($data->id != 1)
    <button class="btn btn-danger btn-sm" onclick="showDeleteModal({{ $data->id }})">
        <i class="bi bi-power"></i>
    </button>
    <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('businesses.destroy', $data->id) }}" method="POST">
        @csrf
        @method('delete')
    </form>
@endif

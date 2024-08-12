@can("brand.edit")
<a href="{{ route('brands.edit', $data->id) }}" class="btn btn-info btn-sm">
    <i class="bi bi-pencil"></i>
</a>
@endcan
@can("brand.delete")
<button class="btn btn-danger btn-sm" onclick="showDeleteModal({{ $data->id }})">
    <i class="bi bi-trash"></i>
</button>
@endcan
<form id="destroy{{ $data->id }}" class="d-none" action="{{ route('brands.destroy', $data->id) }}" method="POST">
    @csrf
    @method('delete')
</form>

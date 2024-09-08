<a href="{{ route('product-categories.edit', $data->id) }}" class="btn btn-info btn-sm">
    <i class="bi bi-pencil"></i>
</a>
<button class="btn btn-danger btn-sm" onclick="showDeleteModal({{ $data->id }})">
    <i class="bi bi-trash"></i>
</button>
<form id="destroy{{ $data->id }}" class="d-none" action="{{ route('product-categories.destroy', $data->id) }}" method="POST">
    @csrf
    @method('delete')
</form>

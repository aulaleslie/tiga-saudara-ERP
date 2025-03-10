@can("users.edit")
<a href="{{ route('users.edit', $data->user_id) }}" class="btn btn-info btn-sm">
    <i class="bi bi-pencil"></i>
</a>
@endcan
@can("users.delete")
<button class="btn btn-danger btn-sm" onclick="showDeleteModal({{ $data->user_id }})">
    <i class="bi bi-trash"></i>
</button>
@endcan
<form id="destroyUsers{{ $data->user_id }}" class="d-none" action="{{ route('users.destroy', $data->user_id) }}" method="POST">
    @csrf
    @method('delete')
</form>

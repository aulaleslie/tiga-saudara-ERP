<a href="{{ route('users.edit', $data->user_id) }}" class="btn btn-info btn-sm">
    <i class="bi bi-pencil"></i>
</a>
<button id="delete" class="btn btn-danger btn-sm" onclick="
    event.preventDefault();
    if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
    document.getElementById('destroy{{ $data->user_id }}').submit();
    }
    ">
    <i class="bi bi-trash"></i>
    <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('users.destroy', $data->user_id) }}"
          method="POST">
        @csrf
        @method('delete')
    </form>
</button>

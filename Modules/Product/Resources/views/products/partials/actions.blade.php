@can('products.edit')
<a href="{{ route('products.edit', $data->id) }}" class="btn btn-info btn-sm">
    <i class="bi bi-pencil"></i>
</a>
@endcan
@can('products.manage_cross_business_prices')
<a href="{{ route('products.cross-business-prices.edit', $data->id) }}" class="btn btn-warning btn-sm" title="Kelola Harga Multi-Bisnis">
    <i class="bi bi-tags"></i>
</a>
@endcan
@can('products.show')
<a href="{{ route('products.show', $data->id) }}" class="btn btn-primary btn-sm">
    <i class="bi bi-eye"></i>
</a>
@endcan
@can('products.delete')
<button id="delete" class="btn btn-danger btn-sm" onclick="
    event.preventDefault();
    if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
        document.getElementById('destroy{{ $data->id }}').submit()
    }
    ">
    <i class="bi bi-trash"></i>
    <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('products.destroy', $data->id) }}" method="POST">
        @csrf
        @method('delete')
    </form>
</button>
@endcan

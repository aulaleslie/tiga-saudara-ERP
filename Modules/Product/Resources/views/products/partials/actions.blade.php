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
@can('products.convert_existing_stock_to_serialized')
@if($data->stock_managed && !$data->serial_number_required && $data->product_quantity > 0)
<a href="{{ route('products.convert-to-serialized.show', $data->id) }}" class="btn btn-dark btn-sm" title="Konversi Stok ke Serial Number">
    <i class="bi bi-upc-scan"></i>
</a>
@endif
@endcan
@can('products.show')
<a href="{{ route('products.show', $data->id) }}" class="btn btn-primary btn-sm">
    <i class="bi bi-eye"></i>
</a>
@endcan
@if(auth()->user()->can('products.edit') || auth()->user()->can('products.delete'))
    @if($data->is_active)
        <button type="button" class="btn btn-warning btn-sm" title="Nonaktifkan Produk" onclick="
            event.preventDefault();
            if (confirm('Nonaktifkan produk &quot;{{ $data->product_name }}&quot;? Produk tidak akan muncul untuk transaksi baru, namun data historis tetap aman.')) {
                document.getElementById('toggle-status-{{ $data->id }}').submit();
            }
        ">
            <i class="bi bi-pause-circle"></i>
        </button>
    @else
        <button type="button" class="btn btn-success btn-sm" title="Aktifkan Kembali" onclick="
            event.preventDefault();
            if (confirm('Aktifkan kembali produk &quot;{{ $data->product_name }}&quot;?')) {
                document.getElementById('toggle-status-{{ $data->id }}').submit();
            }
        ">
            <i class="bi bi-play-circle"></i>
        </button>
    @endif
    <form id="toggle-status-{{ $data->id }}" class="d-none" action="{{ route('products.toggle-status', $data->id) }}" method="POST">
        @csrf
        @method('patch')
    </form>
@endif


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
    <form class="d-inline product-status-form"
          action="{{ route('products.toggle-status', $data->id) }}"
          method="POST"
          data-product-name="{{ $data->product_name }}"
          data-product-active="{{ $data->is_active ? '1' : '0' }}">
        @csrf
        @method('patch')

        @if($data->is_active)
        <button type="submit" class="btn btn-warning btn-sm" title="Nonaktifkan Produk">
            <i class="bi bi-pause-circle"></i>
        </button>
        @else
        <button type="submit" class="btn btn-success btn-sm" title="Aktifkan Kembali">
            <i class="bi bi-play-circle"></i>
        </button>
        @endif
    </form>
@endif

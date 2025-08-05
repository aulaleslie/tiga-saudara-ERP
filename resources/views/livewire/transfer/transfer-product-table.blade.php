{{-- resources/views/livewire/transfer/transfer-product-table.blade.php --}}
<div>
    @if (session()->has('message'))
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="close" data-dismiss="alert">×</button>
        </div>
    @endif

    <div class="table-responsive position-relative">
        <div wire:loading class="overlay">
            <div class="spinner-border text-primary" role="status"></div>
        </div>

        <table class="table table-bordered">
            <thead>
            <tr class="align-middle">
                <th>#</th>
                <th>Nama Produk</th>
                <th>Stok</th>
                <th>Jumlah Pajak</th>
                <th>Jumlah Non Pajak</th>
                <th>Rusak Pajak</th>
                <th>Rusak Non Pajak</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($products as $i => $p)
                <tr>
                    <td>{{ $i + 1 }}</td>

                    <td>
                        {{ $p['product_name'] }}
                        <div>
                            <span class="badge badge-secondary">
                                {{ $p['product_code'] }}
                            </span>
                        </div>
                    </td>

                    <td class="text-center">
                        <span
                            class="badge badge-info"
                            title="Pajak: {{ $p['stock']['quantity_tax'] }} | Non Pajak: {{ $p['stock']['quantity_non_tax'] }} | Rusak Pajak: {{ $p['stock']['broken_quantity_tax'] }} | Rusak Non Pajak: {{ $p['stock']['broken_quantity_non_tax'] }}">
                            {{ $p['stock']['total'] }}
                        </span>
                    </td>

                    <td>
                        <input
                            type="number"
                            min="0"
                            class="form-control"
                            name="quantity_tax[]"
                            wire:model.defer="products.{{ $i }}.quantity_tax"
                        >
                    </td>
                    <td>
                        <input
                            type="number"
                            min="0"
                            class="form-control"
                            name="quantity_non_tax[]"
                            wire:model.defer="products.{{ $i }}.quantity_non_tax"
                        >
                    </td>
                    <td>
                        <input
                            type="number"
                            min="0"
                            class="form-control"
                            name="broken_quantity_tax[]"
                            wire:model.defer="products.{{ $i }}.broken_quantity_tax"
                        >
                    </td>
                    <td>
                        <input
                            type="number"
                            min="0"
                            class="form-control"
                            name="broken_quantity_non_tax[]"
                            wire:model.defer="products.{{ $i }}.broken_quantity_non_tax"
                        >
                    </td>

                    <td class="text-center">
                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            wire:click="removeProduct({{ $i }})"
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted">
                        Silahkan cari dan pilih produk!
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown rounded" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu">
        @can('purchase.create')
            @if($data->status === 'RECEIVED')
                <a href="{{ route('purchase-payments.index', $data->id) }}" class="dropdown-item">
                    <i class="bi bi-cash-coin mr-2 text-warning" style="line-height: 1;"></i> Lihat Pembayaran
                </a>
            @endif

            @if($data->status === 'RECEIVED' && $data->due_amount > 0)
                <a href="{{ route('purchase-payments.create', $data->id) }}" class="dropdown-item">
                    <i class="bi bi-plus-circle-dotted mr-2 text-success" style="line-height: 1;"></i> Buat Pembayaran
                </a>
            @endif
        @endcan

        @can('purchase.edit')
            @if($data->status === 'DRAFTED')
                <a href="{{ route('purchases.edit', $data->id) }}" class="dropdown-item">
                    <i class="bi bi-pencil mr-2 text-primary" style="line-height: 1;"></i> Ubah
                </a>
            @endif
        @endcan

        @can('purchase.view')
            <a href="{{ route('purchases.show', $data->id) }}" class="dropdown-item">
                <i class="bi bi-eye mr-2 text-info" style="line-height: 1;"></i> Rincian
            </a>
        @endcan

        @can('purchase.delete')
            @if($data->status === 'DRAFTED')
                <button id="delete" class="dropdown-item" onclick="
                    event.preventDefault();
                    if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                    document.getElementById('destroy{{ $data->id }}').submit()
                    }">
                    <i class="bi bi-trash mr-2 text-danger" style="line-height: 1;"></i> Hapus
                    <form id="destroy{{ $data->id }}" class="d-none"
                          action="{{ route('purchases.destroy', $data->id) }}" method="POST">
                        @csrf
                        @method('delete')
                    </form>
                </button>
            @endif
        @endcan

        {{-- New Actions for Status Updates --}}
        @if ($data->status === 'DRAFTED')
            <form method="POST" action="{{ route('purchases.updateStatus', $data->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="WAITING_APPROVAL">
                <button type="submit" class="dropdown-item text-warning">
                    <i class="bi bi-send mr-2"></i> Kirim untuk Persetujuan
                </button>
            </form>
        @endif

        @if ($data->status === 'WAITING_APPROVAL')
            <form method="POST" action="{{ route('purchases.updateStatus', $data->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="APPROVED">
                <button type="submit" class="dropdown-item text-success">
                    <i class="bi bi-check-circle mr-2"></i> Setuju
                </button>
            </form>
            <form method="POST" action="{{ route('purchases.updateStatus', $data->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="REJECTED">
                <button type="submit" class="dropdown-item text-danger">
                    <i class="bi bi-x-circle mr-2"></i> Tolak
                </button>
            </form>
        @endif

        @if ($data->status === 'APPROVED' || $data->status === 'RECEIVED_PARTIALLY')
            <a href="{{ route('purchases.receive', $data->id) }}" class="dropdown-item text-primary">
                <i class="bi bi-box-arrow-in-down mr-2"></i> Menerima
            </a>
        @endif
    </div>
</div>

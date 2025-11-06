@can('saleReturnPayments.access')
    <a href="{{ route('sale-returns.show', $data->saleReturn->id) }}" class="btn btn-info btn-sm" title="Lihat Detail">
        <i class="bi bi-eye"></i>
    </a>
@endcan
@can('saleReturnPayments.edit')
    <a href="{{ route('sale-returns.settlement', $data->saleReturn->id) }}" class="btn btn-warning btn-sm" title="Kelola Penyelesaian">
        <i class="bi bi-gear"></i>
    </a>
@endcan

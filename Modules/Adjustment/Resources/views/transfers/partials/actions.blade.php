@php $isV3 = (int) $data->workflow_version === \Modules\Adjustment\Entities\Transfer::WORKFLOW_V3; @endphp
@can('stockTransfers.show')
    <a href="{{ route('transfers.show', $data->id) }}" class="btn btn-primary btn-sm" title="Detail">
        <i class="bi bi-eye"></i>
    </a>
@endcan

@if (in_array($data->status, ['DRAFT', 'PENDING'], true))
    @can('stockTransfers.edit')
        <a href="{{ route('transfers.edit', $data->id) }}" class="btn btn-info btn-sm" title="Ubah">
            <i class="bi bi-pencil"></i>
        </a>
    @endcan
@endif

@if ($isV3 && $data->status === 'DRAFT')
    @can('stockTransfers.edit')
        <form action="{{ route('transfers.v3.submit', $data->id) }}" method="POST" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-success btn-sm" title="Ajukan Persetujuan">
                <i class="bi bi-send"></i>
            </button>
        </form>
    @endcan
@endif

@if ($isV3 && $data->status === 'PENDING')
    @can('stockTransfers.approval')
        <a href="{{ route('transfers.v3.approval', $data->id) }}" class="btn btn-warning btn-sm" title="Alokasi &amp; Persetujuan">
            <i class="bi bi-diagram-3"></i>
        </a>
    @endcan
@endif

@if (! $isV3 && $data->status === 'PENDING')
    @can('stockTransfers.delete')
        <button id="delete" class="btn btn-danger btn-sm" onclick="
            event.preventDefault();
            if (confirm('Are you sure you want to delete this transfer?')) {
            document.getElementById('destroy{{ $data->id }}').submit()
            }
            ">
            <i class="bi bi-trash"></i>
            <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('transfers.destroy', $data->id) }}"
                  method="POST">
                @csrf
                @method('delete')
            </form>
        </button>
    @endcan
@endif

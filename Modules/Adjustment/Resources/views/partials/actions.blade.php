@can('adjustments.show')
    <a href="{{ route('adjustments.show', $data->id) }}" class="btn btn-primary btn-sm">
        <i class="bi bi-eye"></i>
    </a>
@endcan

@php
    $dataStatus = $data->status instanceof \Modules\Adjustment\Entities\AdjustmentStatus
        ? $data->status->value
        : strtoupper((string) $data->status);
@endphp
@if ($dataStatus !== \Modules\Adjustment\Entities\AdjustmentStatus::Approved->value && $dataStatus !== \Modules\Adjustment\Entities\AdjustmentStatus::Rejected->value)
    @canany(['adjustments.edit', 'adjustments.breakage.edit'])
        <a href="{{ $data->type === 'breakage' ? route('adjustments.editBreakage', $data->id) : route('adjustments.edit', $data->id) }}"
           class="btn btn-info btn-sm">
            <i class="bi bi-pencil"></i>
        </a>
    @endcan

    @can('adjustments.delete')
        <button id="delete" class="btn btn-danger btn-sm" onclick="
            event.preventDefault();
            if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
            document.getElementById('destroy{{ $data->id }}').submit()
            }
            ">
            <i class="bi bi-trash"></i>
            <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('adjustments.destroy', $data->id) }}"
                  method="POST">
                @csrf
                @method('delete')
            </form>
        </button>
    @endcan
@endif

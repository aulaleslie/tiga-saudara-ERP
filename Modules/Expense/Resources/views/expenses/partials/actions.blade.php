<div class="dropdown">
    <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" id="dropdownMenuButton{{$data->id}}" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="bi bi-gear"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownMenuButton{{$data->id}}">
        <a class="dropdown-item" href="{{ route('expenses.show', $data->id) }}">
            <i class="bi bi-eye"></i> Lihat
        </a>
        
        @if(in_array($data->status, [\Modules\Expense\Entities\Expense::STATUS_DRAFT, \Modules\Expense\Entities\Expense::STATUS_REJECTED]))
            @can('expenses.edit')
                <a class="dropdown-item" href="{{ route('expenses.edit', $data->id) }}">
                    <i class="bi bi-pencil"></i> Edit
                </a>
            @endcan
            @can('expenses.create')
                <form action="{{ route('expenses.submit', $data->id) }}" method="POST">
                    @csrf
                    <button class="dropdown-item" type="submit"><i class="bi bi-send"></i> Ajukan</button>
                </form>
            @endcan
            @can('expenses.delete')
                <form action="{{ route('expenses.destroy', $data->id) }}" method="POST" onsubmit="return confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')">
                    @csrf
                    @method('delete')
                    <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash"></i> Hapus</button>
                </form>
            @endcan
        @endif

        @if($data->status === \Modules\Expense\Entities\Expense::STATUS_SUBMITTED)
            @can('expenses.approval')
                <form action="{{ route('expenses.approve', $data->id) }}" method="POST">
                    @csrf
                    <button class="dropdown-item text-success" type="submit"><i class="bi bi-check-circle"></i> Setujui</button>
                </form>
                <button class="dropdown-item text-warning" type="button" onclick="showRejectModal({{ $data->id }})">
                    <i class="bi bi-x-circle"></i> Tolak
                </button>
            @endcan
        @endif

        @if(in_array($data->status, [\Modules\Expense\Entities\Expense::STATUS_SUBMITTED, \Modules\Expense\Entities\Expense::STATUS_APPROVED]) && empty($data->archived_at))
            @can('expenses.archive')
                <button class="dropdown-item" type="button" onclick="showArchiveModal({{ $data->id }}, '{{ $data->status }}')">
                    <i class="bi bi-archive"></i> Arsip
                </button>
            @endcan
        @endif
    </div>
</div>

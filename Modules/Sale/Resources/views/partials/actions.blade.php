@php $id = $data->id; @endphp

<div x-data="{
    open: false,
    position: { top: 0, left: 0 },
    updatePosition() {
        if (!this.$refs.trigger) return;
        const rect = this.$refs.trigger.getBoundingClientRect();
        this.position = {
            top: rect.bottom + window.scrollY,
            left: rect.right - 200 // Align with right edge, assuming menu width ~200px
        };
    },
    toggle() {
        if (!this.open) {
            this.updatePosition();
            // Refine after a tick to ensure layout is settled
            setTimeout(() => this.updatePosition(), 0);
        }
        this.open = !this.open;
    }
}" x-init="
    const scrollHandler = () => { if (open) updatePosition() };
    window.addEventListener('scroll', scrollHandler, true);
    window.addEventListener('resize', scrollHandler);
" @click.away="open = false" class="d-inline-block">

    <button type="button"
            x-ref="trigger"
            @click="toggle()"
            class="btn btn-ghost-primary"
            style="padding: 0.25rem 0.5rem; border: none; background: transparent;">
        <i class="bi bi-three-dots-vertical"></i>
    </button>

    <template x-teleport="body">
        <div x-show="open"
             x-cloak
             class="dropdown-menu shadow-sm show"
             :style="`position: absolute; z-index: 1060; top: ${position.top}px; left: ${position.left}px; min-width: 200px; margin: 0; display: block;`"
             @click.away="open = false">
            @if(!($showArchived ?? false))
            @can('salePayments.show')
                <a href="{{ route('sale-payments.index', $data->id) }}" class="dropdown-item">
                    <i class="bi bi-cash-coin mr-2 text-warning" style="line-height: 1;"></i> Show Payments
                </a>
            @endcan
            @can('salePayments.create')
                @if($data->due_amount > 0)
                    <a href="{{ route('sale-payments.create', $data->id) }}" class="dropdown-item">
                        <i class="bi bi-plus-circle-dotted mr-2 text-success" style="line-height: 1;"></i> Add Payment
                    </a>
                @endif
            @endcan
            @can('sales.edit')
                @if ($data->status === 'DRAFTED')
                    <a href="{{ route('sales.edit', $data->id) }}" class="dropdown-item">
                        <i class="bi bi-pencil mr-2 text-primary" style="line-height: 1;"></i> Edit
                    </a>
                @endif
            @endcan
            @endif
            @can('sales.show')
                <a href="{{ route('sales.show', $data->id) }}" class="dropdown-item">
                    <i class="bi bi-eye mr-2 text-info" style="line-height: 1;"></i> Details
                </a>
            @endcan
            @if(!($showArchived ?? false))
            @can('sales.edit')
                @if ($data->status === 'DRAFTED')
                    <form method="POST" action="{{ route('sales.updateStatus', $data->id) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="WAITING_APPROVAL">
                        <button type="submit" class="dropdown-item text-warning">
                            <i class="bi bi-send mr-2"></i> Kirim untuk Persetujuan
                        </button>
                    </form>
                @endif
            @endcan


            @can('sales.approval')
                @if ($data->status === 'WAITING_APPROVAL')
                    <form method="POST" action="{{ route('sales.updateStatus', $data->id) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="APPROVED">
                        <button type="submit" class="dropdown-item text-success">
                            <i class="bi bi-check-circle mr-2"></i> Setuju
                        </button>
                    </form>
                    <form method="POST" action="{{ route('sales.updateStatus', $data->id) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="REJECTED">
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-x-circle mr-2"></i> Tolak
                        </button>
                    </form>
                @endif
            @endcan
            @if($data->status === 'DRAFTED' || $data->status === 'REJECTED')
                @can('sales.delete')
                    <button id="delete" class="dropdown-item" onclick="
                        event.preventDefault();
                        if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                        document.getElementById('destroy{{ $data->id }}').submit()
                        }">
                        <i class="bi bi-trash mr-2 text-danger" style="line-height: 1;"></i> Delete
                        <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('sales.destroy', $data->id) }}" method="POST">
                            @csrf
                            @method('delete')
                        </form>
                    </button>
                @endcan
            @elseif($data->status === 'APPROVED')
                @can('sales.archive')
                    @if(!in_array($data->status, ['DISPATCHED', 'DISPATCHED PARTIALLY']))
                        <button id="archive" class="dropdown-item" onclick="
                            event.preventDefault();
                            if (confirm('Anda Yakin untuk Mengarsipkan?')) {
                            document.getElementById('archive{{ $data->id }}').submit()
                            }">
                            <i class="bi bi-archive mr-2 text-warning" style="line-height: 1;"></i> Arsipkan
                            <form id="archive{{ $data->id }}" class="d-none"
                                  action="{{ route('sales.archive', $data->id) }}" method="POST">
                                @csrf
                                @method('put')
                            </form>
                        </button>
                    @endif
                @endcan
            @endif
            @can('sales.dispatch')
                @if ($data->status === 'APPROVED' || $data->status === 'RECEIVED_PARTIALLY')
                    <a href="{{ route('sales.dispatch', $data->id) }}" class="dropdown-item text-primary">
                        <i class="bi bi-box-arrow-in-down mr-2"></i> Pengeluaran Barang
                    </a>
                @endif
            @endcan
            @endif
        </div>
    </template>
</div>

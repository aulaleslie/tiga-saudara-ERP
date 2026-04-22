@php $approvalStatus = strtolower($data->approval_status ?? ''); @endphp
@php $dispatchStatus = strtolower($data->return_dispatch_status ?? ''); @endphp

<div x-data="{
    open: false,
    position: { top: 0, left: 0 },
    updatePosition() {
        const rect = this.$refs.trigger.getBoundingClientRect();
        this.position = {
            top: rect.bottom,
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
             :style="`position: fixed; z-index: 1060; top: ${position.top}px; left: ${position.left}px; min-width: 200px; margin: 0; display: block;`"
             @click.away="open = false">
            
            @can('purchaseReturns.update')
                @if($dispatchStatus !== 'dispatched')
                    <a href="{{ route('purchase-returns.edit', $data->id) }}" class="dropdown-item d-flex align-items-center">
                        <i class="bi bi-pencil text-primary me-2"></i> <span>Edit</span>
                    </a>
                @endif
            @endcan

            @can('purchaseReturns.approval')
                @if($approvalStatus === 'pending')
                    <a href="#" class="dropdown-item d-flex align-items-center" onclick="event.preventDefault(); openApproveModal('{{ route('purchase-returns.approve', $data->id) }}');">
                        <i class="bi bi-check2-circle text-success me-2"></i> <span>Setujui</span>
                    </a>
                    <a href="#" class="dropdown-item d-flex align-items-center" onclick="event.preventDefault(); openRejectModal('{{ route('purchase-returns.reject', $data->id) }}');">
                        <i class="bi bi-x-circle text-danger me-2"></i> <span>Tolak</span>
                    </a>
                @endif
            @endcan

            @can('purchaseReturns.show')
                <a href="{{ route('purchase-returns.show', $data->id) }}" class="dropdown-item d-flex align-items-center">
                    <i class="bi bi-eye text-info me-2"></i> <span>Detail</span>
                </a>
            @endcan

            @if($dispatchStatus === 'dispatched')
                @php
                    $settlementItems = $data->settlementItems ?? collect();
                    $hasUnapprovedItems = $settlementItems->filter(function($item) {
                        return !in_array(strtoupper($item->status), ['APPROVED', 'RECEIVED']);
                    })->isNotEmpty();
                @endphp
                @if($hasUnapprovedItems || $settlementItems->isEmpty())
                    @can('purchaseReturnSettlements.submit')
                        <a href="{{ route('purchase-returns.settlement', $data->id) }}" class="dropdown-item d-flex align-items-center">
                            <i class="bi bi-arrow-repeat text-primary me-2"></i> <span>Kelola Penyelesaian</span>
                        </a>
                    @endcan
                @endif
            @endif

            @if($approvalStatus === 'rejected')
                @can('purchaseReturns.update')
                    <a href="#" class="dropdown-item d-flex align-items-center" onclick="event.preventDefault(); if(confirm('Ajukan ulang retur ini?')) document.getElementById('repropose{{ $data->id }}').submit();">
                        <i class="bi bi-arrow-counterclockwise text-info me-2"></i> <span>Ajukan Ulang</span>
                    </a>
                    <form id="repropose{{ $data->id }}" class="d-none" action="{{ route('purchase-returns.repropose', $data->id) }}" method="POST">
                        @csrf
                    </form>
                @endcan
            @endif

            @if($approvalStatus === 'pending' || $approvalStatus === 'rejected' || $approvalStatus === 'draft')
                @can('purchaseReturns.delete')
                    <button id="delete" type="button" class="dropdown-item d-flex align-items-center" onclick="
                        event.preventDefault();
                        if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                            document.getElementById('destroy{{ $data->id }}').submit()
                        }">
                        <i class="bi bi-trash text-danger me-2"></i> <span>Hapus</span>
                    </button>
                    <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('purchase-returns.destroy', $data->id) }}" method="POST">
                        @csrf
                        @method('delete')
                    </form>
                @endcan
            @elseif($approvalStatus === 'approved')
                @can('purchaseReturns.archive')
                    @if(is_null($data->return_dispatched_at))
                        <button id="archive" type="button" class="dropdown-item d-flex align-items-center" onclick="
                            event.preventDefault();
                            if (confirm('Anda Yakin untuk Mengarsipkan?')) {
                                document.getElementById('archive{{ $data->id }}').submit()
                            }">
                            <i class="bi bi-archive text-warning me-2"></i> <span>Arsipkan</span>
                        </button>
                        <form id="archive{{ $data->id }}" class="d-none" action="{{ route('purchase-returns.archive', $data->id) }}" method="POST">
                            @csrf
                            @method('put')
                        </form>
                    @endif
                @endcan
            @endif
        </div>
    </template>
</div>

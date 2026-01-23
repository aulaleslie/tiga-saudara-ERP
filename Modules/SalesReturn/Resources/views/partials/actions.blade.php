@php
    $approvalStatus = strtolower($data->approval_status ?? '');
    $status = strtolower($data->status ?? '');
@endphp
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
            @can('saleReturns.edit')
                @if(in_array($approvalStatus, ['pending', 'draft']))
                    <a href="{{ route('sale-returns.edit', $data->id) }}" class="dropdown-item d-flex align-items-center" @click="open = false">
                        <i class="bi bi-pencil text-primary me-2"></i> <span>Edit</span>
                    </a>
                @endif
            @endcan

            @can('saleReturns.approve')
                @if($approvalStatus === 'pending')
                    <form method="POST" action="{{ route('sale-returns.approve', $data->id) }}" class="m-0">
                        @csrf
                        <button type="submit" class="dropdown-item d-flex align-items-center border-0 bg-transparent" onclick="return confirm('Setujui retur penjualan ini?')" @click="open = false">
                            <i class="bi bi-check2-circle text-success me-2"></i> <span>Setujui</span>
                        </button>
                    </form>

                    <a href="#" class="dropdown-item d-flex align-items-center" onclick="event.preventDefault(); saleReturnReject{{ $data->id }}();" @click="open = false">
                        <i class="bi bi-x-circle text-danger me-2"></i> <span>Tolak</span>
                    </a>
                    <form id="sale-return-reject-{{ $data->id }}" method="POST" action="{{ route('sale-returns.reject', $data->id) }}" class="d-none">
                        @csrf
                        <input type="hidden" name="reason" value="">
                    </form>
                    <script>
                        function saleReturnReject{{ $data->id }}() {
                            const reason = prompt('Masukkan alasan penolakan (opsional):');
                            if (reason !== null) {
                                const form = document.getElementById('sale-return-reject-{{ $data->id }}');
                                form.querySelector('input[name="reason"]').value = reason;
                                form.submit();
                            }
                        }
                    </script>
                @endif
            @endcan

            @can('saleReturns.show')
                <a href="{{ route('sale-returns.show', $data->id) }}" class="dropdown-item d-flex align-items-center" @click="open = false">
                    <i class="bi bi-eye text-info me-2"></i> <span>Detail</span>
                </a>
            @endcan

            @can('saleReturns.edit')
                @if($approvalStatus === 'approved')
                    <a href="{{ route('sale-returns.settlement', $data->id) }}" class="dropdown-item d-flex align-items-center" @click="open = false">
                        <i class="bi bi-clipboard-check text-success me-2"></i> <span>Penyelesaian</span>
                    </a>
                @endif
            @endcan

            @can('saleReturns.receive')
                @if($status === 'awaiting receiving')
                    <form method="POST" action="{{ route('sale-returns.receive', $data->id) }}" class="m-0">
                        @csrf
                        <button type="submit" class="dropdown-item d-flex align-items-center border-0 bg-transparent" onclick="return confirm('Terima barang retur ini ke stok?')" @click="open = false">
                            <i class="bi bi-box-arrow-in-down text-primary me-2"></i> <span>Terima Barang</span>
                        </button>
                    </form>
                @endif
            @endcan

            @can('salePayments.show')
                <a href="{{ route('sale-return-payments.index', $data->id) }}" class="dropdown-item d-flex align-items-center" @click="open = false">
                    <i class="bi bi-cash-coin text-warning me-2"></i> <span>Pembayaran</span>
                </a>
            @endcan

            @if(in_array($approvalStatus, ['pending', 'rejected', 'draft']))
                @can('saleReturns.delete')
                    <button id="delete" type="button" class="dropdown-item d-flex align-items-center" onclick="
                        event.preventDefault();
                        if (confirm('Anda yakin ingin menghapus retur ini?')) {
                            document.getElementById('destroy{{ $data->id }}').submit()
                        }" @click="open = false">
                        <i class="bi bi-trash text-danger me-2"></i> <span>Hapus</span>
                    </button>
                    <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('sale-returns.destroy', $data->id) }}" method="POST">
                        @csrf
                        @method('delete')
                    </form>
                @endcan
            @elseif($approvalStatus === 'approved')
                @can('saleReturns.archive')
                    @if(is_null($data->received_at))
                        <button id="archive" type="button" class="dropdown-item d-flex align-items-center" onclick="
                            event.preventDefault();
                            if (confirm('Anda Yakin untuk Mengarsipkan?')) {
                                document.getElementById('archive{{ $data->id }}').submit()
                            }" @click="open = false">
                            <i class="bi bi-archive text-warning me-2"></i> <span>Arsipkan</span>
                        </button>
                        <form id="archive{{ $data->id }}" class="d-none" action="{{ route('sale-returns.archive', $data->id) }}" method="POST">
                            @csrf
                            @method('put')
                        </form>
                    @endif
                @endcan
            @endif
        </div>
    </template>
</div>

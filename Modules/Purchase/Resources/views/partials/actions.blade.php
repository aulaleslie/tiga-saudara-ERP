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
            @php $isArchived = $data->isArchived(); @endphp

            @if (!$isArchived && $data->status === 'REJECTED')
                <form method="POST" action="{{ route('purchases.updateStatus', $data->id) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="DRAFTED">
                    <button type="submit" class="dropdown-item text-info" @click="open = false">
                        <i class="bi bi-arrow-counterclockwise mr-2"></i> Mengerti / Perbaiki (Draft)
                    </button>
                </form>
            @endif

            @if (!$isArchived)
                @can('purchases.create')
                    @if($data->status === 'RECEIVED')
                        <a href="{{ route('purchase-payments.index', $data->id) }}" class="dropdown-item" @click="open = false">
                            <i class="bi bi-cash-coin mr-2 text-warning" style="line-height: 1;"></i> Lihat Pembayaran
                        </a>
                    @endif

                    @if($data->status === 'RECEIVED' && $data->due_amount > 0)
                        <a href="{{ route('purchase-payments.create', $data->id) }}" class="dropdown-item" @click="open = false">
                            <i class="bi bi-plus-circle-dotted mr-2 text-success" style="line-height: 1;"></i> Buat Pembayaran
                        </a>
                    @endif
                @endcan

                @can('purchases.update')
                    @if($data->status === 'DRAFTED')
                        <a href="{{ route('purchases.edit', $data->id) }}" class="dropdown-item" @click="open = false">
                            <i class="bi bi-pencil mr-2 text-primary" style="line-height: 1;"></i> Ubah
                        </a>
                    @endif
                @endcan
            @endif

            @can('purchases.show')
                <a href="{{ route('purchases.show', $data->id) }}" class="dropdown-item" @click="open = false">
                    <i class="bi bi-eye mr-2 text-info" style="line-height: 1;"></i> Rincian
                </a>
            @endcan

            @can('purchases.update')
                <a href="{{ route('purchases.show', $data->id) }}#purchase-attachments" class="dropdown-item" @click="open = false">
                    <i class="bi bi-paperclip mr-2 text-secondary" style="line-height: 1;"></i> Lampiran
                </a>
            @endcan

            @can('purchases.create')
                <a href="{{ route('purchases.create', ['duplicate' => $data->id]) }}" class="dropdown-item" @click="open = false">
                    <i class="bi bi-files mr-2 text-secondary" style="line-height: 1;"></i> Duplikat
                </a>
            @endcan

            @if (!$isArchived)
                @if($data->status === 'DRAFTED' || $data->status === 'REJECTED')
                    @can('purchases.delete')
                        <button id="delete" class="dropdown-item" onclick="
                            event.preventDefault();
                            if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                            document.getElementById('destroy{{ $data->id }}').submit()
                            }" @click="open = false">
                            <i class="bi bi-trash mr-2 text-danger" style="line-height: 1;"></i> Hapus
                            <form id="destroy{{ $data->id }}" class="d-none"
                                  action="{{ route('purchases.destroy', $data->id) }}" method="POST">
                                @csrf
                                @method('delete')
                            </form>
                        </button>
                    @endcan
                @elseif($data->status === 'APPROVED')
                    @can('purchases.archive')
                        @if(!in_array($data->status, ['RECEIVED', 'RECEIVED PARTIALLY']))
                            <button id="archive" class="dropdown-item" onclick="
                                event.preventDefault();
                                if (confirm('Anda Yakin untuk Mengarsipkan?')) {
                                document.getElementById('archive{{ $data->id }}').submit()
                                }" @click="open = false">
                                <i class="bi bi-archive mr-2 text-warning" style="line-height: 1;"></i> Arsipkan
                                <form id="archive{{ $data->id }}" class="d-none"
                                      action="{{ route('purchases.archive', $data->id) }}" method="POST">
                                    @csrf
                                    @method('put')
                                </form>
                            </button>
                        @endif
                    @endcan
                @endif

                {{-- New Actions for Status Updates --}}
                @if ($data->status === 'DRAFTED')
                    <form method="POST" action="{{ route('purchases.updateStatus', $data->id) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="WAITING_APPROVAL">
                        <button type="submit" class="dropdown-item text-warning" @click="open = false">
                            <i class="bi bi-send mr-2"></i> Kirim untuk Persetujuan
                        </button>
                    </form>
                @endif

                @if ($data->status === 'WAITING_APPROVAL')
                    <form method="POST" action="{{ route('purchases.updateStatus', $data->id) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="APPROVED">
                        <button type="submit" class="dropdown-item text-success" @click="open = false">
                            <i class="bi bi-check-circle mr-2"></i> Setuju
                        </button>
                    </form>
                    <form method="POST" action="{{ route('purchases.updateStatus', $data->id) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="REJECTED">
                        <button type="submit" class="dropdown-item text-danger" @click="open = false">
                            <i class="bi bi-x-circle mr-2"></i> Tolak
                        </button>
                    </form>
                @endif

                @if ($data->status === 'APPROVED' || $data->status === 'RECEIVED_PARTIALLY')
                    @can('purchases.receive')
                    <a href="{{ route('purchases.receive', $data->id) }}" class="dropdown-item text-primary" @click="open = false">
                        <i class="bi bi-box-arrow-in-down mr-2"></i> Menerima
                    </a>
                    @endcan
                @endif
            @endif
        </div>
    </template>
</div>

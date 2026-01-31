<div class="position-relative" x-data="{ open: @entangle('open') }" @click.away="open = false">
    <div class="input-group">
        <span class="input-group-text bg-white border-end-0">
            <i class="bi bi-search text-muted"></i>
        </span>
        
        @if($selectedLabel)
            <div class="form-control d-flex align-items-center justify-content-between bg-white">
                <span class="fw-medium">{{ $selectedLabel }}</span>
                <button type="button" class="btn-close" aria-label="Close" wire:click="clearSelection"></button>
            </div>
        @else
            <input 
                type="text" 
                class="form-control border-start-0 ps-0" 
                placeholder="{{ $placeholder }}"
                wire:model.live.debounce.300ms="search"
                @focus="open = true"
                @keydown.escape="open = false"
            >
        @endif
    </div>

    @if($open && count($options) > 0)
        <div class="dropdown-menu show w-100 mt-1 shadow-sm fs-6" style="max-height: 300px; overflow-y: auto;">
            @foreach($options as $option)
                <a href="#" class="dropdown-item py-2 border-bottom" wire:click.prevent="select({{ $option['id'] }})">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-primary">{{ $option['reference'] }}</span>
                        <span class="badge bg-light text-dark">{{ $option['date'] }}</span>
                    </div>
                    <div class="small text-muted text-truncate">{{ $option['customer_name'] }}</div>
                </a>
            @endforeach
        </div>
    @elseif($open && strlen($search) >= 2)
        <div class="dropdown-menu show w-100 mt-1 shadow-sm p-3 text-center text-muted">
            <i class="bi bi-search mb-2 d-block fs-4"></i>
            Tidak ditemukan penjualan dengan referensi tersebut.
        </div>
    @endif
</div>

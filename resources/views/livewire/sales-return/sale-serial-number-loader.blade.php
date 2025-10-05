<div class="position-relative">
    <div class="form-group mb-0">
        <div class="input-group">
            <input wire:keydown.escape="resetQuery"
                   wire:model.debounce.500ms="query"
                   type="text"
                   class="form-control"
                   wire:focus="$set('isFocused', true)"
                   wire:blur="resetFocusAfterDelay"
                   placeholder="Cari nomor seri...">
        </div>
    </div>

    <div wire:loading class="card position-absolute mt-1 border-0" style="z-index: 5; left: 0; right: 0;">
        <div class="card-body shadow">
            <div class="d-flex justify-content-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Memuat...</span>
                </div>
            </div>
        </div>
    </div>

    @if($isFocused)
        <div class="card position-absolute mt-1 border-0" style="z-index: 6; left: 0; right: 0;">
            <div class="card-body shadow">
                @if(!empty($searchResults))
                    <ul class="list-group list-group-flush">
                        @foreach($searchResults as $serial)
                            <li class="list-group-item list-group-item-action">
                                <a wire:click.prevent="selectSerial({{ $serial['id'] }})" href="#">
                                    {{ $serial['serial_number'] }}
                                </a>
                            </li>
                        @endforeach
                        @if(count($searchResults) >= $howMany)
                            <li class="list-group-item list-group-item-action text-center">
                                <a wire:click.prevent="loadMore" class="btn btn-primary btn-sm" href="#">
                                    Muat lebih <i class="bi bi-arrow-down-circle"></i>
                                </a>
                            </li>
                        @endif
                    </ul>
                @elseif($query)
                    <div class="alert alert-warning mb-0">Nomor seri tidak ditemukan atau telah digunakan.</div>
                @endif
            </div>
        </div>
    @endif
</div>

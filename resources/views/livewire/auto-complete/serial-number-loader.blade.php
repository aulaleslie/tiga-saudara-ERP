<div class="position-relative">
    <!-- Search Input -->
    <div class="form-group mb-0">
        <div class="input-group">
            <input wire:keydown.escape="resetQuery"
                   wire:model.live.debounce.500ms="query"
                   type="text"
                   class="form-control"
                   wire:focus="$set('isFocused', true)"
                   wire:blur="resetQueryAfterDelay"
                   placeholder="Ketik kode serial number....">
        </div>
    </div>

    <!-- Loading Spinner -->
    <div wire:loading class="card position-absolute mt-1 border-0" style="z-index: 1; left: 0; right: 0;">
        <div class="card-body shadow">
            <div class="d-flex justify-content-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Memuat...</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Results -->
    @if($isFocused)
        <div class="card position-absolute mt-1 border-0" style="z-index: 1; left: 0; right: 0;">
            <div class="card-body shadow">
                @if(count($search_results) > 0)
                    <ul class="list-group list-group-flush">
                        @foreach($search_results as $result)
                            <li class="list-group-item list-group-item-action">
                                <a wire:click.prevent="selectSerialNumber({{ $result->id }})" href="#">
                                    {{ $result->serial_number }}
                                </a>
                            </li>
                        @endforeach
                        @if($query_count >= $how_many)
                            <li class="list-group-item list-group-item-action text-center">
                                <a wire:click.prevent="loadMore" class="btn btn-primary btn-sm" href="#">
                                    Memuat lebih <i class="bi bi-arrow-down-circle"></i>
                                </a>
                            </li>
                        @endif
                    </ul>
                @elseif($query)
                    <div class="alert alert-warning mb-0">
                        Serial <Number></Number> tidak ditemukan....
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>

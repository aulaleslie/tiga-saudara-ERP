<div class="position-relative">
    <!-- Search Box -->
    <div class="mb-0 shadow-sm bg-white border rounded">
        <div class="form-group mb-0">
            <div class="input-group">
                <div class="input-group-prepend">
                    <div class="input-group-text">
                        <i class="bi bi-search text-primary"></i>
                    </div>
                </div>
                <input
                    wire:keydown.escape="resetQuery"
                    wire:model.live.debounce.500ms="query"
                    type="text"
                    class="form-control"
                    placeholder="Ketik nama tag...">
            </div>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div wire:loading class="card position-absolute mt-1 border-0" style="z-index: 10; left: 0; right: 0;">
        <div class="card-body shadow">
            <div class="d-flex justify-content-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Click outside overlay -->
    @if(!empty($query))
        <div wire:click="resetQuery" class="position-fixed w-100 h-100" style="left: 0; top: 0; right: 0; bottom: 0; z-index: 9;"></div>

        <!-- Suggestions Dropdown -->
        @if(!empty($suggestions))
            <div class="card position-absolute mt-1" style="z-index: 11; left: 0; right: 0; border: 0;">
                <div class="card-body shadow">
                    <ul class="list-group list-group-flush">
                        @foreach($suggestions as $suggestion)
                            <li class="list-group-item list-group-item-action" wire:click.prevent="selectTag('{{ $suggestion }}')">
                                {{ $suggestion }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @else
            <div class="card position-absolute mt-1 border-0" style="z-index: 11; left: 0; right: 0;">
                <div class="card-body shadow">
                    <div class="alert alert-warning mb-0">
                        Tag tidak ditemukan.
                        <button wire:click="createTag" type="button" class="btn btn-sm btn-outline-primary float-end">Buat "{{ $query }}"</button>
                    </div>
                </div>
            </div>
        @endif
    @endif

    <!-- Selected Tags -->
    @if(!empty($selectedTags))
        <div class="mt-2">
            @foreach($selectedTags as $tag)
                <span class="badge bg-primary text-white fs-6 py-2 px-3 rounded-pill me-2">
                    {{ $tag }}
                    <a href="#" wire:click.prevent="removeTag('{{ $tag }}')" class="text-white ms-1">&times;</a>
                </span>
            @endforeach
        </div>
    @endif
</div>

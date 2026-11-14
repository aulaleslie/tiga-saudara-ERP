<div class="position-relative mb-4" data-pos-summary-cards
     @include('livewire.partials.summary-request-failure-state')
     @if (! $summaryInitialized) wire:init="loadSummary" @endif>
    <div wire:loading.flex wire:target="loadSummary,retrySummary,__dispatch,toggleCardFilter" wire:cloak
         data-pos-summary-loading
         class="position-absolute justify-content-center align-items-center"
         style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 99;display: none;">
        <div class="spinner-border text-primary" role="status">
            <span class="sr-only">Memuat data...</span>
        </div>
    </div>
        <div x-show.important="summaryRequestFailed" x-cloak class="alert alert-warning d-flex justify-content-between align-items-center mb-3" role="alert" data-pos-summary-request-error>
            <span>Permintaan ringkasan gagal. Angka di bawah belum diperbarui. Tabel tetap dapat digunakan.</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="$wire.retrySummary(retryInputs())">
                Coba Lagi
            </button>
        </div>
    @if ($summaryError)
        <div class="alert alert-warning d-flex justify-content-between align-items-center mb-3" role="alert" data-pos-summary-error>
            <span>{{ $summaryError }} Tabel tetap dapat digunakan.</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="retrySummary" wire:loading.attr="disabled" wire:target="retrySummary">
                Coba Lagi
            </button>
        </div>
    @endif
    <div class="row" x-bind:class="{ 'opacity-50': summaryRequestFailed }">
    <div class="col-md-4">
        <div @class(['card', 'border-0', 'border-start', 'border-primary', 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === 'unpaid'])
             style="cursor: pointer;"
             x-on:click="$wire.toggleCardFilter('unpaid', typeof beginPosCardFilter === 'function' ? beginPosCardFilter() : null)">
            <div class="card-body">
                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">Piutang Belum Tertagih</div>
                @if ($summary === null)
                    <div class="h5 mb-0 fw-bold text-muted" data-pos-summary-placeholder>{{ $summaryError ? 'Tidak tersedia' : 'Memuat…' }}</div>
                    <div class="text-primary mt-2 fw-bold">—</div>
                @else
                    <div class="h5 mb-0 fw-bold text-gray-800">{{ $summary['outstanding']['count'] }} Transaksi</div>
                    <div class="text-primary mt-2 fw-bold">{{ format_currency($summary['outstanding']['total']) }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div @class(['card', 'border-0', 'border-start', 'border-danger', 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === 'overdue'])
             style="cursor: pointer;"
             x-on:click="$wire.toggleCardFilter('overdue', typeof beginPosCardFilter === 'function' ? beginPosCardFilter() : null)">
            <div class="card-body">
                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">Piutang Jatuh Tempo</div>
                @if ($summary === null)
                    <div class="h5 mb-0 fw-bold text-muted" data-pos-summary-placeholder>{{ $summaryError ? 'Tidak tersedia' : 'Memuat…' }}</div>
                    <div class="text-danger mt-2 fw-bold">—</div>
                @else
                    <div class="h5 mb-0 fw-bold text-gray-800">{{ $summary['overdue']['count'] }} Transaksi</div>
                    <div class="text-danger mt-2 fw-bold">{{ format_currency($summary['overdue']['total']) }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div @class(['card', 'border-0', 'border-start', 'border-success', 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === 'paid'])
             style="cursor: pointer;"
             x-on:click="$wire.toggleCardFilter('paid', typeof beginPosCardFilter === 'function' ? beginPosCardFilter() : null)">
            <div class="card-body">
                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">Penerimaan (30 Hari)</div>
                @if ($summary === null)
                    <div class="h5 mb-0 fw-bold text-muted" data-pos-summary-placeholder>{{ $summaryError ? 'Tidak tersedia' : 'Memuat…' }}</div>
                    <div class="text-success mt-2 fw-bold">—</div>
                @else
                    <div class="h5 mb-0 fw-bold text-gray-800">{{ $summary['paid_within_30_days']['count'] }} Transaksi</div>
                    <div class="text-success mt-2 fw-bold">{{ format_currency($summary['paid_within_30_days']['total']) }}</div>
                @endif
            </div>
        </div>
    </div>
    </div>
</div>

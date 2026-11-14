@php
    $cards = [
        ['type' => 'unpaid', 'label' => 'Piutang Belum Tertagih', 'border' => 'border-primary', 'text' => 'text-primary', 'data' => $this->piutangBelumTertagih],
        ['type' => 'overdue', 'label' => 'Piutang Telat', 'border' => 'border-danger', 'text' => 'text-danger', 'data' => $this->piutangTelat],
        ['type' => 'paid', 'label' => 'Penerimaan (30 Hari)', 'border' => 'border-success', 'text' => 'text-success', 'data' => $this->penerimaan],
    ];
    $summaryUnknown = $globalMode && $summary === null;
@endphp
<div class="position-relative mb-4"
     data-sale-summary-cards
     @if ($globalMode) @include('livewire.partials.summary-request-failure-state') @endif
     @if ($globalMode && ! $summaryInitialized) wire:init="loadSummary" @endif>
    @if ($globalMode)
        <div wire:loading.flex wire:target="loadSummary,retrySummary,__dispatch" wire:cloak
             data-sale-summary-loading
             class="position-absolute justify-content-center align-items-center"
             style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 99;display: none;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Memuat ringkasan...</span>
            </div>
        </div>

        <div x-show.important="summaryRequestFailed" x-cloak class="alert alert-warning d-flex justify-content-between align-items-center mb-3" role="alert" data-sale-summary-request-error>
            <span>Permintaan ringkasan gagal. Angka di bawah belum diperbarui. Tabel tetap dapat digunakan.</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="$wire.retrySummary(retryInputs())">
                Coba Lagi
            </button>
        </div>
        @if ($summaryError)
            <div class="alert alert-warning d-flex justify-content-between align-items-center mb-3" role="alert" data-sale-summary-error>
                <span>{{ $summaryError }} Tabel tetap dapat digunakan.</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="retrySummary" wire:loading.attr="disabled" wire:target="retrySummary">
                    Coba Lagi
                </button>
            </div>
        @endif
    @endif

    <div class="row" @if ($globalMode) x-bind:class="{ 'opacity-50': summaryRequestFailed }" @endif>
        @foreach ($cards as $card)
            <div class="col-md-4">
                <div @class(['card', 'border-0', 'border-start', $card['border'], 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === $card['type']])
                     style="cursor: pointer;"
                     @if ($globalMode)
                         x-on:click="$wire.toggleCardFilter('{{ $card['type'] }}', typeof beginSaleCardFilter === 'function' ? beginSaleCardFilter() : null)"
                     @else
                         wire:click="toggleCardFilter('{{ $card['type'] }}')"
                     @endif>
                    <div class="card-body">
                        <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">{{ $card['label'] }}</div>
                        @if ($summaryUnknown)
                            <div class="h5 mb-0 fw-bold text-muted" data-sale-summary-placeholder>
                                {{ $summaryError ? 'Tidak tersedia' : 'Memuat…' }}
                            </div>
                            <div class="{{ $card['text'] }} mt-2 fw-bold">—</div>
                        @else
                            <div class="h5 mb-0 fw-bold text-gray-800">{{ $card['data']['count'] }} Transaksi</div>
                            <div class="{{ $card['text'] }} mt-2 fw-bold">{{ format_currency($card['data']['total']) }}</div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

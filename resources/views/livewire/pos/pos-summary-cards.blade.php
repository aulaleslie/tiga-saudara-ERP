<div class="row mb-4">
    <!-- Piutang Belum Tertagih -->
    <div class="col-md-4">
        <div @class(['card', 'border-0', 'border-start', 'border-primary', 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === 'unpaid'])
             style="cursor: pointer;"
             wire:click="toggleCardFilter('unpaid')">
            <div class="card-body">
                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">Piutang Belum Tertagih</div>
                <div class="h5 mb-0 fw-bold text-gray-800">{{ $summary['outstanding']['count'] }} Transaksi</div>
                <div class="text-primary mt-2 fw-bold">{{ format_currency($summary['outstanding']['total']) }}</div>
            </div>
        </div>
    </div>

    <!-- Piutang Jatuh Tempo -->
    <div class="col-md-4">
        <div @class(['card', 'border-0', 'border-start', 'border-danger', 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === 'overdue'])
             style="cursor: pointer;"
             wire:click="toggleCardFilter('overdue')">
            <div class="card-body">
                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">Piutang Jatuh Tempo</div>
                <div class="h5 mb-0 fw-bold text-gray-800">{{ $summary['overdue']['count'] }} Transaksi</div>
                <div class="text-danger mt-2 fw-bold">{{ format_currency($summary['overdue']['total']) }}</div>
            </div>
        </div>
    </div>

    <!-- Penerimaan (30 Hari Terakhir) -->
    <div class="col-md-4">
        <div @class(['card', 'border-0', 'border-start', 'border-success', 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === 'paid'])
             style="cursor: pointer;"
             wire:click="toggleCardFilter('paid')">
            <div class="card-body">
                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">Penerimaan (30 Hari)</div>
                <div class="h5 mb-0 fw-bold text-gray-800">{{ $summary['paid_within_30_days']['count'] }} Transaksi</div>
                <div class="text-success mt-2 fw-bold">{{ format_currency($summary['paid_within_30_days']['total']) }}</div>
            </div>
        </div>
    </div>
</div>

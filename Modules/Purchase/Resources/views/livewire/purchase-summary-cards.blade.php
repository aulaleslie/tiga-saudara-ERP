<div class="row mb-4">
    <!-- Belum Dibayar -->
    <div class="col-md-4">
        <div @class(['card', 'border-0', 'border-start', 'border-primary', 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === 'unpaid'])
             style="cursor: pointer;"
             wire:click="toggleCardFilter('unpaid')">
            <div class="card-body">
                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">Belum Dibayar</div>
                <div class="h5 mb-0 fw-bold text-gray-800">{{ $this->belumDibayar['count'] }} Transaksi</div>
                <div class="text-primary mt-2 fw-bold">{{ format_currency($this->belumDibayar['total']) }}</div>
            </div>
        </div>
    </div>

    <!-- Telat Bayar -->
    <div class="col-md-4">
        <div @class(['card', 'border-0', 'border-start', 'border-danger', 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === 'overdue'])
             style="cursor: pointer;"
             wire:click="toggleCardFilter('overdue')">
            <div class="card-body">
                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">Telat Bayar</div>
                <div class="h5 mb-0 fw-bold text-gray-800">{{ $this->telatBayar['count'] }} Transaksi</div>
                <div class="text-danger mt-2 fw-bold">{{ format_currency($this->telatBayar['total']) }}</div>
            </div>
        </div>
    </div>

    <!-- Pelunasan (30 Hari Terakhir) -->
    <div class="col-md-4">
        <div @class(['card', 'border-0', 'border-start', 'border-success', 'border-4', 'shadow-sm', 'h-100', 'bg-light' => $selectedCardFilter === 'paid'])
             style="cursor: pointer;"
             wire:click="toggleCardFilter('paid')">
            <div class="card-body">
                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.8rem;">Pelunasan (30 Hari)</div>
                <div class="h5 mb-0 fw-bold text-gray-800">{{ $this->pelunasan['count'] }} Transaksi</div>
                <div class="text-success mt-2 fw-bold">{{ format_currency($this->pelunasan['total']) }}</div>
            </div>
        </div>
    </div>
</div>

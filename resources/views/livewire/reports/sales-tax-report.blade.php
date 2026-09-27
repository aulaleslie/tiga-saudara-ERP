<div>
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3 align-items-end">
        <div>
            <label class="form-label small">Periode</label>
            <select wire:model.live="periodPreset" class="form-control">
                <option value="Hari ini">Hari ini</option>
                <option value="Kemarin">Kemarin</option>
                <option value="Minggu ini">Minggu ini</option>
                <option value="Minggu lalu">Minggu lalu</option>
                <option value="Bulan ini">Bulan ini</option>
                <option value="Bulan lalu">Bulan lalu</option>
                <option value="Tahun ini">Tahun ini</option>
                <option value="Tahun lalu">Tahun lalu</option>
                <option value="Kustom">Kustom</option>
            </select>
        </div>
        <div>
            <label class="form-label small">Tanggal awal</label>
            <input type="date" wire:model.live="startDate" class="form-control" {{ $periodPreset !== 'Kustom' ? 'readonly' : '' }}>
        </div>
        <div>
            <label class="form-label small">Tanggal akhir</label>
            <input type="date" wire:model.live="endDate" class="form-control" {{ $periodPreset !== 'Kustom' ? 'readonly' : '' }}>
        </div>
        <div class="ms-auto d-flex gap-2">
            <button wire:click="applyFilters" wire:loading.attr="disabled" class="btn btn-primary">
                <span wire:loading wire:target="applyFilters" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i wire:loading.remove wire:target="applyFilters" class="bi bi-search"></i> Filter
            </button>
            <button wire:click="resetFilters" wire:loading.attr="disabled" class="btn btn-outline-secondary">
                Reset
            </button>
            <div class="dropdown">
                <button class="btn btn-outline-success dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false" wire:loading.attr="disabled" wire:target="exportExcel,exportCsv">
                    <i class="bi bi-download"></i> Ekspor
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <button class="dropdown-item" wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportExcel">
                            <span wire:loading wire:target="exportExcel" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            Excel
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                            <span wire:loading wire:target="exportCsv" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            CSV
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <div class="d-flex align-items-baseline mb-3">
            <h4 class="mb-0 me-2 text-dark font-weight-bold">Laporan Pajak Penjualan</h4>
            <span class="text-muted small">(dalam IDR)</span>
        </div>

    @if($filterTriggered)
            <div class="table-responsive bg-white border rounded">
                <table class="table table-hover table-striped mb-0 text-sm">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="border-bottom font-weight-semibold" style="width: 25%">Tanggal</th>
                            <th class="border-bottom font-weight-semibold text-end" style="width: 25%">DPP</th>
                            <th class="border-bottom font-weight-semibold text-end" style="width: 25%">Rate Pajak</th>
                            <th class="border-bottom font-weight-semibold text-end" style="width: 25%">Total Pajak</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($groupedData as $group)
                            <tr class="bg-light">
                                <td colspan="4" class="font-weight-bold text-dark border-bottom">
                                    {{ $group['tax_group'] }}
                                </td>
                            </tr>
                            @foreach($group['transactions'] as $row)
                                <tr>
                                    <td class="ps-4 text-nowrap">{{ $row['type'] }}</td>
                                    <td class="text-end text-nowrap">{{ format_currency($row['dpp']) }}</td>
                                    <td class="text-end text-nowrap">{{ number_format($row['tax_rate'], 1, '.', '') }}</td>
                                    <td class="text-end text-nowrap">{{ format_currency($row['total_tax']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="font-weight-bold report-subtotal">
                                <td colspan="4" class="text-end border-top">{{ format_currency($group['subtotal_tax']) }}</td>
                            </tr>
                            <tr class="report-subtotal"><td colspan="4" class="border-0 bg-white" style="height: 15px;"></td></tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 text-light"></i>
                                    Tidak ada data yang sesuai dengan filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i> Silakan sesuaikan filter dan klik <strong>Terapkan Filter</strong> untuk melihat laporan.
        </div>
    @endif
    </div>
</div>

<div>
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form wire:submit="generateReport">
                        <div class="form-row">
                            <div class="col-lg-6">
                                @include('livewire.reports.business-source-selector', [
                                    'selectId' => 'profitLossSettingIds',
                                    'availableSettings' => $availableSettings
                                ])
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label>Tanggal awal</label>
                                    <input wire:model="start_date" type="date" class="form-control" name="start_date">
                                    @error('start_date')
                                    <span class="text-danger mt-1">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label>Tanggal akhir</label>
                                    <input wire:model="end_date" type="date" class="form-control" name="end_date">
                                    @error('end_date')
                                    <span class="text-danger mt-1">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <button type="submit" class="btn btn-primary">
                                <span wire:target="generateReport" wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <i wire:target="generateReport" wire:loading.remove class="bi bi-shuffle"></i>
                                Filter Report
                            </button>
                            <button type="button" wire:click="exportExcel" wire:loading.attr="disabled" class="btn btn-success ml-2">
                                <span wire:target="exportExcel" wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <i wire:target="exportExcel" wire:loading.remove class="bi bi-file-earmark-excel"></i>
                                Export Excel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if($report)
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-center mb-4">
                        <h3 class="font-weight-bold mb-1">Laporan Laba Rugi</h3>
                        <p class="text-muted mb-1">{{ $scopeLabel }}</p>
                        <p class="text-muted mb-0">(dalam {{ $report->currencyCode }})</p>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead class="thead-light">
                                <tr>
                                    <th>Keterangan</th>
                                    <th class="text-right">{{ $report->periodLabel }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($report->getRows() as $row)
                                    @if($row['type'] === 'header')
                                        <tr>
                                            <td colspan="2" class="font-weight-bold">{{ $row['label'] }}</td>
                                        </tr>
                                    @elseif($row['type'] === 'row')
                                        <tr>
                                            <td class="pl-4">{{ $row['label'] }}</td>
                                            <td class="text-right">{{ $row['value'] < 0 ? '(' . format_currency(abs($row['value'])) . ')' : format_currency($row['value']) }}</td>
                                        </tr>
                                    @elseif($row['type'] === 'subtotal')
                                        <tr>
                                            <td class="font-weight-bold">{{ $row['label'] }}</td>
                                            <td class="text-right font-weight-bold">{{ $row['value'] < 0 ? '(' . format_currency(abs($row['value'])) . ')' : format_currency($row['value']) }}</td>
                                        </tr>
                                    @elseif($row['type'] === 'empty')
                                        <tr><td colspan="2"></td></tr>
                                    @elseif($row['type'] === 'total')
                                        <tr>
                                            <td class="font-weight-bold h5 mb-0">{{ $row['label'] }}</td>
                                            <td class="text-right font-weight-bold h5 mb-0">{{ $row['value'] < 0 ? '(' . format_currency(abs($row['value'])) . ')' : format_currency($row['value']) }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

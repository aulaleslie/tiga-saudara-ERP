<div>
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form wire:submit="generateReport">
                        <div class="form-row">
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label>Periode</label>
                                    <select wire:model.live="period_preset" class="form-control">
                                        <option value="today">Hari Ini</option>
                                        <option value="this_week">Minggu Ini</option>
                                        <option value="this_month">Bulan Ini</option>
                                        <option value="this_year">Tahun Ini</option>
                                        <option value="custom">Kustom</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label>Tanggal Mulai</label>
                                    <input wire:model.live="start_date" type="date" class="form-control" name="start_date">
                                    @error('start_date')
                                    <span class="text-danger mt-1">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label>Tanggal Akhir</label>
                                    <input wire:model.live="end_date" type="date" class="form-control" name="end_date">
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
                            <button type="button" wire:click="resetFilters" class="btn btn-secondary ml-2">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset
                            </button>
                            
                            <div class="btn-group ml-2">
                                <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
                                    <span wire:target="exportExcel, exportCsv" wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                    <i wire:target="exportExcel, exportCsv" wire:loading.remove class="bi bi-download"></i>
                                    Export
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="#" wire:click.prevent="exportExcel">Export Excel (XLSX)</a>
                                    <a class="dropdown-item" href="#" wire:click.prevent="exportCsv">Export CSV</a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if($report)
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-center mb-4">
                        <h3 class="font-weight-bold mb-1">Arus Kas (Operasional)</h3>
                        <p class="text-muted mb-0">Periode: {{ $report->periodLabel }}</p>
                        <p class="text-muted mb-0">(dalam {{ $report->currencyCode }})</p>
                    </div>

                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle mr-1"></i> {{ $report->sourceNote }}
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead class="thead-light">
                                <tr>
                                    <th>Keterangan</th>
                                    <th class="text-right">Nilai</th>
                                </tr>
                            </thead>
                            <tbody>
                                {{-- Operating Activities --}}
                                <tr>
                                    <td colspan="2" class="font-weight-bold">{{ $report->operatingActivities->name }}</td>
                                </tr>
                                @foreach($report->operatingActivities->rows as $row)
                                <tr>
                                    <td class="pl-4">{{ $row->name }}</td>
                                    <td class="text-right">{{ $row->amount < 0 ? '(' . format_currency(abs($row->amount)) . ')' : format_currency($row->amount) }}</td>
                                </tr>
                                @endforeach
                                <tr>
                                    <td class="font-weight-bold pl-4">Net {{ $report->operatingActivities->name }}</td>
                                    <td class="text-right font-weight-bold">{{ $report->operatingActivities->total < 0 ? '(' . format_currency(abs($report->operatingActivities->total)) . ')' : format_currency($report->operatingActivities->total) }}</td>
                                </tr>
                                
                                <tr><td colspan="2"></td></tr>

                                {{-- Investing Activities --}}
                                <tr>
                                    <td colspan="2" class="font-weight-bold">{{ $report->investingActivities->name }}</td>
                                </tr>
                                @foreach($report->investingActivities->rows as $row)
                                <tr>
                                    <td class="pl-4">{{ $row->name }}</td>
                                    <td class="text-right">{{ $row->amount < 0 ? '(' . format_currency(abs($row->amount)) . ')' : format_currency($row->amount) }}</td>
                                </tr>
                                @endforeach
                                <tr>
                                    <td class="font-weight-bold pl-4">Net {{ $report->investingActivities->name }}</td>
                                    <td class="text-right font-weight-bold">{{ $report->investingActivities->total < 0 ? '(' . format_currency(abs($report->investingActivities->total)) . ')' : format_currency($report->investingActivities->total) }}</td>
                                </tr>

                                <tr><td colspan="2"></td></tr>

                                {{-- Financing Activities --}}
                                <tr>
                                    <td colspan="2" class="font-weight-bold">{{ $report->financingActivities->name }}</td>
                                </tr>
                                @foreach($report->financingActivities->rows as $row)
                                <tr>
                                    <td class="pl-4">{{ $row->name }}</td>
                                    <td class="text-right">{{ $row->amount < 0 ? '(' . format_currency(abs($row->amount)) . ')' : format_currency($row->amount) }}</td>
                                </tr>
                                @endforeach
                                <tr>
                                    <td class="font-weight-bold pl-4">Net {{ $report->financingActivities->name }}</td>
                                    <td class="text-right font-weight-bold">{{ $report->financingActivities->total < 0 ? '(' . format_currency(abs($report->financingActivities->total)) . ')' : format_currency($report->financingActivities->total) }}</td>
                                </tr>

                                <tr><td colspan="2"></td></tr>
                                
                                {{-- Summary Rows --}}
                                <tr>
                                    <td class="font-weight-bold h6 mb-0">{{ $report->netCashIncrease->name }}</td>
                                    <td class="text-right font-weight-bold h6 mb-0">{{ $report->netCashIncrease->amount < 0 ? '(' . format_currency(abs($report->netCashIncrease->amount)) . ')' : format_currency($report->netCashIncrease->amount) }}</td>
                                </tr>
                                
                                <tr>
                                    <td class="font-weight-bold">{{ $report->bankRevaluation->name }}</td>
                                    <td class="text-right font-weight-bold">{{ $report->bankRevaluation->amount < 0 ? '(' . format_currency(abs($report->bankRevaluation->amount)) . ')' : format_currency($report->bankRevaluation->amount) }}</td>
                                </tr>
                                
                                <tr>
                                    <td class="font-weight-bold">{{ $report->openingCash->name }}</td>
                                    <td class="text-right font-weight-bold">{{ $report->openingCash->amount < 0 ? '(' . format_currency(abs($report->openingCash->amount)) . ')' : format_currency($report->openingCash->amount) }}</td>
                                </tr>
                                
                                <tr>
                                    <td class="font-weight-bold h5 mb-0 text-primary">{{ $report->endingCash->name }}</td>
                                    <td class="text-right font-weight-bold h5 mb-0 text-primary">{{ $report->endingCash->amount < 0 ? '(' . format_currency(abs($report->endingCash->amount)) . ')' : format_currency($report->endingCash->amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

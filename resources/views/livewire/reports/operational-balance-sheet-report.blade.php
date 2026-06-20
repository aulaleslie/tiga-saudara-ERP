<div>
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form wire:submit="generateReport">
                        <div class="form-row">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label>Per Tanggal (As of Date)</label>
                                    <input wire:model.live="as_of_date" type="date" class="form-control" name="as_of_date">
                                    @error('as_of_date')
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
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-center mb-4">
                        <h3 class="font-weight-bold mb-1">Neraca (Operasional)</h3>
                        <p class="text-muted mb-0">Per {{ \Carbon\Carbon::parse($report->asOfDate)->format('d M Y') }}</p>
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
                                {{-- Aset --}}
                                <tr>
                                    <td colspan="2" class="font-weight-bold">{{ $report->assets->name }}</td>
                                </tr>
                                @foreach($report->assets->rows as $row)
                                <tr>
                                    <td class="pl-4">{{ $row->name }}</td>
                                    <td class="text-right">{{ $row->amount < 0 ? '(' . format_currency(abs($row->amount)) . ')' : format_currency($row->amount) }}</td>
                                </tr>
                                @endforeach
                                <tr>
                                    <td class="font-weight-bold">Total {{ $report->assets->name }}</td>
                                    <td class="text-right font-weight-bold">{{ $report->assets->total < 0 ? '(' . format_currency(abs($report->assets->total)) . ')' : format_currency($report->assets->total) }}</td>
                                </tr>
                                
                                <tr><td colspan="2"></td></tr>

                                {{-- Liabilitas --}}
                                <tr>
                                    <td colspan="2" class="font-weight-bold">{{ $report->liabilities->name }}</td>
                                </tr>
                                @foreach($report->liabilities->rows as $row)
                                <tr>
                                    <td class="pl-4">{{ $row->name }}</td>
                                    <td class="text-right">{{ $row->amount < 0 ? '(' . format_currency(abs($row->amount)) . ')' : format_currency($row->amount) }}</td>
                                </tr>
                                @endforeach
                                <tr>
                                    <td class="font-weight-bold">Total {{ $report->liabilities->name }}</td>
                                    <td class="text-right font-weight-bold">{{ $report->liabilities->total < 0 ? '(' . format_currency(abs($report->liabilities->total)) . ')' : format_currency($report->liabilities->total) }}</td>
                                </tr>

                                <tr><td colspan="2"></td></tr>

                                {{-- Ekuitas --}}
                                <tr>
                                    <td colspan="2" class="font-weight-bold">{{ $report->equity->name }}</td>
                                </tr>
                                @foreach($report->equity->rows as $row)
                                <tr>
                                    <td class="pl-4">{{ $row->name }}</td>
                                    <td class="text-right">{{ $row->amount < 0 ? '(' . format_currency(abs($row->amount)) . ')' : format_currency($row->amount) }}</td>
                                </tr>
                                @endforeach
                                <tr>
                                    <td class="font-weight-bold">Total {{ $report->equity->name }}</td>
                                    <td class="text-right font-weight-bold">{{ $report->equity->total < 0 ? '(' . format_currency(abs($report->equity->total)) . ')' : format_currency($report->equity->total) }}</td>
                                </tr>

                                <tr><td colspan="2"></td></tr>
                                
                                {{-- Total Liabilities + Equity --}}
                                <tr>
                                    <td class="font-weight-bold h5 mb-0">Total {{ $report->liabilities->name }} dan {{ $report->equity->name }}</td>
                                    <td class="text-right font-weight-bold h5 mb-0">{{ ($report->liabilities->total + $report->equity->total) < 0 ? '(' . format_currency(abs($report->liabilities->total + $report->equity->total)) . ')' : format_currency($report->liabilities->total + $report->equity->total) }}</td>
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

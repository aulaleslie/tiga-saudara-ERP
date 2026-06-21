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
                                    <label>Dari Tanggal (Start Date)</label>
                                    <input wire:model.live="start_date" type="date" class="form-control" name="start_date">
                                    @error('start_date')
                                    <span class="text-danger mt-1">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label>Sampai Tanggal (End Date)</label>
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
                                <i class="bi bi-arrow-counterclockwise"></i>
                                Reset
                            </button>
                            <button type="button" wire:click="exportExcel" wire:loading.attr="disabled" class="btn btn-success ml-2">
                                <span wire:target="exportExcel" wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <i wire:target="exportExcel" wire:loading.remove class="bi bi-file-earmark-excel"></i>
                                Export Excel
                            </button>
                            <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" class="btn btn-info ml-2">
                                <span wire:target="exportCsv" wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <i wire:target="exportCsv" wire:loading.remove class="bi bi-filetype-csv"></i>
                                Export CSV
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
                        <h3 class="font-weight-bold mb-1">Neraca Saldo</h3>
                        <p class="text-muted mb-0">Periode {{ \Carbon\Carbon::parse($report->startDate)->format('d M Y') }} - {{ \Carbon\Carbon::parse($report->endDate)->format('d M Y') }}</p>
                        <p class="text-muted mb-0">(dalam {{ $report->currencyCode }})</p>
                    </div>

                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle mr-1"></i> {{ $report->sourceNote }}
                    </div>

                    @if(empty($report->categories))
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                            <p class="mt-2">Tidak ada transaksi yang sesuai dengan filter yang dipilih.</p>
                        </div>
                    @else
                        <div class="table-responsive mb-5">
                            <table class="table table-bordered table-sm">
                                <thead class="thead-light">
                                    <tr>
                                        <th rowspan="2" class="align-middle">Kode Akun</th>
                                        <th rowspan="2" class="align-middle">Kategori / Akun</th>
                                        <th colspan="2" class="text-center">Saldo Awal</th>
                                        <th colspan="2" class="text-center">Pergerakan</th>
                                        <th colspan="2" class="text-center">Saldo Akhir</th>
                                    </tr>
                                    <tr>
                                        <th class="text-right">Debit</th>
                                        <th class="text-right">Kredit</th>
                                        <th class="text-right">Debit</th>
                                        <th class="text-right">Kredit</th>
                                        <th class="text-right">Debit</th>
                                        <th class="text-right">Kredit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($report->categories as $category)
                                        <tr class="table-secondary">
                                            <td colspan="8" class="font-weight-bold">{{ $category->categoryName }}</td>
                                        </tr>
                                        @foreach($category->rows as $row)
                                        <tr>
                                            <td>{{ $row->code }}</td>
                                            <td class="pl-4">{{ $row->label }}</td>
                                            <td class="text-right">{{ $row->openingDebit > 0 ? format_currency($row->openingDebit) : '-' }}</td>
                                            <td class="text-right">{{ $row->openingCredit > 0 ? format_currency($row->openingCredit) : '-' }}</td>
                                            <td class="text-right">{{ $row->periodDebit > 0 ? format_currency($row->periodDebit) : '-' }}</td>
                                            <td class="text-right">{{ $row->periodCredit > 0 ? format_currency($row->periodCredit) : '-' }}</td>
                                            <td class="text-right">{{ $row->endingDebit > 0 ? format_currency($row->endingDebit) : '-' }}</td>
                                            <td class="text-right">{{ $row->endingCredit > 0 ? format_currency($row->endingCredit) : '-' }}</td>
                                        </tr>
                                        @endforeach
                                        <tr class="font-weight-bold bg-light">
                                            <td colspan="2" class="text-right">Total {{ $category->categoryName }}</td>
                                            <td class="text-right">{{ $category->totalOpeningDebit > 0 ? format_currency($category->totalOpeningDebit) : '-' }}</td>
                                            <td class="text-right">{{ $category->totalOpeningCredit > 0 ? format_currency($category->totalOpeningCredit) : '-' }}</td>
                                            <td class="text-right">{{ $category->totalPeriodDebit > 0 ? format_currency($category->totalPeriodDebit) : '-' }}</td>
                                            <td class="text-right">{{ $category->totalPeriodCredit > 0 ? format_currency($category->totalPeriodCredit) : '-' }}</td>
                                            <td class="text-right">{{ $category->totalEndingDebit > 0 ? format_currency($category->totalEndingDebit) : '-' }}</td>
                                            <td class="text-right">{{ $category->totalEndingCredit > 0 ? format_currency($category->totalEndingCredit) : '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-dark font-weight-bold">
                                        <td colspan="2" class="text-right">TOTAL KESELURUHAN</td>
                                        <td class="text-right">{{ $report->grandTotalOpeningDebit > 0 ? format_currency($report->grandTotalOpeningDebit) : '-' }}</td>
                                        <td class="text-right">{{ $report->grandTotalOpeningCredit > 0 ? format_currency($report->grandTotalOpeningCredit) : '-' }}</td>
                                        <td class="text-right">{{ $report->grandTotalPeriodDebit > 0 ? format_currency($report->grandTotalPeriodDebit) : '-' }}</td>
                                        <td class="text-right">{{ $report->grandTotalPeriodCredit > 0 ? format_currency($report->grandTotalPeriodCredit) : '-' }}</td>
                                        <td class="text-right">{{ $report->grandTotalEndingDebit > 0 ? format_currency($report->grandTotalEndingDebit) : '-' }}</td>
                                        <td class="text-right">{{ $report->grandTotalEndingCredit > 0 ? format_currency($report->grandTotalEndingCredit) : '-' }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

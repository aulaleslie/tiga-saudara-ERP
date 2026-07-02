<div>
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form wire:submit="generateReport">
                        <div class="form-row">
                            <div class="col-lg-3">
                                @include('livewire.reports.business-source-selector', [
                                    'selectId' => 'generalLedgerSettingIds',
                                    'availableSettings' => $availableSettings
                                ])
                            </div>
                            <div class="col-lg-3">
                                <div class="form-group">
                                    <label>Dari Tanggal (Start Date)</label>
                                    <input wire:model.live="start_date" type="date" class="form-control" name="start_date">
                                    @error('start_date')
                                    <span class="text-danger mt-1">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-lg-3">
                                <div class="form-group">
                                    <label>Sampai Tanggal (End Date)</label>
                                    <input wire:model.live="end_date" type="date" class="form-control" name="end_date">
                                    @error('end_date')
                                    <span class="text-danger mt-1">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-lg-3">
                                <div class="form-group">
                                    <label>Filter Kategori</label>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-left" type="button" data-toggle="dropdown" aria-expanded="false">
                                            {{ count($selected_buckets) }} Kategori Dipilih
                                        </button>
                                        <div class="dropdown-menu w-100 p-2" style="max-height: 300px; overflow-y: auto;">
                                            @foreach($bucketLabels as $key => $label)
                                            <div class="custom-control custom-checkbox mb-2">
                                                <input type="checkbox" class="custom-control-input" id="bucket_{{ $key }}" value="{{ $key }}" wire:model.live="selected_buckets">
                                                <label class="custom-control-label" for="bucket_{{ $key }}">{{ $label }}</label>
                                            </div>
                                            @endforeach
                                        </div>
                                    </div>
                                    @error('selected_buckets')
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
                        <h3 class="font-weight-bold mb-1">Buku Besar</h3>
                        <p class="text-muted mb-1">{{ $scopeLabel }}</p>
                        <p class="text-muted mb-0">Periode {{ \Carbon\Carbon::parse($report->startDate)->format('d M Y') }} - {{ \Carbon\Carbon::parse($report->endDate)->format('d M Y') }}</p>
                        <p class="text-muted mb-0">(dalam {{ $report->currencyCode }})</p>
                    </div>

                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle mr-1"></i> {{ $report->sourceNote }}
                    </div>

                    @if(empty($report->buckets))
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                            <p class="mt-2">Tidak ada transaksi yang sesuai dengan filter yang dipilih.</p>
                        </div>
                    @else
                        @foreach($report->buckets as $bucket)
                            <div class="table-responsive mb-5">
                                <table class="table table-bordered table-striped table-sm">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Nama Akun / Tanggal</th>
                                            <th>Transaksi</th>
                                            <th>No.</th>
                                            <th>Deskripsi</th>
                                            <th class="text-right">Debit</th>
                                            <th class="text-right">Kredit</th>
                                            <th class="text-right">Saldo</th>
                                            <th>Tag</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {{-- Bucket Header & Beginning Balance --}}
                                        <tr class="table-secondary">
                                            <td colspan="6" class="font-weight-bold">{{ $bucket->label }}</td>
                                            <td class="text-right font-weight-bold">{{ $bucket->beginningBalance < 0 ? '(' . format_currency(abs($bucket->beginningBalance)) . ')' : format_currency($bucket->beginningBalance) }}</td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td class="font-italic text-muted" colspan="6">Saldo Awal</td>
                                            <td class="text-right font-italic text-muted">{{ $bucket->beginningBalance < 0 ? '(' . format_currency(abs($bucket->beginningBalance)) . ')' : format_currency($bucket->beginningBalance) }}</td>
                                            <td></td>
                                        </tr>

                                        {{-- Movement Rows --}}
                                        @foreach($bucket->rows as $row)
                                        <tr>
                                            <td>{{ \Carbon\Carbon::parse($row->date)->format('d/m/Y') }}</td>
                                            <td>{{ $row->sourceType }}</td>
                                            <td>{{ $row->reference }}</td>
                                            <td>{{ $row->description }}</td>
                                            <td class="text-right">{{ $row->debit > 0 ? format_currency($row->debit) : '-' }}</td>
                                            <td class="text-right">{{ $row->credit > 0 ? format_currency($row->credit) : '-' }}</td>
                                            <td class="text-right">{{ $row->balance < 0 ? '(' . format_currency(abs($row->balance)) . ')' : format_currency($row->balance) }}</td>
                                            <td>{{ $row->tag }}</td>
                                        </tr>
                                        @endforeach

                                        {{-- Ending Balance & Totals --}}
                                        <tr class="table-secondary">
                                            <td colspan="4" class="font-weight-bold text-right">Pergerakan Periode</td>
                                            <td class="text-right font-weight-bold">{{ $bucket->periodDebit > 0 ? format_currency($bucket->periodDebit) : '-' }}</td>
                                            <td class="text-right font-weight-bold">{{ $bucket->periodCredit > 0 ? format_currency($bucket->periodCredit) : '-' }}</td>
                                            <td class="text-right font-weight-bold">{{ $bucket->endingBalance < 0 ? '(' . format_currency(abs($bucket->endingBalance)) . ')' : format_currency($bucket->endingBalance) }}</td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

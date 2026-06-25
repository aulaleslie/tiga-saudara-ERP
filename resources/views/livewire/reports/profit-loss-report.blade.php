<div>
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form wire:submit="generateReport">
                        <div class="form-row">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label>Perusahaan</label>
                                    <div wire:ignore class="profit-loss-setting-select">
                                        <select id="settingIds" multiple class="form-control" style="width: 100%;">
                                            @forelse($availableSettings as $setting)
                                                <option value="{{ $setting['id'] }}">{{ $setting['company_name'] }}</option>
                                            @empty
                                                <option disabled>Tidak ada perusahaan</option>
                                            @endforelse
                                        </select>
                                    </div>
                                </div>
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
                                {{-- Pendapatan --}}
                                <tr>
                                    <td colspan="2" class="font-weight-bold">Pendapatan</td>
                                </tr>
                                <tr>
                                    <td class="pl-4">Penjualan</td>
                                    <td class="text-right">{{ $report->salesTotal < 0 ? '(' . format_currency(abs($report->salesTotal)) . ')' : format_currency($report->salesTotal) }}</td>
                                </tr>
                                <tr>
                                    <td class="pl-4">Retur Penjualan</td>
                                    <td class="text-right">({{ format_currency($report->saleReturnsTotal) }})</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Total Pendapatan Bersih</td>
                                    <td class="text-right font-weight-bold">{{ $report->netRevenue < 0 ? '(' . format_currency(abs($report->netRevenue)) . ')' : format_currency($report->netRevenue) }}</td>
                                </tr>
                                
                                <tr><td colspan="2"></td></tr>

                                {{-- Biaya --}}
                                <tr>
                                    <td colspan="2" class="font-weight-bold">Beban Pokok Pendapatan</td>
                                </tr>
                                <tr>
                                    <td class="pl-4">Harga Pokok Penjualan</td>
                                    <td class="text-right">{{ $report->salesCostTotal < 0 ? '(' . format_currency(abs($report->salesCostTotal)) . ')' : format_currency($report->salesCostTotal) }}</td>
                                </tr>
                                <tr>
                                    <td class="pl-4">Koreksi HPP (Retur)</td>
                                    <td class="text-right">({{ format_currency($report->saleReturnCostTotal) }})</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Total HPP Bersih</td>
                                    <td class="text-right font-weight-bold">{{ $report->netSalesCost < 0 ? '(' . format_currency(abs($report->netSalesCost)) . ')' : format_currency($report->netSalesCost) }}</td>
                                </tr>
                                <tr><td colspan="2"></td></tr>

                                <tr>
                                    <td colspan="2" class="font-weight-bold">Biaya Operasional</td>
                                </tr>
                                <tr>
                                    <td class="pl-4">Beban & Pengeluaran</td>
                                    <td class="text-right">{{ $report->expensesTotal < 0 ? '(' . format_currency(abs($report->expensesTotal)) . ')' : format_currency($report->expensesTotal) }}</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Total Biaya & HPP</td>
                                    <td class="text-right font-weight-bold">{{ $report->totalCost < 0 ? '(' . format_currency(abs($report->totalCost)) . ')' : format_currency($report->totalCost) }}</td>
                                </tr>

                                <tr><td colspan="2"></td></tr>

                                {{-- Laba (Rugi) --}}
                                <tr>
                                    <td class="font-weight-bold h5 mb-0">Laba (Rugi)</td>
                                    <td class="text-right font-weight-bold h5 mb-0">{{ $report->profitLoss < 0 ? '(' . format_currency(abs($report->profitLoss)) . ')' : format_currency($report->profitLoss) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <style>
        .profit-loss-setting-select .select2-selection--multiple {
            min-height: 38px;
            height: auto !important;
            padding: 2px 6px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
        }

        .profit-loss-setting-select .select2-selection__rendered {
            display: flex !important;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px;
            padding: 0 !important;
            margin: 0 !important;
            width: auto !important;
        }

        .profit-loss-setting-select .select2-search--inline {
            flex: 1 1 160px;
            min-width: 160px;
            line-height: 28px;
        }

        .profit-loss-setting-select .select2-search__field {
            width: 100% !important;
            height: 28px !important;
            min-height: 28px !important;
            max-height: 28px !important;
            line-height: 28px !important;
            resize: none !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
            text-align: left;
        }
    </style>

    <script>
        document.addEventListener('livewire:initialized', () => {
            const $select = $('#settingIds');

            if ($select.hasClass('select2-hidden-accessible')) {
                $select.select2('destroy');
            }

            $select.select2({
                placeholder: 'Pilih perusahaan...',
                allowClear: true,
                theme: 'coreui',
                width: '100%'
            }).on('change', function() {
                const values = $(this).val() || [];
                @this.set('selectedSettingIds', values);
            });
        });
    </script>
</div>

<div class="table-responsive mb-3">
    <table class="table table-sm table-striped">
        <thead>
        <tr>
            <th class="text-uppercase small text-muted">Nominal</th>
            <th class="text-uppercase small text-muted">Jumlah Lembar/Koin</th>
            <th class="text-uppercase small text-muted text-right">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach($denominations as $value => $count)
            @php
                $pieces = is_numeric($count) ? (int) $count : 0;
                $lineTotal = $pieces * (float) $value;
            @endphp
            <tr>
                <td class="align-middle">{{ $currencySymbol }} {{ number_format((float) $value, 0, ',', '.') }}</td>
                <td>
                    <input type="number" min="0" step="1" class="form-control form-control-sm"
                           wire:model.lazy="denominations.{{ $value }}">
                </td>
                <td class="text-right align-middle">{{ $currencySymbol }} {{ number_format($lineTotal, 0, ',', '.') }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="align-middle">Kas Lainnya / Pembulatan</td>
            <td colspan="2">
                <div class="input-group input-group-sm">
                    <div class="input-group-prepend">
                        <span class="input-group-text">{{ $currencySymbol }}</span>
                    </div>
                    <input type="number" step="0.01" min="0" class="form-control" wire:model.lazy="manualAdjustment">
                </div>
                <small class="form-text text-muted">Gunakan kolom ini untuk koin atau penyesuaian kecil lainnya.</small>
            </td>
        </tr>
        </tbody>
        <tfoot>
        <tr>
            <th colspan="2" class="text-right">Total Fisik</th>
            <th class="text-right">{{ $currencySymbol }} {{ number_format($countedTotal, 2, ',', '.') }}</th>
        </tr>
        @if(isset($expectedOnHand))
            <tr>
                <th colspan="2" class="text-right">Perkiraan Seharusnya</th>
                <th class="text-right">{{ $currencySymbol }} {{ number_format($expectedOnHand ?? 0, 2, ',', '.') }}</th>
            </tr>
        @elseif(isset($expectedTotal))
            <tr>
                <th colspan="2" class="text-right">Total yang Diharapkan</th>
                <th class="text-right">{{ $currencySymbol }} {{ number_format($expectedTotal ?? 0, 2, ',', '.') }}</th>
            </tr>
        @endif
        @if(isset($variance))
            <tr>
                <th colspan="2" class="text-right">Selisih</th>
                <th class="text-right {{ ($variance ?? 0) === 0.0 ? 'text-success' : 'text-danger' }}">
                    {{ $currencySymbol }} {{ number_format($variance ?? 0, 2, ',', '.') }}
                </th>
            </tr>
        @endif
        </tfoot>
    </table>
</div>

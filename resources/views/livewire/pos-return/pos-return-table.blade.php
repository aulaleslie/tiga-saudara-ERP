<div>
    <div class="row mb-3">
        <div class="col-md-3">
            <input wire:model.live="search" type="text" class="form-control" placeholder="Cari Ref, Transaksi, Struk, Pelanggan...">
        </div>
        <div class="col-md-2">
            <select wire:model.live="statusFilter" class="form-control">
                <option value="">Semua Status</option>
                @foreach(\Modules\Pos\Entities\PosReturn::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th wire:click="sortBy('reference')" style="cursor: pointer;">
                        Reference {!! $sortField === 'reference' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' !!}
                    </th>
                    <th wire:click="sortBy('transaction_code')" style="cursor: pointer;">
                        Transaksi {!! $sortField === 'transaction_code' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' !!}
                    </th>
                    <th>Customer</th>
                    <th>Opsi</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($returns as $return)
                    <tr>
                        <td>{{ $return->reference }}</td>
                        <td>
                            <div>{{ $return->transaction_code }}</div>
                            <small class="text-muted">{{ $return->receipt_number }}</small>
                        </td>
                        <td>{{ $return->customer_name }}</td>
                        <td>
                            <span class="badge badge-info">
                                {{ \Modules\Pos\Entities\PosReturn::OPTION_LABELS[$return->return_option] ?? $return->return_option }}
                            </span>
                        </td>
                        <td>{{ format_currency($return->total_amount) }}</td>
                        <td>
                            <span class="badge badge-{{ $return->status_color }}">
                                {{ $return->status_label }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('pos.returns.show', $return) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center">Tidak ada data retur.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $returns->links() }}
    </div>
</div>

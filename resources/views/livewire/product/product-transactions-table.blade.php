<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Riwayat Transaksi</h5>
        <div class="input-group" style="max-width: 300px;">
            <input type="text" 
                   class="form-control form-control-sm" 
                   placeholder="Cari transaksi..." 
                   wire:model.live.debounce.300ms="searchQuery">
            @if($searchQuery)
                <button class="btn btn-outline-secondary btn-sm" wire:click="$set('searchQuery', '')">
                    <i class="bi bi-x"></i>
                </button>
            @endif
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Jenis</th>
                    <th>Jumlah</th>
                    <th>Jumlah Saat Ini</th>
                    <th>Lokasi</th>
                    <th>Alasan</th>
                </tr>
                </thead>
                <tbody>
                @forelse($transactions as $transaction)
                    <tr>
                        <td>{{ $transaction->formatted_created_at }}</td>
                        <td>{{ $transaction->type }}</td>
                        <td>{{ $transaction->quantity }}</td>
                        <td>{{ $transaction->current_quantity }}</td>
                        <td>{{ $transaction->location->name ?? 'N/A' }}</td>
                        <td>{{ $transaction->reason ?? 'N/A' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center">
                            @if($searchQuery)
                                Tidak ada transaksi yang cocok dengan pencarian "{{ $searchQuery }}".
                            @else
                                Tidak ada transaksi yang ditemukan.
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $transactions->links() }}
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Riwayat Nomor Seri</h5>
        <div class="input-group" style="max-width: 300px;">
            <input type="text" 
                   class="form-control form-control-sm" 
                   placeholder="Cari nomor seri..." 
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
                    <th style="width: 50px;"></th>
                    <th>Nomor Seri</th>
                    <th>Status</th>
                    <th>Lokasi</th>
                    <th class="text-center">Jumlah Event</th>
                </tr>
                </thead>
                <tbody>
                @forelse($serialNumbers as $serial)
                    <tr>
                        <td class="text-center">
                            <button class="btn btn-sm btn-link p-0" wire:click="toggleExpand({{ $serial->id }})">
                                @if(in_array($serial->id, $expandedSerials))
                                    <i class="bi bi-dash-square"></i>
                                @else
                                    <i class="bi bi-plus-square"></i>
                                @endif
                            </button>
                        </td>
                        <td>{{ $serial->serial_number }}</td>
                        <td>
                            @php
                                $statusClass = match(strtolower($serial->status)) {
                                    'active' => 'badge-success',
                                    'returned' => 'badge-info',
                                    'sold' => 'badge-secondary',
                                    default => 'badge-warning'
                                };
                            @endphp
                            <span class="badge {{ $statusClass }}">
                                {{ strtoupper($serial->status ?: 'N/A') }}
                            </span>
                        </td>
                        <td>{{ $serial->location->name ?? 'N/A' }}</td>
                        <td class="text-center">
                            <span class="badge badge-primary">{{ $serial->histories_count }}</span>
                        </td>
                    </tr>
                    @if(in_array($serial->id, $expandedSerials))
                        <tr>
                            <td colspan="5" class="p-0">
                                <div class="bg-light p-3">
                                    <h6 class="mb-3">Timeline Riwayat</h6>
                                    <table class="table table-sm table-bordered bg-white mb-0">
                                        <thead class="thead-light">
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Jenis Event</th>
                                            <th>Lokasi</th>
                                            <th>Referensi</th>
                                            <th>User</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @forelse($serial->histories as $history)
                                            <tr>
                                                <td>{{ $history->created_at->format('d/m/Y H:i') }}</td>
                                                <td>
                                                    {{ $this::EVENT_LABELS[$history->event_type] ?? $history->event_type }}
                                                </td>
                                                <td>{{ $history->location->name ?? '-' }}</td>
                                                <td>
                                                    @if($history->reference)
                                                        @php
                                                            $refType = class_basename($history->reference);
                                                            $refId = $history->reference->id;
                                                            // Basic mapping for common types, can be expanded
                                                            $refLabel = $refType . ' #' . $refId;
                                                        @endphp
                                                        <span class="text-primary">{{ $refLabel }}</span>
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td>{{ $history->user->name ?? 'System' }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="5" class="text-center">Tidak ada riwayat ditemukan.</td>
                                            </tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="5" class="text-center">
                            @if($searchQuery)
                                Tidak ada nomor seri yang cocok dengan pencarian "{{ $searchQuery }}".
                            @else
                                Tidak ada nomor seri yang ditemukan.
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $serialNumbers->links() }}
        </div>
    </div>
</div>

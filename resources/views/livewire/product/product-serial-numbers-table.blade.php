<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Nomor Seri</h5>
        <div class="input-group" style="max-width: 300px;">
            <input type="text" 
                   class="form-control form-control-sm" 
                   placeholder="Cari nomor seri..." 
                   wire:model.live.debounce.300ms="searchQuery">
            @if($searchQuery)
                <button class="btn btn-outline-secondary btn-sm" wire:click="clearSearch">
                    <i class="bi bi-x"></i>
                </button>
            @endif
        </div>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-3" id="serialNumberTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $currentTab === 'sellable' ? 'active' : '' }}" 
                        wire:click="setTab('sellable')" 
                        type="button">
                    Siap Jual <span class="badge bg-secondary ms-1">{{ $counts['sellable'] ?? 0 }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $currentTab === 'broken' ? 'active' : '' }}" 
                        wire:click="setTab('broken')" 
                        type="button">
                    Rusak <span class="badge bg-secondary ms-1">{{ $counts['broken'] ?? 0 }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $currentTab === 'returning' ? 'active' : '' }}" 
                        wire:click="setTab('returning')" 
                        type="button">
                    Dalam Proses Retur <span class="badge bg-secondary ms-1">{{ $counts['returning'] ?? 0 }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $currentTab === 'missing' ? 'active' : '' }}"
                        wire:click="setTab('missing')"
                        type="button">
                    Hilang <span class="badge bg-secondary ms-1">{{ $counts['missing'] ?? 0 }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $currentTab === 'history' ? 'active' : '' }}"
                        wire:click="setTab('history')"
                        type="button">
                    Riwayat / Tidak Tersedia <span class="badge bg-secondary ms-1">{{ $counts['history'] ?? 0 }}</span>
                </button>
            </li>
        </ul>

        @if($errorMessage)
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ $errorMessage }}
                <button type="button" class="btn-close" wire:click="$set('errorMessage', '')"></button>
            </div>
        @endif
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover">
                <thead>
                <tr>
                    <th>Nomor Seri</th>
                    <th>Status</th>
                    <th>Lokasi</th>
                    <th>Pajak</th>
                </tr>
                </thead>
                <tbody>
                @forelse($serialNumbers as $serial)
                    @php
                        $combinedState = $serial->getCombinedState();
                    @endphp
                    <tr>
                        <td>
                            @if($editingId === $serial->id)
                                <div class="d-flex align-items-center gap-2">
                                    <input type="text" 
                                           class="form-control form-control-sm" 
                                           style="max-width: 300px;"
                                           wire:model="editingValue"
                                           wire:keydown.enter="saveEdit"
                                           wire:keydown.escape="cancelEdit"
                                           autofocus>
                                    <button class="btn btn-success btn-sm" wire:click="saveEdit" title="Simpan">
                                        <i class="bi bi-check"></i>
                                    </button>
                                    <button class="btn btn-secondary btn-sm" wire:click="cancelEdit" title="Batal">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                            @else
                                <span class="serial-number-text" 
                                      style="cursor: pointer;" 
                                      wire:click="startEdit({{ $serial->id }}, '{{ addslashes($serial->serial_number) }}')"
                                      title="Klik untuk edit">
                                    {{ $serial->serial_number }}
                                    <i class="bi bi-pencil-fill text-muted ms-1" style="font-size: 0.75em;"></i>
                                </span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $combinedState['badge_class'] }}">
                                {{ $combinedState['label'] }}
                            </span>
                        </td>
                        <td>
                            @if($serial->status === \Modules\Product\Entities\ProductSerialNumber::STATUS_MISSING)
                                <span class="text-muted small">Lokasi Terakhir:</span> {{ $serial->location->name ?? 'N/A' }}
                            @else
                                {{ $serial->location->name ?? 'N/A' }}
                            @endif
                        </td>
                        <td>{{ $serial->tax->name ?? 'N/A' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center">
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
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mt-3">
            <div class="mb-2 mb-md-0">
                {{ $serialNumbers->links() }}
            </div>
            <a href="{{ route('products.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </div>
</div>

@extends('layouts.app')

@section('title', 'Konfigurasi Gudang Penjualan')

@section('content')
    <div class="container">
        @php($canEdit = auth()->user()?->can('saleLocations.edit'))
        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Lokasi Penjualan Aktif</span>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary text-white">{{ $setting->company_name }}</span>
                            @if($canEdit && $assignedLocations->isNotEmpty())
                                <form id="reorder-form" action="{{ route('sales-location-configurations.order') }}" method="POST" class="d-flex align-items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        Simpan Urutan
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                    <div class="card-body p-0">
                        @error('order')
                            <div class="alert alert-danger m-3 mb-0">
                                {{ $message }}
                            </div>
                        @enderror
                        <table class="table mb-0 table-striped">
                            <thead>
                                <tr>
                                    <th>Nama Lokasi</th>
                                    <th>Bisnis Asal</th>
                                    <th>Status</th>
                                    @if($canEdit)
                                        <th class="text-center">Prioritas</th>
                                    @endif
                                    <th class="text-center">POS</th>
                                    @if($canEdit)
                                        <th class="text-end">Aksi</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody data-assigned-locations>
                                @forelse($assignedLocations as $location)
                                    <tr data-location-id="{{ $location->id }}">
                                        <input type="hidden" name="order[]" value="{{ $location->id }}" form="reorder-form">
                                        <td>{{ $location->name }}</td>
                                        <td>{{ optional($location->setting)->company_name ?? 'Tidak diketahui' }}</td>
                                        <td>
                                            @if($location->setting_id === $setting->id)
                                                <span class="badge bg-success">Milik Bisnis</span>
                                            @else
                                                <span class="badge bg-warning text-dark">Dipinjam</span>
                                            @endif
                                        </td>
                                        @if($canEdit)
                                            <td class="text-center">
                                                <div class="btn-group" role="group" aria-label="Reorder location">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-move="up" title="Naikkan prioritas">
                                                        ↑
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-move="down" title="Turunkan prioritas">
                                                        ↓
                                                    </button>
                                                </div>
                                            </td>
                                        @endif
                                        <td class="text-center">
                                            @if($location->saleAssignment?->is_pos)
                                                <span class="badge bg-primary">Aktif</span>
                                            @else
                                                <span class="badge bg-secondary">Nonaktif</span>
                                            @endif
                                        </td>
                                        @if($canEdit)
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end flex-wrap gap-2">
                                                    <form action="{{ route('sales-location-configurations.update', $location->id) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="is_pos" value="{{ $location->saleAssignment?->is_pos ? 0 : 1 }}">
                                                        <button type="submit"
                                                                class="btn btn-sm {{ $location->saleAssignment?->is_pos ? 'btn-outline-secondary' : 'btn-outline-primary' }}">
                                                            {{ $location->saleAssignment?->is_pos ? 'Nonaktifkan POS' : 'Jadikan POS' }}
                                                        </button>
                                                    </form>

                                                    @if($location->setting_id !== $setting->id)
                                                        <form action="{{ route('sales-location-configurations.destroy', $location->id) }}" method="POST" class="d-inline"
                                                              onsubmit="return confirm('Kembalikan lokasi ini ke bisnis asal?');">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Kembalikan</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $canEdit ? 6 : 4 }}" class="text-center py-4">
                                            Belum ada lokasi penjualan yang dikonfigurasi.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer small text-muted">
                        Lokasi yang dimiliki bisnis ini akan selalu tersedia dan tidak dapat dihapus dari konfigurasi.
                    </div>
                </div>
            </div>
            @if($canEdit)
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">Tambah Lokasi Penjualan</div>
                        <div class="card-body">
                            <form action="{{ route('sales-location-configurations.store') }}" method="POST">
                                @csrf
                                <div class="mb-3">
                                    <label for="location_id" class="form-label">Pilih Lokasi dari Bisnis Lain</label>
                                    <select name="location_id" id="location_id" class="form-select" @if($availableLocations->isEmpty()) disabled @endif>
                                        <option value="">-- Pilih Lokasi --</option>
                                        @foreach($availableLocations as $location)
                                            <option value="{{ $location->id }}" @selected(old('location_id') == $location->id)>
                                                {{ $location->name }} — {{ optional($location->setting)->company_name ?? 'Tidak diketahui' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('location_id')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </div>
                                <button type="submit" class="btn btn-primary w-100" @if($availableLocations->isEmpty()) disabled @endif>
                                    Tambahkan Lokasi
                                </button>
                                @if($availableLocations->isEmpty())
                                    <p class="text-muted small mt-3 mb-0">
                                        Semua lokasi dari bisnis lain sedang digunakan. Kembalikan lokasi dari konfigurasi bisnis terkait untuk membuatnya tersedia kembali.
                                    </p>
                                @endif
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    @if($canEdit && $assignedLocations->isNotEmpty())
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const tableBody = document.querySelector('[data-assigned-locations]');
                const reorderForm = document.getElementById('reorder-form');

                if (!tableBody || !reorderForm) {
                    return;
                }

                const updateInputs = () => {
                    tableBody.querySelectorAll('tr[data-location-id]').forEach((row) => {
                        const hiddenInput = row.querySelector('input[name="order[]"]');

                        if (hiddenInput) {
                            hiddenInput.value = row.dataset.locationId;
                        }
                    });
                };

                tableBody.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-move]');

                    if (!button) {
                        return;
                    }

                    event.preventDefault();

                    const row = button.closest('tr[data-location-id]');

                    if (!row) {
                        return;
                    }

                    if (button.dataset.move === 'up' && row.previousElementSibling) {
                        row.parentElement.insertBefore(row, row.previousElementSibling);
                    }

                    if (button.dataset.move === 'down' && row.nextElementSibling) {
                        row.parentElement.insertBefore(row.nextElementSibling, row);
                    }

                    updateInputs();
                });

                reorderForm.addEventListener('submit', () => {
                    updateInputs();
                });

                updateInputs();
            });
        </script>
    @endif
@endpush

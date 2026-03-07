@extends('layouts.app')

@section('title', 'Konfigurasi Lokasi Penjualan POS')

@section('content')
    <div class="container">
        @php($canEdit = auth()->user()?->can('saleLocations.edit'))
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Lokasi Penjualan POS Aktif</span>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary text-white">{{ $setting->company_name }}</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <table class="table mb-0 table-striped">
                            <thead>
                                <tr>
                                    <th>Nama Lokasi</th>
                                    <th>Bisnis Asal</th>
                                    <th>Status</th>
                                    @if($canEdit)
                                        <th class="text-end">Aksi</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($locations as $location)
                                    <tr>
                                        <td>{{ $location->name }}</td>
                                        <td>{{ optional($location->setting)->company_name ?? 'Tidak diketahui' }}</td>
                                        <td>
                                            @if($location->is_owned)
                                                <span class="badge bg-success">Milik Bisnis</span>
                                            @elseif($location->is_enabled)
                                                <span class="badge bg-primary">Enabled</span>
                                            @else
                                                <span class="badge bg-secondary">Disabled</span>
                                            @endif
                                        </td>
                                        @if($canEdit)
                                            <td class="text-end">
                                                @if($location->is_owned)
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Lokasi milik bisnis tidak dapat dinonaktifkan">
                                                        Disable
                                                    </button>
                                                @else
                                                    <form action="{{ route('sales-location-configurations.toggle', $location->id) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        @method('PATCH')
                                                        @if($location->is_enabled)
                                                            <input type="hidden" name="is_enabled" value="0">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Disable</button>
                                                        @else
                                                            <input type="hidden" name="is_enabled" value="1">
                                                            <button type="submit" class="btn btn-sm btn-outline-success">Enable</button>
                                                        @endif
                                                    </form>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $canEdit ? 4 : 3 }}" class="text-center py-4">
                                            Belum ada lokasi yang tersedia.
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
        </div>
    </div>
@endsection

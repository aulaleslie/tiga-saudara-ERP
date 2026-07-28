@extends('layouts.app')

@section('title', 'Konfigurasi Lokasi Penjualan POS')

@section('content')
    <div class="container">
        @php($canEdit = auth()->user()?->can('saleLocations.edit'))
        <div class="row">
            <div class="col-12">
                <form id="reorder-form" action="{{ route('sales-location-configurations.order') }}" method="POST">
                    @csrf
                    @method('PUT')
                    
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span>Lokasi Penjualan POS Aktif</span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary text-white">{{ $setting->company_name }}</span>
                                @if($canEdit)
                                    <button type="submit" class="btn btn-sm btn-primary ml-2">Simpan Urutan Prioritas</button>
                                @endif
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <table class="table mb-0 table-striped" id="locations-table">
                                <thead>
                                    <tr>
                                        @if($canEdit)
                                            <th style="width: 100px;">Prioritas</th>
                                        @endif
                                        <th>Nama Lokasi</th>
                                        <th>Bisnis Asal</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($activeLocations as $index => $location)
                                        <tr class="location-row">
                                            @if($canEdit)
                                                <td>
                                                    <input type="hidden" name="location_ids[]" value="{{ $location->id }}">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-secondary btn-move-up" title="Naikkan" {{ $loop->first ? 'disabled' : '' }}>
                                                            <i class="bi bi-arrow-up"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-secondary btn-move-down" title="Turunkan" {{ $loop->last ? 'disabled' : '' }}>
                                                            <i class="bi bi-arrow-down"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            @endif
                                            <td>{{ $location->name }}</td>
                                            <td>{{ optional($location->setting)->company_name ?? 'Tidak diketahui' }}</td>
                                            <td>
                                                @if($location->is_owned)
                                                    <span class="badge bg-success">Milik Bisnis</span>
                                                @else
                                                    <span class="badge bg-primary">Aktif</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ $canEdit ? 4 : 3 }}" class="text-center py-4">
                                                Belum ada lokasi aktif untuk prioritas.
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

                    @if($availableLocations->isNotEmpty())
                        <div class="card mt-4">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <span>Lokasi Tersedia (Belum Diaktifkan)</span>
                            </div>
                            <div class="card-body p-0">
                                <table class="table mb-0 table-striped" id="available-locations-table">
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
                                        @foreach($availableLocations as $location)
                                            <tr>
                                                <td>{{ $location->name }}</td>
                                                <td>{{ optional($location->setting)->company_name ?? 'Tidak diketahui' }}</td>
                                                <td>
                                                    <span class="badge bg-secondary">Tidak Aktif</span>
                                                </td>
                                                @if($canEdit)
                                                    <td class="text-end">
                                                        <button type="button" class="btn btn-sm btn-outline-success btn-toggle"
                                                            data-url="{{ route('sales-location-configurations.toggle', $location->id) }}"
                                                            data-enabled="0">
                                                            Aktifkan
                                                        </button>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer small text-muted">
                                Aktifkan lokasi untuk menambahkannya ke daftar prioritas POS.
                            </div>
                        </div>
                    @endif
                </form>
            </div>
        </div>
    </div>

    <!-- Toggle Form (Independent of Reorder Form) -->
    <form id="toggle-form" method="POST" style="display: none;">
        @csrf
        @method('PATCH')
        <input type="hidden" name="is_enabled" id="toggle-is-enabled">
    </form>
@endsection

@push('page_scripts')
<script>
    $(document).ready(function() {
        const $tableBody = $('#locations-table tbody');

        // Handle Movement
        $tableBody.on('click', '.btn-move-up, .btn-move-down', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const $row = $btn.closest('tr');

            if ($btn.hasClass('btn-move-up')) {
                const $prev = $row.prev('.location-row');
                if ($prev.length) {
                    $row.insertBefore($prev);
                }
            } else {
                const $next = $row.next('.location-row');
                if ($next.length) {
                    $row.insertAfter($next);
                }
            }

            updateButtonStates();
        });

        // Handle Toggle
        $tableBody.on('click', '.btn-toggle', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const url = $btn.data('url');
            const isEnabled = $btn.data('enabled') == '1';
            
            const $form = $('#toggle-form');
            $form.attr('action', url);
            $('#toggle-is-enabled').val(isEnabled ? '0' : '1');
            $form.submit();
        });

        function updateButtonStates() {
            const $rows = $tableBody.find('.location-row');
            $rows.each(function(index) {
                const $row = $(this);
                const isFirst = (index === 0);
                const isLast = (index === $rows.length - 1);
                
                $row.find('.btn-move-up').prop('disabled', isFirst);
                $row.find('.btn-move-down').prop('disabled', isLast);
            });
        }
    });
</script>
@endpush

@extends('layouts.app')

@section('title', 'Konfigurasi Pecahan Uang')

@section('content')
    <div class="container">
        @php
            $canEdit = auth()->user()?->can('cashDenominations.edit');
            $oldEnabledDenominationIds = old('enabled_denomination_ids', $enabledDenominationIds);
            $selectedDenominationIds = is_array($oldEnabledDenominationIds)
                ? array_map('intval', $oldEnabledDenominationIds)
                : $enabledDenominationIds;
        @endphp

        <div class="row">
            <div class="col-12">
                <form action="{{ route('cash-denomination-configurations.update') }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span>Konfigurasi Pecahan Uang</span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary text-white">Global</span>
                                @if($canEdit)
                                    <button type="submit" class="btn btn-sm btn-primary ml-2">
                                        Simpan Konfigurasi
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="card-body">
                            <p class="text-muted mb-4">
                                Pilih pecahan yang tersedia untuk proses penghitungan kas. Perubahan berlaku untuk seluruh perusahaan.
                            </p>

                            @error('enabled_denomination_ids')
                                <div class="alert alert-danger">{{ $message }}</div>
                            @enderror
                            @error('enabled_denomination_ids.*')
                                <div class="alert alert-danger">{{ $message }}</div>
                            @enderror

                            <div class="row">
                                @foreach(['coin' => 'Uang Logam', 'banknote' => 'Uang Kertas'] as $type => $title)
                                    <div class="col-lg-6 mb-4 mb-lg-0">
                                        <div class="border rounded h-100">
                                            <div class="bg-light border-bottom px-3 py-2 font-weight-bold">
                                                {{ $title }}
                                            </div>
                                            <div class="list-group list-group-flush">
                                                @foreach($denominationGroups->get($type, collect()) as $denomination)
                                                    <label class="list-group-item d-flex justify-content-between align-items-center mb-0" for="denomination-{{ $denomination->id }}">
                                                        <span>
                                                            <strong>{{ $denomination->label() }}</strong>
                                                            <small class="text-muted d-block">Satuan: {{ $denomination->unitLabel() }}</small>
                                                        </span>
                                                        <span class="custom-control custom-switch">
                                                            <input
                                                                type="checkbox"
                                                                class="custom-control-input"
                                                                id="denomination-{{ $denomination->id }}"
                                                                name="enabled_denomination_ids[]"
                                                                value="{{ $denomination->id }}"
                                                                @checked(in_array($denomination->id, $selectedDenominationIds, true))
                                                                @disabled(!$canEdit)
                                                            >
                                                            <span class="custom-control-label">
                                                                {{ in_array($denomination->id, $selectedDenominationIds, true) ? 'Aktif' : 'Tidak Aktif' }}
                                                            </span>
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        @unless($canEdit)
                            <div class="card-footer small text-muted">
                                Anda memiliki akses lihat, tetapi tidak memiliki izin untuk mengubah konfigurasi.
                            </div>
                        @endunless
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

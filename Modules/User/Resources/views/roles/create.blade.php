@extends('layouts.app')

@section('title', 'Buat Role')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('roles.index') }}">Peran</a></li>
        <li class="breadcrumb-item active">Buat</li>
    </ol>
@endsection

@push('page_css')
    <style>
        .custom-control-label {
            cursor: pointer;
        }
    </style>
@endpush

@section('content')
    @php
        use Modules\User\Helpers\PermissionHelper;
    @endphp
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                @include('utils.alerts')
                <form action="{{ route('roles.store') }}" method="POST" id="role-create-form">
                    @csrf
                    <input type="hidden" name="idempotency_token" value="{{ old('idempotency_token', $idempotencyToken) }}">
                    <div class="form-group mb-3 d-flex gap-2">
                        <x-button type="submit" class="btn btn-primary" processing-text="Menyimpan..." form="role-create-form">Buat Peran <i class="bi bi-check"></i></x-button>
                        <a href="{{ route('roles.index') }}" class="btn btn-secondary">Kembali</a>
                    </div>

                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="form-group mb-4">
                                <label for="name">Role Name <span class="text-danger">*</span></label>
                                <input class="form-control" type="text" name="name" required value="{{ old('name') }}">
                            </div>

                            <hr>

                            @php
                                $posGuidance = PermissionHelper::getPosGuidance();
                            @endphp

                            <div class="alert alert-info">
                                <div class="font-weight-bold mb-2">Panduan Bundle POS</div>
                                <div class="small mb-2">Gunakan bundle POS yang didukung sebagai default, lalu tambahkan pengecualian hanya bila memang dibutuhkan.</div>
                                <div class="row">
                                    @foreach($posGuidance['bundles'] as $bundle)
                                        <div class="col-lg-4 mb-2">
                                            <div class="border rounded p-2 h-100 bg-white">
                                                <div class="font-weight-bold">{{ $bundle['label'] }}</div>
                                                <div class="small text-muted mb-1">{{ $bundle['description'] }}</div>
                                                @if($bundle['permissions'] !== [])
                                                    <div class="small">{{ implode(', ', $bundle['permissions']) }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="small mt-2">
                                    Permission POS yang deprecated disembunyikan dari form ini dan dipertahankan hanya untuk migrasi peran lama.
                                </div>
                            </div>

                            <div class="form-group mb-2">
                                <label>Permissions <span class="text-danger">*</span></label>
                            </div>

                            <div class="form-group mb-3">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="select-all">
                                    <label class="custom-control-label" for="select-all">Beri Semua Hak Akses</label>
                                </div>
                            </div>

                            <div class="row gy-3">
                                @php
                                    $permissionGroups = PermissionHelper::getGroupsForForm();
                                @endphp

                                @foreach($permissionGroups as $groupName => $perms)
                                    <div class="col-lg-4 col-md-6 mb-3">
                                        <div class="card h-100 border-0 shadow">
                                            <div class="card-header">
                                                {{ $groupName }}
                                                <div class="custom-control custom-checkbox float-right">
                                                    <input type="checkbox" class="custom-control-input group-toggle" id="select-all-{{ \Illuminate\Support\Str::slug($groupName) }}" data-target="{{ \Illuminate\Support\Str::slug($groupName) }}">
                                                    <label class="custom-control-label" for="select-all-{{ \Illuminate\Support\Str::slug($groupName) }}">Pilih Semua</label>
                                                </div>
                                            </div>
                                            <div id="{{ \Illuminate\Support\Str::slug($groupName) }}" class="card-body">
                                                @foreach ($perms as $perm => $permData)
                                                    <div class="custom-control custom-switch mb-2">
                                                        @php
                                                            $inputId = str_replace(['.', '_'], '_', $perm);
                                                            $label = is_array($permData) ? $permData['label'] : $permData;
                                                            $checked = is_array($permData) ? $permData['checked'] : in_array($perm, old('permissions', []));
                                                        @endphp
                                                        <input type="checkbox" class="custom-control-input group-member" id="{{ $inputId }}" name="permissions[]" value="{{ $perm }}" {{ $checked || in_array($perm, old('permissions', [])) ? 'checked' : '' }}>
                                                        <label class="custom-control-label" for="{{ $inputId }}">{{ $label }}</label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endforeach

                            </div> {{-- row --}}
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        function syncGroupToggle(groupToggle) {
            const target = groupToggle.dataset.target;
            const container = document.getElementById(target);
            if (!container) return;
            const checkboxes = container.querySelectorAll('input[name="permissions[]"]');
            checkboxes.forEach(cb => cb.checked = groupToggle.checked);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const selectAll = document.getElementById('select-all');
            selectAll?.addEventListener('change', function () {
                const all = document.querySelectorAll('input[name="permissions[]"]');
                all.forEach(i => i.checked = this.checked);
                // also sync group toggles visually
                document.querySelectorAll('.group-toggle').forEach(gt => gt.checked = this.checked);
            });

            document.querySelectorAll('.group-toggle').forEach(function (toggle) {
                toggle.addEventListener('change', function () {
                    syncGroupToggle(this);
                });
            });

            // If any individual in group is toggled off, uncheck group select all; if all on, check it.
            document.querySelectorAll('.card-body').forEach(function (container) {
                const groupToggleId = 'select-all-' + container.id;
                const groupToggle = document.getElementById(groupToggleId);
                if (!groupToggle) return;
                const members = container.querySelectorAll('input[name="permissions[]"]');
                members.forEach(function (member) {
                    member.addEventListener('change', function () {
                        const allChecked = Array.from(members).every(i => i.checked);
                        groupToggle.checked = allChecked;
                    });
                });
            });
        });

        // Initialize form submission lock
        initFormSubmissionLock('role-create-form', 'role:submit-error');
    </script>
@endpush

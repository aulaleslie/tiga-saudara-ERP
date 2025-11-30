@php
    use Modules\Setting\Entities\Setting;
    use Spatie\Permission\Models\Role;
@endphp

@extends('layouts.app')

@section('title', 'Buat Akun')

@section('third_party_stylesheets')
    <link href="https://unpkg.com/filepond/dist/filepond.css" rel="stylesheet"/>
    <link href="https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.css" rel="stylesheet">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Akun</a></li>
        <li class="breadcrumb-item active">Buat</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <form action="{{ route('users.store') }}" method="POST" enctype="multipart/form-data" id="user-form">
            @csrf
            <input type="hidden" name="idempotency_token" value="{{ old('idempotency_token', $idempotencyToken) }}">
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="form-group">
                        <button class="btn btn-primary" type="submit">Buat Akun <i class="bi bi-check"></i></button>
                        <a href="{{ route('users.index') }}" class="btn btn-secondary">Kembali</a>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="name">Nama <span class="text-danger">*</span></label>
                                        <input class="form-control" type="text" name="name" required value="{{ old('name') }}">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="email">Email <span class="text-danger">*</span></label>
                                        <input class="form-control" type="email" name="email" required value="{{ old('email') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="password">Kata Sandi <span class="text-danger">*</span></label>
                                        <input class="form-control" type="password" name="password" required>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="password_confirmation">Konfirmasi Kata Sandi <span class="text-danger">*</span></label>
                                        <input class="form-control" type="password" name="password_confirmation" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Settings dan Peran <span class="text-danger">*</span></label>
                                <div class="card mb-3">
                                    <div class="card-body">
                                        @if(auth()->user()->hasRole('Super Admin'))
                                            @foreach(Setting::all() as $setting)
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input setting-checkbox" type="checkbox" name="settings[]"
                                                           value="{{ $setting->id }}" id="setting{{ $setting->id }}">
                                                    <label class="form-check-label" for="setting{{ $setting->id }}">
                                                        {{ $setting->company_name }}
                                                    </label>
                                                    <select class="form-control mt-2 role-select" name="roles[{{ $setting->id }}]"
                                                            id="role{{ $setting->id }}" disabled>
                                                        <option value="" selected disabled>Pilih Peran</option>
                                                        @foreach(Role::where('name', '!=', 'Super Admin')->get() as $role)
                                                            <option value="{{ $role->name }}">{{ $role->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @endforeach
                                        @else
                                            @foreach(auth()->user()->settings as $setting)
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input setting-checkbox" type="checkbox" name="settings[]"
                                                           value="{{ $setting->id }}" id="setting{{ $setting->id }}">
                                                    <label class="form-check-label" for="setting{{ $setting->id }}">
                                                        {{ $setting->company_name }}
                                                    </label>
                                                    <select class="form-control mt-2 role-select" name="roles[{{ $setting->id }}]"
                                                            id="role{{ $setting->id }}" disabled>
                                                        <option value="" selected disabled>Pilih Peran</option>
                                                        @foreach(Role::where('name', '!=', 'Super Admin')->get() as $role)
                                                            <option value="{{ $role->name }}">{{ $role->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="is_active">Status <span class="text-danger">*</span></label>
                                <select class="form-control" name="is_active" id="is_active" required>
                                    <option value="" selected disabled>Pilih Status</option>
                                    <option value="1">Aktif</option>
                                    <option value="0">Tidak Aktif</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-group">
                                <label for="image">Foto Profil <span class="text-danger">*</span></label>
                                <input id="image" type="file" name="image" data-max-file-size="500KB">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@section('third_party_scripts')
    <script src="https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.js"></script>
    <script src="https://unpkg.com/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.js"></script>
    <script src="https://unpkg.com/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.js"></script>
    <script src="https://unpkg.com/filepond/dist/filepond.js"></script>
@endsection

@push('page_scripts')
    <script>
        // Enable/disable role selection based on checkbox state
        document.querySelectorAll('.setting-checkbox').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                const roleSelect = document.getElementById('role' + this.value);
                roleSelect.disabled = !this.checked;
                if (!this.checked) {
                    roleSelect.selectedIndex = 0;  // Reset role selection if checkbox is unchecked
                }
            });
        });

        // Form submission validation to ensure role is selected for each checked setting
        document.getElementById('user-form').addEventListener('submit', function(event) {
            let valid = true;
            document.querySelectorAll('.setting-checkbox:checked').forEach(function(checkbox) {
                const roleSelect = document.getElementById('role' + checkbox.value);
                if (roleSelect.disabled || !roleSelect.value) {
                    alert('Please select a role for each selected setting.');
                    roleSelect.focus();
                    valid = false;
                }
            });
            if (!valid) {
                event.preventDefault();  // Prevent form submission
            }
        });

        // FilePond initialization
        FilePond.registerPlugin(
            FilePondPluginImagePreview,
            FilePondPluginFileValidateSize,
            FilePondPluginFileValidateType
        );
        const fileElement = document.querySelector('input[id="image"]');
        const pond = FilePond.create(fileElement, {
            acceptedFileTypes: ['image/png', 'image/jpg', 'image/jpeg'],
        });
        FilePond.setOptions({
            server: {
                url: "{{ route('filepond.upload') }}",
                headers: {
                    "X-CSRF-TOKEN": "{{ csrf_token() }}"
                }
            }
        });
    </script>
@endpush

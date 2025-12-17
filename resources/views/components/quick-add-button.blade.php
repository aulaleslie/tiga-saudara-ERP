{{-- Quick Add Button Component --}}
@props([
    'entity' => '', // e.g., 'supplier', 'customer', 'payment-term'
    'permission' => '', // e.g., 'suppliers.create'
    'modalEvent' => '', // e.g., 'openSupplierModal'
    'tooltip' => 'Tambah baru',
    'size' => 'sm',
    'class' => ''
])

@if($permission && auth()->user()->can($permission) || !$permission)
    <button
        type="button"
        class="btn btn-outline-primary btn-{{ $size }} ms-1 {{ $class }}"
        data-bs-toggle="tooltip"
        data-bs-placement="top"
        title="{{ $tooltip }}"
        onclick="if (window.Livewire) { Livewire.dispatch('{{ $modalEvent }}'); }"
    >
        <i class="bi bi-plus-circle"></i>
    </button>
@else
    <button
        type="button"
        class="btn btn-outline-secondary btn-{{ $size }} ms-1 {{ $class }}"
        disabled
        data-bs-toggle="tooltip"
        data-bs-placement="top"
        title="Tidak memiliki izin untuk menambah {{ $entity }}"
    >
        <i class="bi bi-plus-circle"></i>
    </button>
@endif

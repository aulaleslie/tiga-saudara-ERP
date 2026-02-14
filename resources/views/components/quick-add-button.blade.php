{{-- Quick Add Button Component --}}
@props([
    'entity' => '', // e.g., 'supplier', 'customer', 'payment-term'
    'permission' => '', // e.g., 'suppliers.create'
    'modalEvent' => '', // e.g., 'openSupplierModal'
    'modalParams' => [], // Optional parameters to pass to the modal
    'tooltip' => 'Tambah baru',
    'size' => 'sm',
    'class' => ''
])

@php
    $onClickHandler = 'if (window.Livewire) { Livewire.dispatch(\'' . $modalEvent . '\'';
    if (!empty($modalParams)) {
        $onClickHandler .= ', ' . json_encode($modalParams);
    }
    $onClickHandler .= '); }';
@endphp

@if($permission && auth()->user()->can($permission) || !$permission)
    <button
        type="button"
        class="btn btn-outline-primary btn-{{ $size }} ms-1 {{ $class }}"
        data-coreui-toggle="tooltip"
        data-coreui-placement="top"
        title="{{ $tooltip }}"
        onclick="{{ $onClickHandler }}"
    >
        <i class="bi bi-plus-circle"></i>
    </button>
@else
    <button
        type="button"
        class="btn btn-outline-secondary btn-{{ $size }} ms-1 {{ $class }}"
        disabled
        data-coreui-toggle="tooltip"
        data-coreui-placement="top"
        title="Tidak memiliki izin untuk menambah {{ $entity }}"
    >
        <i class="bi bi-plus-circle"></i>
    </button>
@endif

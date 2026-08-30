@extends('layouts.app')

@section('title', 'Edit Pembelian')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <!-- Product Search Component -->
        @if($editMode !== \Modules\Purchase\Entities\Purchase::EDIT_MODE_MONETARY_ONLY)
        <div class="row">
            <div class="col-12">
                <livewire:purchase.search-product wire:key="purchase-search-product" />
            </div>
        </div>
        @endif

        <!-- Purchase Form -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <livewire:purchase.edit-form :purchaseId="$purchase->id" :isGlobal="isset($globalMode) && $globalMode" wire:key="purchase-edit-form" />
                </div>
            </div>
        </div>
    </div>

    <!-- Modals - placed outside component tree to prevent orphaning during re-renders -->
    <livewire:modules.purchase.modals.payment-term-quick-add-modal wire:key="page-payment-term-modal" />
    <livewire:modules.people.modals.supplier-quick-add-modal wire:key="page-supplier-modal" />
    <livewire:modules.product.modals.product-quick-add-modal wire:key="page-product-modal" />
    <livewire:modules.setting.modals.tax-quick-add-modal wire:key="page-tax-modal" />
@endsection

@push('page_scripts')
@endpush

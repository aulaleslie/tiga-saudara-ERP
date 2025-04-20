@php use Modules\People\Entities\Supplier;use Modules\Purchase\Entities\PaymentTerm; @endphp
@extends('layouts.app')

@section('title', 'Edit Purchase')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <!-- Search Product Livewire Component -->
        <div class="row">
            <div class="col-12">
                <livewire:purchase.search-product/>
            </div>
        </div>

        <!-- Purchase Form -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <livewire:purchase.edit-form :purchaseId="$purchase->id"/>
                </div>
            </div>
        </div>
    </div>

    @include('components.confirmation-modal')
@endsection

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const submitButton = document.getElementById('submitWithConfirmation');

            if (submitButton) {
                submitButton.addEventListener('click', function () {
                    console.log("submitWithConfirmation clicked")
                    showConfirmationModal(() => {
                        if (typeof Livewire !== 'undefined' && Livewire.dispatch) {
                            Livewire.dispatch('confirmSubmit');
                        } else {
                            console.warn('Livewire is not ready yet.');
                        }
                    }, 'Apakah Anda yakin ingin mengubah pembelian ini?');
                });
            }
        });
    </script>
@endpush

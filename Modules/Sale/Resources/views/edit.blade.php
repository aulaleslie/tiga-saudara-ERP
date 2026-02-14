@extends('layouts.app')

@section('title', 'Ubah Penjualan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
        <li class="breadcrumb-item active">Ubah</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <!-- Search Product Livewire Component -->
        <div class="row">
            <div class="col-12">
                <livewire:sale.search-product/>
            </div>
        </div>

        <!-- Sale Form -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <livewire:sale.edit-form :sale="$sale"/>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals - placed outside component tree to prevent orphaning during re-renders -->
    <livewire:modules.purchase.modals.payment-term-quick-add-modal wire:key="sale-edit-payment-term-modal" />
    <livewire:modules.people.modals.customer-quick-add-modal wire:key="sale-edit-customer-modal" />
    <livewire:modules.product.modals.product-quick-add-modal wire:key="sale-edit-product-modal" />
    <livewire:modules.setting.modals.tax-quick-add-modal wire:key="sale-edit-tax-modal" />

    @include('components.confirmation-modal')
@endsection

@push('page_scripts')
<script>
    document.addEventListener('livewire:init', () => {
    });

    document.addEventListener('DOMContentLoaded', function () {
        const submitButton = document.getElementById('submitWithConfirmation');
        const submitMethod = 'update';
        let isProcessing = false;

        const resolveLivewireComponent = () => {
            if (!submitButton || typeof Livewire === 'undefined' || typeof Livewire.find !== 'function') {
                return null;
            }

            const componentRoot = submitButton.closest('[wire\\:id]');
            if (!componentRoot) {
                return null;
            }

            const wireId = componentRoot.getAttribute('wire:id');
            if (!wireId) {
                return null;
            }

            try {
                return Livewire.find(wireId);
            } catch (error) {
                console.warn('Livewire component lookup failed.', error);
                return null;
            }
        };

        const readDropdownValue = (name) => {
            return document.querySelector(`input[name="${name}"]`)?.value || null;
        };

        const submitViaComponent = () => {
            const wire = resolveLivewireComponent();
            if (!wire) {
                return false;
            }

            const customerId = readDropdownValue('customer_id');
            const paymentTermId = readDropdownValue('payment_term');

            if (typeof wire.$call === 'function') {
                wire.$call(submitMethod, customerId, paymentTermId);
                return true;
            }

            if (typeof wire.call === 'function') {
                wire.call(submitMethod, customerId, paymentTermId);
                return true;
            }

            if (typeof wire[submitMethod] === 'function') {
                wire[submitMethod](customerId, paymentTermId);
                return true;
            }

            return false;
        };

        const setButtonProcessing = (processing = false) => {
            if (!submitButton) return;

            const spinner = submitButton.querySelector('.button-spinner');
            const textEl = submitButton.querySelector('.button-text');
            const defaultText = submitButton.dataset.defaultText || submitButton.textContent.trim();
            const processingText = submitButton.dataset.processingText || 'Processing…';

            if (processing) {
                submitButton.disabled = true;
                submitButton.classList.add('disabled');
                if (spinner) spinner.classList.remove('d-none');
                if (textEl) textEl.textContent = processingText;
            } else {
                submitButton.disabled = false;
                submitButton.classList.remove('disabled');
                if (spinner) spinner.classList.add('d-none');
                if (textEl) textEl.textContent = defaultText;
            }

            isProcessing = processing;
        };

        if (submitButton) {
            submitButton.addEventListener('click', function () {
                if (isProcessing) return;

                showConfirmationModal(() => {
                    setButtonProcessing(true);

                    if (submitViaComponent()) {
                        return;
                    }

                    console.warn('Livewire submit handler is not available.');
                    setButtonProcessing(false);
                }, 'Apakah Anda yakin ingin menyimpan penjualan ini?');
            });
        }

        window.addEventListener('sale:submit-start', () => setButtonProcessing(true));
        window.addEventListener('sale:submit-finish', () => setButtonProcessing(false));
    });
</script>
@endpush

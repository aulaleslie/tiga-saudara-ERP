@extends('layouts.app')

@section('title', 'Buat Penjualan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
        <li class="breadcrumb-item active">Tambah</li>
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
                    <livewire:sale.create-form :idempotencyToken="$idempotencyToken" />
                </div>
            </div>
        </div>
    </div>

    <!-- Modals - placed outside component tree to prevent orphaning during re-renders -->
    <livewire:modules.purchase.modals.payment-term-quick-add-modal wire:key="sale-payment-term-modal" />
    <livewire:modules.people.modals.customer-quick-add-modal wire:key="sale-customer-modal" />
    <livewire:modules.product.modals.product-quick-add-modal wire:key="sale-product-modal" />
    <livewire:modules.setting.modals.tax-quick-add-modal wire:key="sale-tax-modal" />

    @include('components.confirmation-modal')
@endsection

@push('page_scripts')
<script>
    document.addEventListener('livewire:init', () => {
        // Listen for payment-term-changed event from PaymentTermSearchDropdown
        // This updates the dueDate field when payment term changes
        Livewire.on('payment-term-changed', (event) => {
            const paymentTermId = event.paymentTermId;
            if (!paymentTermId) return;

            // Fetch payment term longevity and calculate due date
            fetch(`/api/payment-terms/${paymentTermId}`)
                .then(response => response.json())
                .then(data => {
                    const dateInput = document.getElementById('date');
                    const dueDateInput = document.getElementById('dueDate');
                    
                    if (dateInput && dueDateInput && data.longevity !== undefined) {
                        const date = new Date(dateInput.value);
                        date.setDate(date.getDate() + parseInt(data.longevity));
                        const newDueDate = date.toISOString().split('T')[0];
                        dueDateInput.value = newDueDate;
                        
                        // Also update Livewire property
                        dueDateInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                })
                .catch(error => {
                    console.error('Error fetching payment term:', error);
                });
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const submitButton = document.getElementById('submitWithConfirmation');
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
            return document.querySelector(`input[name=\"${name}\"]`)?.value || null;
        };

        const submitViaComponent = () => {
            const wire = resolveLivewireComponent();
            if (!wire) {
                return false;
            }

            const customerId = readDropdownValue('customer_id');
            const paymentTermId = readDropdownValue('payment_term');

            if (typeof wire.$call === 'function') {
                wire.$call('submit', customerId, paymentTermId);
                return true;
            }

            if (typeof wire.call === 'function') {
                wire.call('submit', customerId, paymentTermId);
                return true;
            }

            if (typeof wire.submit === 'function') {
                wire.submit(customerId, paymentTermId);
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
                }, 'Apakah Anda yakin ingin membuat penjualan ini?');
            });
        }

        window.addEventListener('sale:submit-start', () => setButtonProcessing(true));
        window.addEventListener('sale:submit-finish', () => setButtonProcessing(false));
    });
</script>
@endpush

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

    @include('components.confirmation-modal')
@endsection

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const submitButton = document.getElementById('submitWithConfirmation');
            let isProcessing = false;

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

                        if (typeof Livewire !== 'undefined' && Livewire.dispatch) {
                            Livewire.dispatch('confirmSubmit');
                        } else {
                            console.warn('Livewire is not ready yet.');
                            setButtonProcessing(false);
                        }
                    }, 'Apakah Anda yakin ingin membuat penjualan ini?');
                });
            }

            window.addEventListener('sale:submit-start', () => setButtonProcessing(true));
            window.addEventListener('sale:submit-finish', () => setButtonProcessing(false));
        });
    </script>
@endpush

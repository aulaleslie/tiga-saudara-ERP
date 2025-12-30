@extends('layouts.app')

@section('title', 'Buat Pengeluaran')

@section('content')
    <div class="container">
        {{-- Komponen Header --}}
{{--        @livewire('sale.dispatch-sale-header', ['sale' => $sale, 'locations' => $locations])--}}

        {{-- Tampilkan error validasi --}}
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('sales.storeDispatch', $sale->id) }}" method="POST">
            @csrf
            <livewire:sale.dispatch-sale-header :sale="$sale"/>

            <div class="card p-3">
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="dispatch_date">Tanggal Pengiriman</label>
                            <input type="date" class="form-control" name="dispatch_date" required value="{{ now()->format('Y-m-d') }}">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Komponen Tabel --}}
{{--            @livewire('sale.dispatch-sale-table', ['sale' => $sale, 'aggregatedProducts' => $aggregatedProducts])--}}
            <livewire:sale.dispatch-sale-table :sale="$sale" :aggregatedProducts="$aggregatedProducts" :locations="$locations"/>

            <button type="submit" class="btn btn-success mt-3">Kirim</button>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
           // Any initialization if needed
        });

        function handleSerialKeydown(event, compositeKey, productId, locationId) {
            if (event.key === 'Enter') {
                event.preventDefault(); // Prevent form submission
                addSerialFromInput(compositeKey, productId, locationId);
            }
        }

        function showError(compositeKey, message) {
            const errorContainer = document.getElementById(`serial-error-${compositeKey}`);
            if (errorContainer) {
                errorContainer.textContent = message;
                errorContainer.classList.remove('d-none');
            }
        }

        function clearError(compositeKey) {
            const errorContainer = document.getElementById(`serial-error-${compositeKey}`);
            if (errorContainer) {
                errorContainer.textContent = '';
                errorContainer.classList.add('d-none');
            }
        }

        async function addSerialFromInput(compositeKey, productId, locationId) {
            const input = document.getElementById(`serial-input-${compositeKey}`);
            const serial = input.value.trim();

            // Clear previous error
            clearError(compositeKey);

            if (!serial) return;

            if (!locationId) {
                showError(compositeKey, 'Pilih lokasi terlebih dahulu.');
                return;
            }

            // Check duplicate in current list (client-side)
            const container = document.getElementById(`serial-pills-container-${compositeKey}`);
            let existsLocally = false;
            container.querySelectorAll('input[type="hidden"]').forEach(hidden => {
               if (hidden.value === serial) existsLocally = true;
            });

            if (existsLocally) {
                showError(compositeKey, 'Serial number sudah ditambahkan di baris ini.');
                input.select();
                return;
            }

            // Disable input during validation
            input.disabled = true;

            try {
                // Server-side validation via AJAX
                // We use validate-dispatch endpoint check if serial exists, is at location, and not dispatched
                const response = await fetch("{{ route('serial-numbers.validate-dispatch') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        product_id: productId,
                        serial_number: serial,
                        location_id: locationId
                    })
                });

                const data = await response.json();

                if (!data.valid) {
                    showError(compositeKey, data.message);
                    input.disabled = false;
                    input.select();
                    return;
                }

                // Success - add pill
                addSerialPill(compositeKey, serial);
                input.value = '';
                input.disabled = false;
                input.focus();
                updateDerivedQuantity(compositeKey);

            } catch (error) {
                console.error('Serial validation error:', error);
                showError(compositeKey, 'Error validating serial number. Please try again.');
                input.disabled = false;
                input.focus();
            }
        }

        function addSerialPill(compositeKey, serial) {
            const container = document.getElementById(`serial-pills-container-${compositeKey}`);
            const pill = document.createElement('span');
            pill.className = 'badge badge-primary mr-1 mb-1 p-2 d-flex align-items-center';
            pill.innerText = serial + ' '; // text part

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `selectedSerialNumbers[${compositeKey}][]`;
            hiddenInput.value = serial;
            pill.appendChild(hiddenInput);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-link text-white p-0 ml-2';
            removeBtn.onclick = function() { removeSerialPill(removeBtn, compositeKey); };
            removeBtn.innerHTML = '<i class="bi bi-x"></i>';
            pill.appendChild(removeBtn);

            container.appendChild(pill);
        }

        function removeSerialPill(btn, compositeKey) {
            const pill = btn.parentElement;
            pill.remove();
            updateDerivedQuantity(compositeKey);
        }

        function updateDerivedQuantity(compositeKey) {
            const container = document.getElementById(`serial-pills-container-${compositeKey}`);
            const count = container.querySelectorAll('input[type="hidden"]').length;
            const qtyInput = document.getElementById(`quantity-${compositeKey}`);

            if (qtyInput) {
                qtyInput.value = count;
                // Dispatch input event for Livewire wire:model
                qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
                // Dispatch change event for Livewire wire:change
                qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    </script>
@endpush

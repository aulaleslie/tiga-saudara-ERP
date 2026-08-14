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

        <form action="{{ route('sales.storeDispatch', $sale->id) }}" method="POST" data-store-dispatch-id="{{ $sale->id }}">
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
            <div id="hidden-inputs-container">
                {{-- JS will inject hidden inputs here --}}
            </div>

            <livewire:sale.dispatch-sale-table :sale="$sale" :aggregatedProducts="$aggregatedProducts" :locations="$locations"/>

            <button type="submit" class="btn btn-success mt-3">Kirim</button>
        </form>
    </div>
@endsection

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
           // Any initialization if needed
        });

        function handleSerialKeydown(event, compositeKey, productId, taxId) {
            if (event.key === 'Enter') {
                event.preventDefault(); // Prevent form submission
                addSerialFromInput(compositeKey, productId, taxId);
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

        async function addSerialFromInput(compositeKey, productId, taxId) {
            const input = document.getElementById(`serial-input-${compositeKey}`);
            const serial = input.value.trim();

            // Clear previous error
            clearError(compositeKey);

            if (!serial) return;

            // Check duplicate in current list (client-side)
            const tbody = document.getElementById(`serial-rows-${compositeKey}`);
            let existsLocally = false;
            if (tbody) {
                tbody.querySelectorAll('tr.serial-row').forEach(tr => {
                    if (tr.dataset.serial === serial) existsLocally = true;
                });
            }

            if (existsLocally) {
                showError(compositeKey, 'Serial number sudah ditambahkan.');
                input.select();
                return;
            }

            // Disable input during validation
            input.disabled = true;

            try {
                // Server-side validation via AJAX
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
                        expected_tax_id: taxId
                    })
                });

                const data = await response.json();

                if (!data.valid) {
                    showError(compositeKey, data.message);
                    input.disabled = false;
                    input.select();
                    return;
                }

                // Success - add row and hidden inputs
                addSerialRow(compositeKey, serial, data.location_id, data.location_name, taxId, true);
                
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

        function addSerialRow(compositeKey, serial, locationId, locationName, taxId, notifyLivewire = true) {
            console.log('Adding serial row:', {compositeKey, serial, locationId, taxId});

            const tbody = document.getElementById(`serial-rows-${compositeKey}`);
            if (!tbody) return;

            // Hide placeholder if any
            const placeholder = document.getElementById(`no-serial-placeholder-${compositeKey}`);
            if (placeholder) placeholder.classList.add('d-none');

            // Create table row
            const tr = document.createElement('tr');
            tr.dataset.serial = serial;
            tr.className = 'serial-row border-top';
            
            // Serial Cell
            const tdSerial = document.createElement('td');
            tdSerial.className = 'pl-4 align-middle py-2';
            tdSerial.innerHTML = `<i class="bi bi-arrow-return-right text-primary opacity-50 mr-2"></i> <span class="fw-bold">${serial}</span>`;
            tr.appendChild(tdSerial);
            
            // Location Cell
            const tdLocation = document.createElement('td');
            tdLocation.className = 'align-middle text-center py-2';
            tdLocation.innerHTML = `<span class="badge bg-light-row text-muted border px-2 font-weight-normal">${locationName}</span>`;
            tr.appendChild(tdLocation);
            
            // Action Cell
            const tdAction = document.createElement('td');
            tdAction.className = 'text-right pr-4 align-middle py-2';
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-outline-danger btn-sm rounded-circle border-0';
            removeBtn.innerHTML = '<i class="bi bi-trash small"></i>';
            removeBtn.onclick = function() { removeSerialRow(tr, compositeKey, serial); };
            tdAction.appendChild(removeBtn);
            tr.appendChild(tdAction);
            
            tbody.appendChild(tr);

            // Add hidden inputs for form submission
            addHiddenInputs(compositeKey, serial, locationId);
            // Notify Livewire if needed
            if (notifyLivewire && window.Livewire) {
                Livewire.dispatch('addSerialNumber', {
                    compositeKey: compositeKey, 
                    serialNumber: serial, 
                    locationId: locationId, 
                    locationName: locationName,
                    taxId: taxId
                });
            }
        }

        function removeSerialRow(tr, compositeKey, serial) {
            const tbody = tr.parentElement;
            tr.remove();

            // Show placeholder if empty
            if (tbody.querySelectorAll('tr.serial-row').length === 0) {
                const placeholder = document.getElementById(`no-serial-placeholder-${compositeKey}`);
                if (placeholder) placeholder.classList.remove('d-none');
            }
            
            // Remove hidden inputs
            const container = document.getElementById('hidden-inputs-container');
            const inputs = container.querySelectorAll(`input[data-composite-key="${compositeKey}"][data-serial="${serial}"]`);
            inputs.forEach(input => input.remove());
            
            // Notify Livewire
            if (window.Livewire) {
                Livewire.dispatch('removeSerialNumber', {
                    compositeKey: compositeKey, 
                    serialNumber: serial
                });
            }
            
            updateDerivedQuantity(compositeKey);
        }

        function addHiddenInputs(compositeKey, serial, locationId) {
            const container = document.getElementById('hidden-inputs-container');
            
            // Serial Number Hidden
            const inputSerial = document.createElement('input');
            inputSerial.type = 'hidden';
            inputSerial.name = `selectedSerialNumbers[${compositeKey}][]`;
            inputSerial.value = serial;
            inputSerial.dataset.compositeKey = compositeKey;
            inputSerial.dataset.serial = serial;
            container.appendChild(inputSerial);
            
            // Location Hidden
            const inputLocation = document.createElement('input');
            inputLocation.type = 'hidden';
            inputLocation.name = `serialNumberLocations[${compositeKey}][${serial}]`;
            inputLocation.value = locationId;
            inputLocation.dataset.compositeKey = compositeKey;
            inputLocation.dataset.serial = serial;
            container.appendChild(inputLocation);
        }

        function updateDerivedQuantity(compositeKey) {
            const tbody = document.getElementById(`serial-rows-${compositeKey}`);
            const count = tbody ? tbody.querySelectorAll('tr.serial-row').length : 0;
            const qtyInput = document.getElementById(`quantity-${compositeKey}`);

            if (qtyInput) {
                qtyInput.value = count;
                qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
                qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    </script>
    @include('sale::partials.lifecycle-warning-modal')
@endpush

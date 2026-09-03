@php use Carbon\Carbon; @endphp
@extends('layouts.app')

@section('title', 'Penerimaan Pembelian')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Purchases</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.show', $purchase->id) }}">Details</a></li>
        <li class="breadcrumb-item active">Penerimaan Pembelian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>Penerimaan Pembelian</h5>
                        <strong>Nomor Referensi: {{ $purchase->reference }}</strong>
                    </div>
                    <div class="card-body">
                        <!-- Supplier and Invoice Info -->
                        <div class="row mb-4">
                            <div class="col-sm-6">
                                <h6>Info Supplier</h6>
                                <div><strong>{{ $purchase->supplier->supplier_name }}</strong></div>
                                <div>{{ $purchase->supplier->address }}</div>
                                <div>Email: {{ $purchase->supplier->supplier_email }}</div>
                                <div>Phone: {{ $purchase->supplier->supplier_phone }}</div>
                            </div>
                            <div class="col-sm-6">
                                <h6>Info Invoice</h6>
                                <div>Invoice: <strong>INV/{{ $purchase->reference }}</strong></div>
                                <div>Tanggal: {{ Carbon::parse($purchase->date)->format('d M, Y') }}</div>
                                <div>Status: <strong>{{ $purchase->status }}</strong></div>
                            </div>
                        </div>

                        <!-- Supplier Delivery Order Number -->
                        <form action="{{ route('purchases.storeReceive', $purchase->id) }}" method="POST">
                            @csrf
                            <div class="row mb-3">
                                <div class="col-sm-6">
                                    <label for="location_id">Lokasi</label>
                                    @livewire('modules.setting.location-search-dropdown', [
                                        'selected' => old('location_id'),
                                        'name' => 'location_id',
                                        'placeholder' => 'Pilih lokasi standar...',
                                        'allowCreate' => true,
                                        'consignmentFilter' => 'standard',
                                        'error' => $errors->first('location_id')
                                    ])
                                    @livewire('modules.setting.modals.location-quick-add-modal')
                                </div>
                                <div class="col-sm-6">
                                    <label for="external_delivery_number">Nomor Surat Jalan Supplier</label>
                                    <input type="text" name="external_delivery_number" id="external_delivery_number"
                                           class="form-control @error('external_delivery_number') is-invalid @enderror" 
                                           placeholder="Masukkan Nomor Surat Jalan"
                                           value="{{ old('external_delivery_number') }}">
                                    @error('external_delivery_number')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div id="validation-summary" class="alert alert-danger @if(!$errors->any()) d-none @endif">
                                <ul class="mb-0" id="validation-errors-list">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>

                            <!-- Receive Items -->
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="thead-dark">
                                    <tr>
                                        <th>Produk</th>
                                        <th>Dipesan / Sisa</th>
                                        <th>Jumlah Diterima</th>
                                        <th>Satuan</th>
                                        <th>Serial Number</th>
                                        <th>Catatan</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($purchase->purchaseDetails as $detail)
                                        <tr>
                                            <td>
                                                {{ $detail->product_name }}
                                                <br>
                                                <span class="badge badge-success">{{ $detail->product_code }}</span>
                                            </td>
                                            @php
                                                $factor = (float) ($detail->conversion_factor_value ?? 1);
                                                $orderedUnit = $detail->effective_unit_name;
                                                $baseUnit = $detail->effective_base_unit_name;
                                                $hasConversion = $factor > 1.0;
                                                // Remaining is canonical; show it in the ordered unit too when they differ.
                                                $remainingBase = (float) ($detail->quantity_remaining ?? 0);
                                                $remainingOrdered = $factor > 0 ? $remainingBase / $factor : $remainingBase;
                                            @endphp
                                            <td>
                                                <div>
                                                    Dipesan:
                                                    <strong>{{ rtrim(rtrim(number_format((float) $detail->effective_entered_quantity, 3, '.', ''), '0'), '.') }}</strong>
                                                    {{ $orderedUnit }}
                                                    @if ($hasConversion)
                                                        <br><small class="text-muted">
                                                            = {{ rtrim(rtrim(number_format((float) $detail->quantity, 3, '.', ''), '0'), '.') }} {{ $baseUnit }}
                                                        </small>
                                                    @endif
                                                </div>
                                                <div class="mt-1">
                                                    Sisa:
                                                    <span class="badge badge-warning">
                                                        {{ rtrim(rtrim(number_format($remainingBase, 3, '.', ''), '0'), '.') }} {{ $baseUnit }}
                                                    </span>
                                                    @if ($hasConversion)
                                                        <br><small class="text-muted">
                                                            ≈ {{ rtrim(rtrim(number_format($remainingOrdered, 3, '.', ''), '0'), '.') }} {{ $orderedUnit }}
                                                        </small>
                                                    @endif
                                                </div>
                                            </td>
                                            <td>
                                                <input type="number" name="received[{{ $detail->id }}]"
                                                       class="form-control"
                                                       min="0"
                                                       step="0.001"
                                                       value="{{ old("received.$detail->id", 0) }}"
                                                       data-require-serial="{{ $detail->product->serial_number_required ? 'true' : 'false' }}"
                                                       data-detail-id="{{ $detail->id }}"
                                                       data-conversion-factor="{{ $factor }}"
                                                       data-remaining-base="{{ $remainingBase }}"
                                                       id="received-{{ $detail->id }}"
                                                       {{ $detail->product->serial_number_required ? 'readonly' : '' }}>
                                            </td>
                                            <td>
                                                {{-- Only the ordered unit and the base unit may be received in. --}}
                                                @if ($hasConversion)
                                                    <select name="received_unit[{{ $detail->id }}]"
                                                            class="form-control received-unit-select"
                                                            data-detail-id="{{ $detail->id }}">
                                                        <option value="ordered" {{ old("received_unit.$detail->id", 'ordered') === 'ordered' ? 'selected' : '' }}>
                                                            {{ $orderedUnit }}
                                                        </option>
                                                        <option value="base" {{ old("received_unit.$detail->id") === 'base' ? 'selected' : '' }}>
                                                            {{ $baseUnit }}
                                                        </option>
                                                    </select>
                                                @else
                                                    <input type="hidden" name="received_unit[{{ $detail->id }}]" value="base">
                                                    <span class="text-muted">{{ $baseUnit }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($detail->product->serial_number_required)
                                                    <div class="serial-number-wrapper" data-detail-id="{{ $detail->id }}" data-product-id="{{ $detail->product_id }}">
                                                        <div class="input-group mb-2">
                                                            <input type="text"
                                                                   class="form-control serial-input"
                                                                   id="serial-input-{{ $detail->id }}"
                                                                   placeholder="Scan/Type Serial Number..."
                                                                   onkeydown="handleSerialKeydown(event, {{ $detail->id }}, {{ $detail->product_id }})">
                                                            <div class="input-group-append">
                                                                <button class="btn btn-primary" type="button" onclick="addSerialFromInput({{ $detail->id }}, {{ $detail->product_id }})">
                                                                    <i class="bi bi-plus"></i> Tambah
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div id="serial-error-{{ $detail->id }}" class="text-danger small mb-2 d-none"></div>
                                                        <div id="serial-info-{{ $detail->id }}" class="text-info small mb-2 d-none"></div>
                                                        <small class="text-muted d-block mb-2">Tekan Enter untuk menambahkan setelah scan.</small>

                                                        <div id="serial-pills-container-{{ $detail->id }}" class="d-flex flex-wrap">
                                                             @php
                                                                $oldSerials = old("serial_numbers.{$detail->id}", []);
                                                             @endphp
                                                             @if(is_array($oldSerials))
                                                                 @foreach ($oldSerials as $serial)
                                                                    <span class="badge badge-primary mr-1 mb-1 p-2 d-flex align-items-center">
                                                                        {{ $serial }}
                                                                        <input type="hidden" name="serial_numbers[{{ $detail->id }}][]" value="{{ $serial }}">
                                                                        <button type="button" class="btn btn-sm btn-link text-white p-0 ml-2" onclick="removeSerialPill(this, {{ $detail->id }})">
                                                                            <i class="bi bi-x"></i>
                                                                        </button>
                                                                    </span>
                                                                 @endforeach
                                                             @endif
                                                        </div>
                                                        @if ($errors->has("serial_numbers.$detail->id"))
                                                            <div class="text-danger mt-1">
                                                                {{ $errors->first("serial_numbers.$detail->id") }}
                                                            </div>
                                                        @endif
                                                        @if ($errors->has('serial_numbers'))
                                                            <div class="text-danger mt-1">
                                                                {{ $errors->first('serial_numbers') }}
                                                            </div>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-muted">Not Required</span>
                                                @endif
                                            </td>
                                            <td>
                                                <input type="text" name="notes[{{ $detail->id }}]" class="form-control"
                                                       placeholder="Optional" value="{{ old("notes.$detail->id") }}">
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <!-- Submit Button -->
                            <div class="text-right mt-3">
                                <button type="button" id="submit-btn" class="btn btn-primary" onclick="validateForm(this)">Konfirmasi Penerimaan</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Initial check to disable manual input for quantity if serials are required (redundant with HTML read-only but good for safety)
            document.querySelectorAll('input[data-require-serial="true"]').forEach(input => {
                input.setAttribute('readonly', true);
            });

            // Initialize quantity from old values if any
            document.querySelectorAll('.serial-number-wrapper').forEach(wrapper => {
                const detailId = wrapper.dataset.detailId;
                updateDerivedQuantity(detailId);
            });
        });

        function validateForm(btn) {
            // parseFloat, not parseInt: a fractional receipt such as 0.5 would
            // otherwise truncate to 0 and be reported as an empty submission.
            let totalReceived = 0;
            document.querySelectorAll('input[name^="received["]').forEach(input => {
                totalReceived += parseFloat(input.value) || 0;
            });

            if (totalReceived <= 0) {
                const summary = document.getElementById('validation-summary');
                const list = document.getElementById('validation-errors-list');
                list.innerHTML = '<li>Minimal satu produk harus memiliki jumlah diterima lebih dari 0.</li>';
                summary.classList.remove('d-none');
                summary.scrollIntoView({ behavior: 'smooth' });
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Memproses...';
            btn.form.submit();
        }

        function handleSerialKeydown(event, detailId, productId) {
            if (event.key === 'Enter') {
                event.preventDefault(); // Prevent form submission
                addSerialFromInput(detailId, productId);
            }
        }

        function showError(detailId, message) {
            const errorContainer = document.getElementById(`serial-error-${detailId}`);
            if (errorContainer) {
                errorContainer.textContent = message;
                errorContainer.classList.remove('d-none');
            }
        }

        function clearError(detailId) {
            const errorContainer = document.getElementById(`serial-error-${detailId}`);
            if (errorContainer) {
                errorContainer.textContent = '';
                errorContainer.classList.add('d-none');
            }
        }

        function showInfo(detailId, message) {
            const infoContainer = document.getElementById(`serial-info-${detailId}`);
            if (infoContainer) {
                infoContainer.textContent = message;
                infoContainer.classList.remove('d-none');
                // Auto-hide after 5 seconds to avoid clutter
                setTimeout(() => {
                    clearInfo(detailId);
                }, 5000);
            }
        }

        function clearInfo(detailId) {
            const infoContainer = document.getElementById(`serial-info-${detailId}`);
            if (infoContainer) {
                infoContainer.textContent = '';
                infoContainer.classList.add('d-none');
            }
        }

        async function addSerialFromInput(detailId, productId) {
            const input = document.getElementById(`serial-input-${detailId}`);
            const serial = input.value.trim();

            // Clear previous error
            clearError(detailId);
            clearInfo(detailId);

            if (!serial) return;

            // Check duplicate in current list (client-side)
            const container = document.getElementById(`serial-pills-container-${detailId}`);
            let existsLocally = false;
            container.querySelectorAll('input[type="hidden"]').forEach(hidden => {
               if (hidden.value === serial) existsLocally = true;
            });

            if (existsLocally) {
                showError(detailId, 'Serial number sudah ditambahkan di baris ini.');
                input.select();
                return;
            }

            // Disable input during validation
            input.disabled = true;

            try {
                // Server-side validation via AJAX
                const response = await fetch('/serial-numbers/validate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ 
                        product_id: productId, 
                        serial_number: serial 
                    })
                });
                
                const data = await response.json();
                
                if (!data.valid) {
                    showError(detailId, data.message);
                    input.disabled = false;
                    input.select();
                    return;
                }

                // Success - add pill
                addSerialPill(detailId, serial);
                
                if (data.info_message) {
                    showInfo(detailId, data.info_message);
                }

                input.value = '';
                input.disabled = false;
                input.focus();
                updateDerivedQuantity(detailId);
                
            } catch (error) {
                console.error('Serial validation error:', error);
                showError(detailId, 'Error validating serial number. Please try again.');
                input.disabled = false;
                input.focus();
            }
        }

        function addSerialPill(detailId, serial) {
            const container = document.getElementById(`serial-pills-container-${detailId}`);
            const pill = document.createElement('span');
            pill.className = 'badge badge-primary mr-1 mb-1 p-2 d-flex align-items-center';
            // Use textContent for safety against XSS for viewing, but we need HTML structure for the button
            // So we build it carefully
            pill.innerText = serial + ' '; // text part

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `serial_numbers[${detailId}][]`;
            hiddenInput.value = serial;
            pill.appendChild(hiddenInput);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-link text-white p-0 ml-2';
            removeBtn.onclick = function() { removeSerialPill(removeBtn, detailId); };
            removeBtn.innerHTML = '<i class="bi bi-x"></i>';
            pill.appendChild(removeBtn);

            container.appendChild(pill);
        }

        function removeSerialPill(btn, detailId) {
            const pill = btn.parentElement; // The span.badge
            pill.remove();
            updateDerivedQuantity(detailId);
        }

        function updateDerivedQuantity(detailId) {
            const container = document.getElementById(`serial-pills-container-${detailId}`);
            const count = container.querySelectorAll('input[type="hidden"]').length;
            const qtyInput = document.getElementById(`received-${detailId}`);
            if (qtyInput) {
                qtyInput.value = count;
                // A serial count is a count of base units, so this row must be
                // submitted in the base unit; otherwise the server would scale it
                // by the conversion factor and receive far too much.
                const unitSelect = document.querySelector(`select[name="received_unit[${detailId}]"]`);
                if (unitSelect) {
                    unitSelect.value = 'base';
                    unitSelect.disabled = true;
                    // A disabled select submits nothing; mirror the value so the
                    // server still receives an explicit base-unit choice.
                    let mirror = document.getElementById(`received-unit-mirror-${detailId}`);
                    if (!mirror) {
                        mirror = document.createElement('input');
                        mirror.type = 'hidden';
                        mirror.id = `received-unit-mirror-${detailId}`;
                        mirror.name = `received_unit[${detailId}]`;
                        unitSelect.parentNode.appendChild(mirror);
                    }
                    mirror.value = 'base';
                }
                // Dispatch input event in case other listeners are watching it
                qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    </script>
@endsection

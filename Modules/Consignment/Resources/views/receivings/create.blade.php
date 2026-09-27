@extends('layouts.app')

@section('title', 'Catat Penerimaan Fisik Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.receivals.index') }}">Konsinyasi</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.receivals.show', $receival->id) }}">{{ $receival->reference }}</a></li>
        <li class="breadcrumb-item active">Penerimaan Fisik</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('consignments.receivings.store', $receival->id) }}" method="POST" id="consignment-receiving-form">
            @csrf
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white font-weight-bold">
                    Informasi Penerimaan Fisik (Ref Dokumen: {{ $receival->reference }})
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Supplier:</small>
                            <strong>{{ $receival->supplier->supplier_name }}</strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Tanggal Dokumen:</small>
                            <div>{{ $receival->date->format('d/m/Y') }}</div>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Ref Surat Jalan Supplier:</small>
                            <div>{{ $receival->supplier_delivery_reference ?? '-' }}</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-md-6 mb-3">
                            <label for="location_id">Lokasi Konsinyasi <span class="text-danger">*</span></label>
                            <select name="location_id" id="location_id" class="form-control" required>
                                <option value="">-- Pilih Lokasi Konsinyasi --</option>
                                @foreach($locations as $loc)
                                    <option value="{{ $loc->id }}" {{ old('location_id') == $loc->id ? 'selected' : '' }}>
                                        {{ $loc->name }} (Konsinyasi)
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted">Hanya lokasi yang diklasifikasikan sebagai konsinyasi yang dapat dipilih.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="date">Tanggal Penerimaan Fisik <span class="text-danger">*</span></label>
                            <input type="date" name="date" id="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="external_delivery_number">No. Surat Jalan Penerimaan</label>
                            <input type="text" name="external_delivery_number" id="external_delivery_number" class="form-control" value="{{ old('external_delivery_number', $receival->supplier_delivery_reference) }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="note">Catatan Penerimaan</label>
                            <input type="text" name="note" id="note" class="form-control" value="{{ old('note') }}" placeholder="Catatan fisik...">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white font-weight-bold">
                    Rincian Barang yang Diterima (Penerimaan Penuh)
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 30%;">Produk</th>
                                    <th style="width: 15%;">Jumlah Disetujui</th>
                                    <th style="width: 15%;">Jumlah Diterima <span class="text-danger">*</span></th>
                                    <th style="width: 40%;">Nomor Seri (Serial Number)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($receival->lines as $line)
                                    <tr data-line-id="{{ $line->id }}" data-product-id="{{ $line->product_id }}" data-required-qty="{{ (int) $line->quantity }}" data-serialized="{{ $line->is_serialized ? 'true' : 'false' }}">
                                        <td>
                                            <div class="font-weight-bold">{{ $line->product_name }}</div>
                                            <small class="text-muted">{{ $line->product_code }}</small>
                                            @if($line->is_serialized)
                                                <span class="badge badge-info d-block mt-1" style="width: fit-content;">Serial Number Wajib</span>
                                            @endif
                                        </td>
                                        <td class="align-middle">{{ (int) $line->quantity }} {{ $line->unit_code }}</td>
                                        <td class="align-middle">
                                            <input type="number" name="details[{{ $line->id }}][quantity_received]" id="quantity-received-{{ $line->id }}" class="form-control form-control-sm font-weight-bold bg-light" value="{{ $line->is_serialized ? 0 : (int) $line->quantity }}" readonly>
                                        </td>
                                        <td>
                                            @if($line->is_serialized)
                                                <div class="serial-number-wrapper" data-line-id="{{ $line->id }}" data-product-id="{{ $line->product_id }}" data-target-qty="{{ (int) $line->quantity }}">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <span class="badge badge-secondary" id="serial-counter-{{ $line->id }}">0 / {{ (int) $line->quantity }} serials scanned</span>
                                                    </div>
                                                    <div class="input-group mb-2">
                                                        <input type="text" class="form-control form-control-sm serial-input" id="serial-input-{{ $line->id }}" placeholder="Scan/Type Serial Number..." onkeydown="handleSerialKeydown(event, {{ $line->id }}, {{ $line->product_id }})">
                                                        <div class="input-group-append">
                                                            <button class="btn btn-sm btn-primary" type="button" id="serial-add-btn-{{ $line->id }}" onclick="addSerialFromInput({{ $line->id }}, {{ $line->product_id }})">
                                                                <i class="bi bi-plus"></i> Tambah
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div id="serial-error-{{ $line->id }}" class="text-danger small mb-2 d-none"></div>
                                                    <div id="serial-info-{{ $line->id }}" class="text-info small mb-2 d-none"></div>
                                                    <div id="serial-requirement-msg-{{ $line->id }}" class="text-warning small mb-2">
                                                        This product requires exactly {{ (int) $line->quantity }} serial numbers; 0 have been captured.
                                                    </div>

                                                    <div id="serial-pills-container-{{ $line->id }}" class="d-flex flex-wrap">
                                                        @php
                                                            $oldSerials = old("details.{$line->id}.serial_numbers", []);
                                                        @endphp
                                                        @if(is_array($oldSerials))
                                                            @foreach ($oldSerials as $serial)
                                                                <span class="badge badge-primary mr-1 mb-1 p-2 d-flex align-items-center">
                                                                    <span>{{ $serial }}</span>
                                                                    <input type="hidden" name="details[{{ $line->id }}][serial_numbers][]" value="{{ $serial }}">
                                                                    <button type="button" class="btn btn-sm btn-link text-white p-0 ml-2" onclick="removeSerialPill(this, {{ $line->id }})">
                                                                        <i class="bi bi-x"></i>
                                                                    </button>
                                                                </span>
                                                            @endforeach
                                                        @endif
                                                    </div>
                                                </div>
                                            @else
                                                <span class="text-muted italic">- Tidak Memerlukan Serial -</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end mb-4">
                <a href="{{ route('consignments.receivals.show', $receival->id) }}" class="btn btn-secondary mr-2">Batal</a>
                <button type="submit" id="submit-btn" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Penerimaan Fisik (PENDING)</button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.serial-number-wrapper').forEach(wrapper => {
                const lineId = wrapper.dataset.lineId;
                updateDerivedState(lineId);
            });
            checkOverallFormValidity();
        });

        function handleSerialKeydown(event, lineId, productId) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addSerialFromInput(lineId, productId);
            }
        }

        function showError(lineId, message) {
            const errorContainer = document.getElementById(`serial-error-${lineId}`);
            if (errorContainer) {
                errorContainer.textContent = message;
                errorContainer.classList.remove('d-none');
            }
        }

        function clearError(lineId) {
            const errorContainer = document.getElementById(`serial-error-${lineId}`);
            if (errorContainer) {
                errorContainer.textContent = '';
                errorContainer.classList.add('d-none');
            }
        }

        function showInfo(lineId, message) {
            const infoContainer = document.getElementById(`serial-info-${lineId}`);
            if (infoContainer) {
                infoContainer.textContent = message;
                infoContainer.classList.remove('d-none');
                setTimeout(() => {
                    clearInfo(lineId);
                }, 5000);
            }
        }

        function clearInfo(lineId) {
            const infoContainer = document.getElementById(`serial-info-${lineId}`);
            if (infoContainer) {
                infoContainer.textContent = '';
                infoContainer.classList.add('d-none');
            }
        }

        async function addSerialFromInput(lineId, productId) {
            const wrapper = document.querySelector(`.serial-number-wrapper[data-line-id="${lineId}"]`);
            const targetQty = parseInt(wrapper.dataset.targetQty, 10);
            const container = document.getElementById(`serial-pills-container-${lineId}`);
            const currentCount = container.querySelectorAll('input[type="hidden"]').length;

            clearError(lineId);
            clearInfo(lineId);

            if (currentCount >= targetQty) {
                showError(lineId, `Jumlah serial maksimum (${targetQty}) telah tercapai.`);
                return;
            }

            const input = document.getElementById(`serial-input-${lineId}`);
            const serial = input.value.trim();

            if (!serial) return;

            let existsLocally = false;
            container.querySelectorAll('input[type="hidden"]').forEach(hidden => {
               if (hidden.value === serial) existsLocally = true;
            });

            if (existsLocally) {
                showError(lineId, 'Serial number sudah ditambahkan di baris ini.');
                input.select();
                return;
            }

            input.disabled = true;

            try {
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
                    showError(lineId, data.message);
                    input.disabled = false;
                    input.select();
                    return;
                }

                addSerialPill(lineId, serial);

                if (data.info_message) {
                    showInfo(lineId, data.info_message);
                }

                input.value = '';
                input.disabled = false;
                input.focus();
                updateDerivedState(lineId);

            } catch (error) {
                console.error('Serial validation error:', error);
                showError(lineId, 'Gagal memvalidasi nomor seri. Silakan coba lagi.');
                input.disabled = false;
                input.focus();
            }
        }

        function addSerialPill(lineId, serial) {
            const container = document.getElementById(`serial-pills-container-${lineId}`);
            const pill = document.createElement('span');
            pill.className = 'badge badge-primary mr-1 mb-1 p-2 d-flex align-items-center';

            const textSpan = document.createElement('span');
            textSpan.textContent = serial;
            pill.appendChild(textSpan);

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `details[${lineId}][serial_numbers][]`;
            hiddenInput.value = serial;
            pill.appendChild(hiddenInput);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-link text-white p-0 ml-2';
            removeBtn.onclick = function() { removeSerialPill(removeBtn, lineId); };
            removeBtn.innerHTML = '<i class="bi bi-x"></i>';
            pill.appendChild(removeBtn);

            container.appendChild(pill);
        }

        function removeSerialPill(btn, lineId) {
            const pill = btn.parentElement;
            pill.remove();
            updateDerivedState(lineId);
            const input = document.getElementById(`serial-input-${lineId}`);
            if (input) input.focus();
        }

        function updateDerivedState(lineId) {
            const wrapper = document.querySelector(`.serial-number-wrapper[data-line-id="${lineId}"]`);
            if (!wrapper) return;
            const targetQty = parseInt(wrapper.dataset.targetQty, 10);
            const container = document.getElementById(`serial-pills-container-${lineId}`);
            const currentCount = container.querySelectorAll('input[type="hidden"]').length;

            const qtyInput = document.getElementById(`quantity-received-${lineId}`);
            if (qtyInput) {
                qtyInput.value = currentCount;
            }

            const counterSpan = document.getElementById(`serial-counter-${lineId}`);
            if (counterSpan) {
                counterSpan.textContent = `${currentCount} / ${targetQty} serials scanned`;
            }

            const reqMsg = document.getElementById(`serial-requirement-msg-${lineId}`);
            const input = document.getElementById(`serial-input-${lineId}`);
            const addBtn = document.getElementById(`serial-add-btn-${lineId}`);

            if (currentCount === targetQty) {
                if (reqMsg) reqMsg.className = 'text-success small mb-2 d-none';
                if (input) input.disabled = true;
                if (addBtn) addBtn.disabled = true;
            } else {
                if (reqMsg) {
                    reqMsg.className = 'text-warning small mb-2';
                    reqMsg.textContent = `This product requires exactly ${targetQty} serial numbers; ${currentCount} have been captured.`;
                }
                if (input) input.disabled = false;
                if (addBtn) addBtn.disabled = false;
            }

            checkOverallFormValidity();
        }

        function checkOverallFormValidity() {
            let isValid = true;
            document.querySelectorAll('tr[data-serialized="true"]').forEach(tr => {
                const lineId = tr.dataset.lineId;
                const requiredQty = parseInt(tr.dataset.requiredQty, 10);
                const container = document.getElementById(`serial-pills-container-${lineId}`);
                const count = container ? container.querySelectorAll('input[type="hidden"]').length : 0;
                if (count !== requiredQty) {
                    isValid = false;
                }
            });

            const submitBtn = document.getElementById('submit-btn');
            if (submitBtn) {
                submitBtn.disabled = !isValid;
            }
        }
    </script>
@endsection

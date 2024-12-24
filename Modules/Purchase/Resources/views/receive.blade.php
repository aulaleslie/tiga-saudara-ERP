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
                                <div>Tanggal: {{ \Carbon\Carbon::parse($purchase->date)->format('d M, Y') }}</div>
                                <div>Status: <strong>{{ $purchase->status }}</strong></div>
                            </div>
                        </div>

                        <!-- Supplier Delivery Order Number -->
                        <form action="{{ route('purchases.storeReceive', $purchase->id) }}" method="POST">
                            @csrf
                            <div class="row mb-3">
                                <div class="col-sm-6">
                                    <label for="location_id">Lokasi</label>
                                    <select name="location_id" id="location_id" class="form-control" required>
                                        <option value="" selected disabled>Pilih Lokasi</option>
                                        @foreach ($locations as $location)
                                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label for="external_delivery_number">Nomor Surat Jalan Supplier</label>
                                    <input type="text" name="external_delivery_number" id="external_delivery_number"
                                           class="form-control" placeholder="Masukkan Nomor Surat Jalan">
                                </div>
                            </div>

                            <!-- Receive Items -->
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="thead-dark">
                                    <tr>
                                        <th>Produk</th>
                                        <th>Jumlah Dipesan</th>
                                        <th>Jumlah Sudah Diterima</th>
                                        <th>Jumlah Diterima</th>
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
                                            <td>{{ $detail->quantity }}</td>
                                            <td>{{ $detail->quantity_received ?? 0 }}</td>
                                            <td>
                                                <input type="number" name="received[{{ $detail->id }}]"
                                                       class="form-control"
                                                       min="0"
                                                       max="{{ $detail->quantity - ($detail->quantity_received ?? 0) }}"
                                                       value="0"
                                                       data-require-serial="{{ $detail->product->serial_number_required ? 'true' : 'false' }}"
                                                       data-detail-id="{{ $detail->id }}">
                                            </td>
                                            <td>
                                                @if ($detail->product->serial_number_required)
                                                    <div class="serial-number-container"
                                                         id="serial-number-container-{{ $detail->id }}">
                                                        <button type="button" class="btn btn-sm btn-secondary mb-2"
                                                                onclick="toggleSerialFields({{ $detail->id }})">
                                                            <i class="bi bi-chevron-down"></i> Toggle Serial Number
                                                            Fields
                                                        </button>
                                                        <div class="serial-fields d-none"
                                                             id="serial-fields-{{ $detail->id }}">
                                                            <!-- Serial Number Input Fields will be added dynamically -->
                                                        </div>
                                                    </div>
                                                @else
                                                    <span class="text-muted">Not Required</span>
                                                @endif
                                            </td>
                                            <td>
                                                <input type="text" name="notes[{{ $detail->id }}]" class="form-control"
                                                       placeholder="Optional">
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <!-- Submit Button -->
                            <div class="text-right mt-3">
                                <button type="submit" class="btn btn-primary">Konfirmasi Penerimaan</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Monitor input for quantity received
            document.querySelectorAll('input[name^="received"]').forEach(input => {
                input.addEventListener('input', function () {
                    const detailId = this.dataset.detailId;
                    const requireSerial = this.dataset.requireSerial === 'true';
                    const quantity = parseInt(this.value) || 0;

                    if (requireSerial) {
                        const container = document.getElementById(`serial-fields-${detailId}`);
                        container.innerHTML = ''; // Clear existing fields

                        for (let i = 0; i < quantity; i++) {
                            const field = document.createElement('input');
                            field.type = 'text';
                            field.name = `serial_numbers[${detailId}][]`;
                            field.className = 'form-control mb-2';
                            field.placeholder = `Serial Number ${i + 1}`;
                            container.appendChild(field);
                        }
                    }
                });
            });
        });

        // Toggle serial number fields visibility
        function toggleSerialFields(detailId) {
            const container = document.getElementById(`serial-fields-${detailId}`);
            container.classList.toggle('d-none');
        }
    </script>
@endsection

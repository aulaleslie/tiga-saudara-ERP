@extends('layouts.app')

@section('title', 'Inisiasi Stok Produk')

@section('content')
    <div class="container-fluid">
        <form id="initialize-product-stock-form"
              action="{{ route('products.storeInitialProductStock', ['product_id' => $product->id]) }}"
              method="POST">
            @csrf

            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary mr-2">Kembali</a>

                        <button type="submit" class="btn btn-primary" id="save-btn">Simpan</button>

                        @if ($product->serial_number_required)
                            <button type="submit" class="btn btn-primary" id="save-and-serial-btn"
                                    formaction="{{ route('products.storeInitialProductStockAndRedirectToInputSerialNumbers', ['product_id' => $product->id]) }}">
                                Simpan & Lanjut Input Serial Number
                            </button>
                        @endif
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">

                            {{-- JUMLAH (otomatis) --}}
                            <div class="form-row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="quantity">Jumlah Stok<span class="text-danger">*</span></label>
                                        <input
                                            id="quantity"
                                            name="quantity"
                                            type="number"
                                            class="form-control"
                                            step="1"
                                            min="0"
                                            required
                                            readonly
                                            onfocus="this.blur()"
                                            onkeydown="return false"
                                            onwheel="return false"
                                            inputmode="numeric"
                                        />
                                        <small class="text-muted">Diisi otomatis dari 4 kolom di bawah.</small>
                                    </div>
                                </div>
                            </div>

                            {{-- RINCIAN JUMLAH --}}
                            <div class="form-row">
                                <div class="col-md-3">
                                    <x-input label="Stok Non-PPN"
                                             name="quantity_non_tax"
                                             type="number"
                                             step="1"
                                             min="0"
                                             value="{{ old('quantity_non_tax', 0) }}"
                                             required/>
                                </div>
                                <div class="col-md-3">
                                    <x-input label="Stok PPN"
                                             name="quantity_tax"
                                             type="number"
                                             step="1"
                                             min="0"
                                             value="{{ old('quantity_tax', 0) }}"
                                             required/>
                                </div>
                                <div class="col-md-3">
                                    <x-input label="Stok Rusak Non-PPN"
                                             name="broken_quantity_non_tax"
                                             type="number"
                                             step="1"
                                             min="0"
                                             value="{{ old('broken_quantity_non_tax', 0) }}"
                                             required/>
                                </div>
                                <div class="col-md-3">
                                    <x-input label="Stok Rusak PPN"
                                             name="broken_quantity_tax"
                                             type="number"
                                             step="1"
                                             min="0"
                                             value="{{ old('broken_quantity_tax', 0) }}"
                                             required/>
                                </div>
                            </div>

                            {{-- HARGA (readonly, ditarik dari setting aktif) --}}
                            <div class="form-row">
                                <div class="col-md-4">
                                    <x-input label="Harga Beli Terakhir"
                                             name="last_purchase_price"
                                             type="number"
                                             step="0.01"
                                             value="{{ $last_purchase_price }}"
                                             disabled/>
                                </div>
                                <div class="col-md-4">
                                    <x-input label="Harga Beli Rata-Rata"
                                             name="average_purchase_price"
                                             type="number"
                                             step="0.01"
                                             value="{{ $average_purchase_price }}"
                                             disabled/>
                                </div>
                                <div class="col-md-4">
                                    <x-input label="Harga Jual"
                                             name="sale_price"
                                             type="number"
                                             step="0.01"
                                             value="{{ $sale_price }}"
                                             disabled/>
                                </div>
                            </div>

                            {{-- LOKASI (label: Perusahaan — Lokasi) --}}
                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-select label="Lokasi (Perusahaan — Lokasi)"
                                              name="location_id"
                                              :options="$locationOptions"
                                              required/>
                                </div>
                            </div>

                        </div> {{-- card-body --}}
                    </div> {{-- card --}}
                </div>
            </div>
        </form>
    </div>
@endsection

@section('third_party_scripts')
    <script>
        (function () {
            const get = (n) => document.querySelector('[name="'+n+'"]');
            const parts = [
                'quantity_non_tax',
                'quantity_tax',
                'broken_quantity_non_tax',
                'broken_quantity_tax'
            ];

            function toInt(v) {
                const n = parseInt(v, 10);
                return Number.isNaN(n) ? 0 : n;
            }

            function recalc() {
                const total = parts.reduce((sum, name) => sum + toInt(get(name)?.value || 0), 0);
                const q = get('quantity');
                if (q) q.value = total;
            }

            function clampNonNegative(name) {
                const el = get(name);
                if (!el) return;
                el.addEventListener('input', () => {
                    if (el.value === '') { recalc(); return; } // allow clearing briefly
                    const n = parseInt(el.value, 10);
                    if (Number.isNaN(n) || n < 0) el.value = 0;
                    recalc();
                });
                el.addEventListener('change', recalc);
            }

            document.addEventListener('DOMContentLoaded', function () {
                parts.forEach(clampNonNegative);
                recalc();
            });
        })();
    </script>
@endsection

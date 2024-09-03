@extends('layouts.app')

@section('title', 'Update Product')

@section('content')
    <div class="container-fluid">
        <form id="product-form" action="{{ route('products.update', $product->id) }}" method="POST"
              enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary mr-2">
                            Kembali
                        </a>
                        <x-button label="Update Product" icon="bi-check"/>
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Nama Produk" name="product_name"
                                             value="{{ old('product_name', $product->product_name) }}"/>
                                </div>
                                <div class="col-md-6">
                                    <x-input label="Kode Produk" name="product_code"
                                             value="{{ old('product_code', $product->product_code) }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-select label="Kategori" name="category_id"
                                              :options="$formattedCategories"
                                              selected="{{ old('category_id', $product->category_id) }}"/>
                                </div>
                                <div class="col-md-6">
                                    <x-select label="Merek" name="brand_id"
                                              :options="$brands->pluck('name', 'id')"
                                              selected="{{ old('brand_id', $product->brand_id) }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Stok" name="product_quantity" type="number" step="1"
                                             value="{{ old('product_quantity', $product->product_quantity) }}" disabled/>
                                </div>
                                <div class="col-md-6">
                                    <x-input label="Peringatan Jumlah Stok" name="product_stock_alert" type="number"
                                             step="1" value="{{ old('product_stock_alert', $product->product_stock_alert) }}"/>
                                </div>
                            </div>

                            <!-- Removed Old Fields and Added New Fields -->
                            <div class="form-row">
                                <div class="col-md-12">
                                    <div class="border p-3 mb-3">
                                        <div class="form-group">
                                            <input type="checkbox" name="is_purchased" id="is_purchased" value="1"
                                                   {{ $product->is_purchased ? 'checked' : '' }} readonly>
                                            <label for="is_purchased"><strong>Saya Beli Barang Ini</strong></label>

                                            <div class="row mt-3">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Beli" name="purchase_price" step="0.01"
                                                             value="{{ old('purchase_price', $product->purchase_price) }}"/>
                                                </div>
                                                <div class="col-md-6">
                                                    <x-select label="Pajak Beli" name="purchase_tax"
                                                              :options="['1' => 'PPN 11%']"
                                                              selected="{{ old('purchase_tax', $product->purchase_tax) }}"/>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bordered Group for "Saya Jual Barang Ini" -->
                            <div class="form-row">
                                <div class="col-md-12">
                                    <div class="border p-3 mb-3">
                                        <div class="form-group">
                                            <input type="checkbox" name="is_sold" id="is_sold" value="1"
                                                   {{ $product->is_sold ? 'checked' : '' }} readonly>
                                            <label for="is_sold"><strong>Saya Jual Barang Ini</strong></label>

                                            <div class="row mt-3">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Jual" name="sale_price" step="0.01"
                                                             value="{{ old('sale_price', $product->sale_price) }}"/>
                                                </div>
                                                <div class="col-md-6">
                                                    <x-select label="Pajak Jual" name="sale_tax"
                                                              :options="['1' => 'PPN 11%']"
                                                              selected="{{ old('sale_tax', $product->sale_tax) }}"/>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <br>
                                        <label>
                                            <input type="checkbox" name="stock_managed" id="stock_managed" value="1"
                                                   class="input-icheck" {{ old('stock_managed', $product->stock_managed) ? 'checked' : '' }}>
                                            <strong>Manajemen Stok</strong>
                                        </label>
                                        <i class="bi bi-question-circle-fill text-info" data-toggle="tooltip"
                                           data-placement="top"
                                           title="Stock Management should be disabled mostly for services. Example: Jasa Instalasi, Jasa Perbaikan, dll."></i>
                                        <p class="help-block"><i>Aktifkan opsi ini jika Anda ingin mengelola stok untuk produk ini.</i></p>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-select label="Unit Utama" name="base_unit_id"
                                              :options="$units->pluck('name', 'id')"
                                              selected="{{ old('base_unit_id', $product->base_unit_id) }}"/>
                                </div>

                                <div class="col-md-6">
                                    <x-input label="Barcode Unit Utama" name="barcode"
                                             value="{{ old('barcode', $product->barcode) }}"/>
                                </div>
                            </div>

                            <!-- Livewire component for Unit Conversion Table -->
                            <livewire:product.unit-conversion-table :conversions="old('conversions', $product->conversions->toArray())" :errors="$errors->toArray()"/>

                            <div class="form-group">
                                <label for="product_note">Catatan</label>
                                <textarea name="product_note" id="product_note" rows="4"
                                          class="form-control">{{ old('product_note', $product->product_note) }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-group">
                                <label for="image">Gambar Produk <i class="bi bi-question-circle-fill text-info"
                                                                    data-toggle="tooltip" data-placement="top"
                                                                    title="Max Files: 3, Max File Size: 1MB, Image Size: 400x400"></i></label>
                                <div
                                    class="dropzone d-flex flex-wrap flex-wrap align-items-center justify-content-center"
                                    id="document-dropzone">
                                    <div class="dz-message" data-dz-message>
                                        <i class="bi bi-cloud-arrow-up"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    @include('product::includes.category-modal')
@endsection

@section('third_party_scripts')
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script>
        $(document).ready(function () {
            function applyMask() {
                $('#purchase_price, #sale_price').maskMoney({
                    prefix: '{{ settings()->currency->symbol }}',
                    thousands: '{{ settings()->currency->thousand_separator }}',
                    decimal: '{{ settings()->currency->decimal_separator }}',
                    precision: 2,
                    allowZero: true,
                    allowNegative: false
                });
            }

            applyMask();

            // On focus, unmask to show raw value for editing and select all text
            $('#purchase_price, #sale_price').on('focus', function () {
                $(this).maskMoney('destroy'); // Remove mask during focus/typing
                $(this).val($(this).val().replace(/[^0-9.-]/g, '')); // Show raw value without formatting
                setTimeout(() => {
                    $(this).select(); // Select all text in the input
                }, 0);
            });

            // On blur, reapply the mask to format as currency
            $('#purchase_price, #sale_price').on('blur', function () {
                var value = parseFloat($(this).val().replace(/[^0-9.-]/g, ''));
                if (isNaN(value)) {
                    value = 0;
                }
                $(this).val(value.toFixed(2)); // Ensure two decimal places
                applyMask(); // Reapply the mask
                $(this).maskMoney('mask'); // Mask the value again
            });

            // Submit the form with unmasked values
            $('#product-form').submit(function () {
                var purchasePrice = $('#purchase_price').maskMoney('unmasked')[0];
                var salePrice = $('#sale_price').maskMoney('unmasked')[0];
                $('#purchase_price').val(purchasePrice);
                $('#sale_price').val(salePrice);
            });
        });
    </script>
    <script src="{{ asset('js/dropzone.js') }}"></script>
    <script>
        var uploadedDocumentMap = {}
        Dropzone.options.documentDropzone = {
            url: '{{ route('dropzone.upload') }}',
            maxFilesize: 1,
            acceptedFiles: '.jpg, .jpeg, .png',
            maxFiles: 3,
            addRemoveLinks: true,
            dictRemoveFile: "<i class='bi bi-x-circle text-danger'></i> remove",
            headers: {
                'X-CSRF-TOKEN': "{{ csrf_token() }}"
            },
            success: function (file, response) {
                $('form').append('<input type="hidden" name="document[]" value="' + response.name + '">');
                uploadedDocumentMap[file.name] = response.name;
            },
            removedfile: function (file) {
                file.previewElement.remove();
                var name = '';
                if (typeof file.file_name !== 'undefined') {
                    name = file.file_name;
                } else {
                    name = uploadedDocumentMap[file.name];
                }
                $('form').find('input[name="document[]"][value="' + name + '"]').remove();
            },
            init: function () {
                @if(isset($product) && $product->getMedia('images'))
                var files = {!! json_encode($product->getMedia('images')) !!};
                for (var i in files) {
                    var file = files[i];
                    this.options.addedfile.call(this, file);
                    this.options.thumbnail.call(this, file, file.original_url);
                    file.previewElement.classList.add('dz-complete');
                    $('form').append('<input type="hidden" name="document[]" value="' + file.file_name + '">');
                }
                @endif
            }
        }
    </script>
@endsection

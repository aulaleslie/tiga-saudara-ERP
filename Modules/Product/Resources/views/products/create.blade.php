@extends('layouts.app')

@section('title', 'Create Product')

@section('content')
    <div class="container-fluid">
        <form id="product-form" action="{{ route('products.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary mr-2">Kembali</a>
                        <x-button label="Tambah Produk" icon="bi-check"/>

                        <!-- Show when stock_managed is checked -->
                        @canany('create_products')
                        <button type="submit" class="btn btn-primary ml-2" id="stock-initiate-btn"
                                formaction="{{ route('products.storeProductAndRedirectToInitializeProductStock') }}"
                                style="display: none;">
                            Tambah Produk & Lanjut Inisiasi Stock
                        </button>
                        @endcanany
                    </div>
                </div>

                <!-- Product Details Section -->
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <!-- Product Name and Code -->
                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Nama Produk" name="product_name" required/>
                                </div>
                                <div class="col-md-6">
                                    <x-input label="Kode Produk" name="product_code" required/>
                                </div>
                            </div>

                            <!-- Kategori and Merek -->
                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-select label="Kategori" name="category_id" :options="$formattedCategories"
                                              addCategoryButton="true"/>
                                </div>
                                <div class="col-md-6">
                                    <x-select label="Merek" name="brand_id" :options="$brands->pluck('name', 'id')"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-12">
                                    <div class="border p-3 mb-3">
                                        <div class="form-group">
                                            <input type="checkbox" name="is_purchased" id="is_purchased" value="1"
                                                {{ old('is_purchased') ? 'checked' : '' }}>
                                            <label for="is_purchased"><strong>Saya Beli Barang Ini</strong></label>

                                            <div class="row mt-3">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Beli" name="purchase_price"
                                                             step="0.01" :disabled="!old('is_purchased')"
                                                             value="{{ old('purchase_price', $purchase_price ?? '') }}"/>
                                                </div>
                                                <div class="col-md-6">
                                                    <x-select label="Pajak Beli" name="purchase_tax_id"
                                                              :options="$taxes->pluck('name', 'id')"
                                                              :disabled="!old('is_purchased')"/>
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
                                                {{ old('is_sold') ? 'checked' : '' }}>
                                            <label for="is_sold"><strong>Saya Jual Barang Ini</strong></label>

                                            <div class="row mt-3">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Jual" name="sale_price"
                                                             step="0.01" :disabled="!old('is_sold')"
                                                             value="{{ old('sale_price', $sale_price ?? '') }}"/>
                                                </div>
                                                <div class="col-md-6">
                                                    <x-select label="Pajak Jual" name="sale_tax_id"
                                                              :options="$taxes->pluck('name', 'id')"
                                                              :disabled="!old('is_sold')"/>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Stock Management -->
                            <div class="form-row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>
                                            <input type="checkbox" name="stock_managed" id="stock_managed"
                                                   value="1" {{ old('stock_managed') ? 'checked' : '' }}>
                                            <strong>Manajemen Stok</strong>
                                        </label>
                                        <p class="help-block"><i>Aktifkan opsi ini jika Anda ingin mengelola stok untuk
                                                produk ini.</i></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Serial Number Requirement -->
                            <div class="form-row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <input type="checkbox" name="serial_number_required" id="serial_number_required"
                                               value="1" disabled>
                                        <label for="serial_number_required"><strong>Serial Number
                                                Diperlukan</strong></label>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Quantity and Stock Alert -->
                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Peringatan Jumlah Stok" name="product_stock_alert" type="number"
                                             step="1"/>
                                </div>
                            </div>

                            <!-- Unit and Barcode -->
                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-select label="Unit Utama" name="base_unit_id"
                                              :options="$units->pluck('name', 'id')"/>
                                </div>
                                <div class="col-md-6">
                                    <x-input label="Barcode Unit Utama" name="barcode"/>
                                </div>
                            </div>

                            <!-- Livewire component for Unit Conversion Table -->
                            <livewire:product.unit-conversion-table :conversions="old('conversions', [])"
                                                                    :errors="$errors->toArray()"/>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
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

            // Reapply mask on page load if old values are present
            function prefillMaskedValues() {
                let salePrice = "{{ old('sale_price') }}";
                let purchasePrice = "{{ old('purchase_price') }}";

                if (salePrice) {
                    $('#sale_price').val(parseFloat(salePrice).toFixed(2));
                    $('#sale_price').maskMoney('mask');
                }
                if (purchasePrice) {
                    $('#purchase_price').val(parseFloat(purchasePrice).toFixed(2));
                    $('#purchase_price').maskMoney('mask');
                }
            }

            prefillMaskedValues();

            // Submit the form with unmasked values
            $('#product-form').submit(function () {
                var purchasePrice = $('#purchase_price').maskMoney('unmasked')[0];
                var salePrice = $('#sale_price').maskMoney('unmasked')[0];
                $('#purchase_price').val(purchasePrice);
                $('#sale_price').val(salePrice);
            });

            function togglePurchaseFields() {
                $('#is_purchased').on('change', function () {
                    const isChecked = $(this).is(':checked');
                    $('#purchase_price').prop('disabled', !isChecked).val(isChecked ? $('#purchase_price').val() : '');
                    $('#purchase_tax_id').prop('disabled', !isChecked);

                    if (!isChecked) {
                        $('#purchase_price').val(''); // Clear purchase price
                        $('#purchase_tax_id').val(null); // Set tax ID to null (remove it)
                    }
                }).trigger('change'); // Trigger change to set the initial state
            }

            function toggleSaleFields() {
                $('#is_sold').on('change', function () {
                    const isChecked = $(this).is(':checked');
                    $('#sale_price').prop('disabled', !isChecked).val(isChecked ? $('#sale_price').val() : '');
                    $('#sale_tax_id').prop('disabled', !isChecked);

                    if (!isChecked) {
                        $('#sale_price').val(''); // Clear sale price
                        $('#sale_tax_id').val(null); // Set tax ID to null (remove it)
                    }
                }).trigger('change'); // Trigger change to set the initial state
            }

            togglePurchaseFields();
            toggleSaleFields();

            function toggleStockManagedFields() {
                const isStockManaged = $('#stock_managed').is(':checked');

                // Show "Tambah Produk & Lanjut Inisiasi Stock" button if stock_managed is checked
                if (isStockManaged) {
                    $('#stock-initiate-btn').show();
                } else {
                    $('#stock-initiate-btn').hide();
                }

                // Enable Serial Number Checkbox if stock_managed is checked and quantity is greater than 0
                if (isStockManaged) {
                    $('#serial_number_required').prop('disabled', false);
                } else {
                    $('#serial_number_required').prop('disabled', true).prop('checked', false); // Disable and uncheck
                }
            }

            // Call the function on page load and when the relevant fields change
            $('#stock_managed').on('change keyup', toggleStockManagedFields);
            toggleStockManagedFields(); // Initial check on page load // Trigger on page load
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
                "X-CSRF-TOKEN": "{{ csrf_token() }}"
            },
            success: function (file, response) {
                $('form').append('<input type="hidden" name="document[]" value="' + response.name + '">');
                uploadedDocumentMap[file.name] = response.name;
            },
            removedFile: function (file) {
                file.previewElement.remove();
                var name = '';
                if (typeof file.file_name !== 'undefined') {
                    name = file.file_name;
                } else {
                    name = uploadedDocumentMap[file.name];
                }
                $.ajax({
                    type: "POST",
                    url: "{{ route('dropzone.delete') }}",
                    data: {
                        '_token': "{{ csrf_token() }}",
                        'file_name': `${name}`
                    },
                });
                $('form').find('input[name="document[]"][value="' + name + '"]').remove();
            },
            init: function () {
                @if(isset($product) && $product.getMedia('images'))
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

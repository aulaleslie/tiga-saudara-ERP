@extends('layouts.app')

@section('title', 'Create Product')

@section('content')
    <div class="container-fluid">
        <form id="product-form" action="{{ route('products.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary mr-2">
                            Kembali
                        </a>
                        <x-button label="Tambah Produk" icon="bi-check"/>
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Nama Produk" name="product_name"/>
                                </div>
                                <div class="col-md-6">
                                    <x-input label="Kode Produk" name="product_code"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-select label="Kategori" name="category_id" :options="$formattedCategories"
                                              addCategoryButton="true"/>
                                </div>
                                <div class="col-md-6">
                                    <x-select label="Brand" name="brand_id" :options="$brands->pluck('name', 'id')"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Stock" name="product_quantity" type="number" step="1"/>
                                </div>
                                <div class="col-md-6">
                                    <x-input label="Stock Alert Quantity" name="product_stock_alert" type="number"
                                             step="1"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Pajak (%)" name="product_order_tax" type="number" step="0.01"/>
                                </div>
                                <div class="col-md-6">
                                    <x-select label="Jenis Pajak" name="product_tax_type"
                                              :options="['1' => 'Exclusive', '2' => 'Inclusive']"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Harga" name="product_cost" step="0.01"/>
                                </div>
                                <div class="col-md-6">
                                    <x-input label="Profit (%)" name="profit_percentage" step="0.01"
                                             placeholder="Enter Profit Percentage"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Harga Jual" name="product_price" step="0.01"
                                             value="{{ old('product_price', $product_price ?? '') }}"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <br>
                                        <label>
                                            <input type="checkbox" name="stock_managed" id="stock_managed" value="1"
                                                   class="input-icheck" {{ old('stock_managed') ? 'checked' : '' }}>
                                            <strong>Manage Stock</strong>
                                        </label>
                                        <i class="bi bi-question-circle-fill text-info" data-toggle="tooltip"
                                           data-placement="top"
                                           title="Stock Management should be disabled mostly for services. Example: Jasa Instalasi, Jasa Perbaikan, dll."></i>
                                        <p class="help-block"><i>Enable this option if you want to manage the stock for
                                                this product.</i></p>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-select label="Primary Unit" name="base_unit_id"
                                              :options="$units->pluck('name', 'id')"/>
                                </div>

                                <div class="col-md-6">
                                    <x-input label="Primary Unit Barcode" name="primary_unit_barcode"
                                             value="{{ old('primary_unit_barcode') }}"/>
                                </div>
                            </div>

                            <!-- Livewire component for Unit Conversion Table -->
                            <livewire:product.unit-conversion-table :conversions="old('conversions', [])"
                                                                    :errors="$errors"/>

                            <div class="form-group">
                                <label for="product_note">Catatan</label>
                                <textarea name="product_note" id="product_note" rows="4"
                                          class="form-control"></textarea>
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

    <!-- Create Category Modal -->
    @include('product::includes.category-modal')
@endsection

@section('third_party_scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script>
        $(document).ready(function () {
            const currencySymbol = '{{ settings()->currency->symbol }}';
            const thousandSeparator = '{{ settings()->currency->thousand_separator }}';
            const decimalSeparator = '{{ settings()->currency->decimal_separator }}';
            const precision = 2;

            $('#product_price').mask('#' + thousandSeparator + '##0' + decimalSeparator + '00', {
                reverse: true,
                translation: {
                    '#': {
                        pattern: /-?\d/,
                        recursive: true
                    }
                },
                onKeyPress: function (value, event) {
                    // Remove mask to get raw numeric value before submission
                    let numericValue = value.replace(new RegExp('\\' + thousandSeparator, 'g'), '').replace(decimalSeparator, '.');
                    $('#product_price').data('numeric-value', numericValue);
                }
            });

            // Calculate Harga Jual based on Harga, Pajak, Jenis Pajak, and Profit
            function parseCurrency(value) {
                return parseFloat(value.replace(/[^\d.-]/g, ''));
            }

            function calculateHargaJual() {
                let raw_product_cost = $('#product_cost').val();
                let product_cost = parseCurrency(raw_product_cost);

                let product_order_tax = parseFloat($('#product_order_tax').val()) || 0;
                let product_tax_type = $('#product_tax_type').val();
                let profit_percentage = parseFloat($('#profit_percentage').val()) || 0;

                // Check if product_cost or other fields are empty or NaN
                if (isNaN(product_cost) || product_cost === 0) {
                    $('#product_price').val('');  // Clear the Harga Jual field
                    return;
                }

                let product_price = 0;

                if (product_tax_type === '2') { // Inclusive
                    product_cost = product_cost / (1 + product_order_tax / 100);
                }

                product_price = product_cost + (product_cost * profit_percentage / 100);

                if (product_tax_type === '1') { // Exclusive
                    product_price += product_price * product_order_tax / 100;
                }

                $('#product_price').val(product_price.toFixed(precision));
                $('#product_price').trigger('input'); // Trigger mask update
            }

            $('#product_order_tax, #product_tax_type, #product_cost, #profit_percentage').on('input change', function () {
                calculateHargaJual();
            });

            // Submit the form with numeric values
            $('#product-form').on('submit', function () {
                $('#product_price').val($('#product_price').data('numeric-value'));
            });
        });
    </script>

    <script src="{{ asset('js/dropzone.js') }}"></script>
    <!-- Keep your existing Dropzone initialization -->
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

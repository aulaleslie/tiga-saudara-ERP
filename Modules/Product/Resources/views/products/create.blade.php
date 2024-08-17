@extends('layouts.app')

@section('title', 'Create Product')

@section('content')
    <div class="container-fluid">
        <form id="product-form" action="{{ route('products.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    <x-button label="Tambah Produk" icon="bi-check"/>
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
                                           title="Stock Management should be disable mostly for services. Example: Jasa Instalasi, Jasa Perbaikan, dll."></i>
                                        <p class="help-block"><i>Enable this option if you want to manage the stock for
                                                this product.</i></p>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row" id="primary-unit-section" style="display: none;">
                                <div class="col-md-6">
                                    <x-select label="Primary Unit" name="base_unit_id"
                                              :options="$units->pluck('name', 'id')"/>
                                </div>

                                <div class="col-md-6">
                                    <x-input label="Primary Unit Barcode" name="primary_unit_barcode"
                                             value="{{ old('primary_unit_barcode') }}"/>
                                </div>
                            </div>

                            <!-- Include partial for Unit Conversion -->
                            @include('product::products.partials._unit-conversion')

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
                                <div class="dropzone d-flex flex-wrap align-items-center justify-content-center"
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

            <!-- Hidden template for a conversion row -->
            <template id="conversion-row-template">
                <tr>
                    <td>
                        <x-select label="To Unit" name="conversions[0][unit_id]"
                                  :options="$units->pluck('name', 'id')"/>
                    </td>
                    <td>
                        <x-input label="Conversion Factor" name="conversions[0][conversion_factor]" type="number"
                                 step="0.0001"/>
                    </td>
                    <td>
                        <x-input label="Barcode" name="conversions[0][barcode]" placeholder="Barcode"/>
                    </td>
                    <td>
                        <button type="button" class="btn btn-danger remove_conversion">Remove</button>
                    </td>
                </tr>
            </template>

            @if(old('conversions'))
                @foreach(old('conversions') as $index => $conversion)
                    <tr>
                        <td>
                            <x-select label="To Unit" name="conversions[{{ $index }}][unit_id]" :options="$units->pluck('name', 'id')" selected="{{ old('conversions.' . $index . '.unit_id') }}" />
                        </td>
                        <td>
                            <x-input label="Conversion Factor" name="conversions[{{ $index }}][conversion_factor]" type="number" step="0.0001" value="{{ old('conversions.' . $index . '.conversion_factor') }}" />
                        </td>
                        <td>
                            <x-input label="Barcode" name="conversions[{{ $index }}][barcode]" value="{{ old('conversions.' . $index . '.barcode') }}" placeholder="Barcode" />
                        </td>
                        <td>
                            <button type="button" class="btn btn-danger remove_conversion">Remove</button>
                        </td>
                    </tr>
                @endforeach
            @endif
        </form>
    </div>

    <!-- Create Category Modal -->
    @include('product::includes.category-modal')
@endsection

@section('third_party_scripts')
    <script src="{{ asset('js/dropzone.js') }}"></script>
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
@endsection

@push('page_scripts')
    <script>
        $(document).ready(function () {
            // Handle stock_managed checkbox
            $('#stock_managed').change(function () {
                if ($(this).is(':checked')) {
                    $('#primary-unit-section').show();
                    $('#unit-conversion-section').show();
                } else {
                    $('#primary-unit-section').hide();
                    $('#unit-conversion-section').hide();
                }
            }).trigger('change');

            // Handle add conversion
            let conversionIndex = 0;

            // Handle add conversion
            $('#add_conversion').click(function () {
                let template = $('#conversion-row-template').html();

                // Replace placeholder index with actual index
                template = template.replace(/\[0\]/g, '[' + conversionIndex + ']');

                $('#conversion_table_body').append(template);
                conversionIndex++;
            });

            // Handle remove conversion
            $(document).on('click', '.remove_conversion', function () {
                $(this).closest('tr').remove();
            });

            // Calculate Harga Jual based on Harga, Pajak, Jenis Pajak, and Profit
            function parseCurrency(value) {
                return parseFloat(value.replace(/[^0-9.-]+/g, ""));
            }

            function calculateHargaJual() {
                let raw_product_cost = $('#product_cost').val();
                let product_cost = parseCurrency(raw_product_cost);

                let product_order_tax = parseFloat($('#product_order_tax').val()) || 0;
                let product_tax_type = $('#product_tax_type').val();
                let profit_percentage = parseFloat($('#profit_percentage').val()) || 0;

                // Check if product_cost or other  fields are empty or NaN
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

                $('#product_price').val(product_price.toFixed(2));
            }

            $('#product_order_tax, #product_tax_type, #product_cost, #profit_percentage').on('input change', function () {
                calculateHargaJual();
            });

            $('#product_price').on('input', function () {
                // Allow manual override of Harga Jual
            });

            calculateHargaJual(); // Initial calculation
        });
    </script>

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
            removedfile: function (file) {
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

    <script>
        $(document).ready(function () {
            const currencySymbol = '{{ settings()->currency->symbol }}';
            const thousandSeparator = '{{ settings()->currency->thousand_separator }}';
            const decimalSeparator = '{{ settings()->currency->decimal_separator }}';
            const precision = 2;

            function formatNumber(value) {
                if (!value) return '';
                // Remove non-numeric characters (except for the decimal point)
                value = value.replace(/[^\d.]/g, '');

                // Split the number into integer and decimal parts
                const parts = value.split('.');
                // Format the integer part with the thousand separator
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator);
                // Limit decimal places to the specified precision
                if (parts[1]) {
                    parts[1] = parts[1].substring(0, precision);
                }
                // Join the parts back together with the decimal separator
                return currencySymbol + parts.join(decimalSeparator);
            }

            function formatCurrency(input) {
                let value = input.value.replace(currencySymbol, '').trim();
                input.value = formatNumber(value);
            }

            $('#product_cost, #product_price').on('input', function () {
                formatCurrency(this);
            });

            $('#product-form').submit(function () {
                var product_cost = $('#product_cost').val().replace(new RegExp('\\' + thousandSeparator, 'g'), '').replace(currencySymbol, '').trim();
                var product_price = $('#product_price').val().replace(new RegExp('\\' + thousandSeparator, 'g'), '').replace(currencySymbol, '').trim();
                $('#product_cost').val(product_cost);
                $('#product_price').val(product_price);
            });
        });
    </script>
@endpush

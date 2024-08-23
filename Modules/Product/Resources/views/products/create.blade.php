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
                                    <x-select label="Merek" name="brand_id" :options="$brands->pluck('name', 'id')"/>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Stok" name="product_quantity" type="number" step="1"/>
                                </div>
                                <div class="col-md-6">
                                    <x-input label="Peringatan Jumlah Stok" name="product_stock_alert" type="number"
                                             step="1"/>
                                </div>
                                <div class="col-md-4">
                                    <x-select label="Lokasi" name="location_id" :options="$locations->pluck('name', 'id')"/>
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
                                              :options="$units->pluck('name', 'id')"/>
                                </div>

                                <div class="col-md-6">
                                    <x-input label="Barcode Unit Utama" name="primary_unit_barcode"
                                             value="{{ old('primary_unit_barcode') }}"/>
                                </div>
                            </div>

                            <!-- Livewire component for Unit Conversion Table -->
                            <livewire:product.unit-conversion-table :conversions="old('conversions', [])" :errors="$errors->toArray()"/>

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
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script>
        $(document).ready(function () {
            function applyMask() {
                $('#product_cost, #product_price').maskMoney({
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
            $('#product_cost, #product_price').on('focus', function () {
                $(this).maskMoney('destroy'); // Remove mask during focus/typing
                $(this).val($(this).val().replace(/[^0-9.-]/g, '')); // Show raw value without formatting
                setTimeout(() => {
                    $(this).select(); // Select all text in the input
                }, 0);
            });

            // On blur, reapply the mask to format as currency
            $('#product_cost, #product_price').on('blur', function () {
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
                var productCost = $('#product_cost').maskMoney('unmasked')[0];
                var productPrice = $('#product_price').maskMoney('unmasked')[0];
                $('#product_cost').val(productCost);
                $('#product_price').val(productPrice);
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

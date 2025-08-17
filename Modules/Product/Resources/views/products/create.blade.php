@extends('layouts.app')

@section('title', 'Buat Produk')

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
                        <button type="submit" class="btn btn-primary ml-2" id="stock-initiate-btn"
                                formaction="{{ route('products.storeProductAndRedirectToInitializeProductStock') }}"
                                style="display: none;">
                            Tambah Produk & Lanjut Inisiasi Stock
                        </button>
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

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Jual Partai Besar" name="tier_1_price"
                                                             step="0.01" :disabled="!old('is_sold')"
                                                             value="{{ old('tier_1_price', $tier_1_price ?? '') }}"/>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Jual Reseller" name="tier_2_price"
                                                             step="0.01" :disabled="!old('is_sold')"
                                                             value="{{ old('tier_2_price', $tier_2_price ?? '') }}"/>
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
                                        <p class="help-block"><i>Aktifkan opsi ini jika Anda ingin mengelola stok untuk produk ini.</i></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Serial Number Requirement -->
                            <fieldset id="stock-dependent">

                                <!-- Serial Number Requirement -->
                                <div class="form-row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <input type="checkbox" name="serial_number_required" id="serial_number_required"
                                                   value="1" {{ old('serial_number_required') ? 'checked' : '' }}>
                                            <label for="serial_number_required"><strong>Serial Number Diperlukan</strong></label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Product Quantity and Stock Alert -->
                                <div class="form-row">
                                    <div class="col-md-6">
                                        <x-input label="Peringatan Jumlah Stok" name="product_stock_alert" type="number" step="1"/>
                                    </div>
                                </div>

                                <!-- Unit and Barcode -->
                                <div class="form-row">
                                    <div class="col-md-6">
                                        <x-select label="Unit Utama" name="base_unit_id" :options="$units->pluck('name', 'id')" />
                                    </div>
                                    <div class="col-md-6">
                                        <x-input label="Barcode Unit Utama" name="barcode"/>
                                    </div>
                                </div>

                                <!-- Livewire component for Unit Conversion Table -->
                                <div class="form-row">
                                    <div class="col-lg-12">
                                        <div class="card">
                                            <div class="card-body">
                                                <livewire:product.unit-conversion-table
                                                    :conversions="old('conversions', [])"
                                                    :errors="$errors->toArray()"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </fieldset>

                            <div class="form-row">
                                <div class="col-lg-12">
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="form-group">
                                                <label for="image">Gambar Produk <i
                                                        class="bi bi-question-circle-fill text-info"
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
        $(function () {
            // === Mask helpers ===
            function applyMask() {
                $('#purchase_price, #sale_price, #tier_1_price, #tier_2_price').maskMoney({
                    prefix: '{{ settings()->currency->symbol }}',
                    thousands: '{{ settings()->currency->thousand_separator }}',
                    decimal: '{{ settings()->currency->decimal_separator }}',
                    precision: 2,
                    allowZero: true,
                    allowNegative: false
                });
            }
            function setMaskedZero($el) {
                // Force "0.00" visually, even if disabled
                $el.maskMoney('destroy');
                $el.val('0.00');
                applyMask();
                $el.maskMoney('mask');
            }

            applyMask();

            // === Focus/blur keepers (unchanged idea, just robust) ===
            $('#purchase_price, #sale_price, #tier_1_price, #tier_2_price')
                .on('focus', function () {
                    $(this).maskMoney('destroy');
                    $(this).val($(this).val().replace(/[^0-9.-]/g, ''));
                    setTimeout(() => this.select(), 0);
                })
                .on('blur', function () {
                    let v = parseFloat($(this).val().replace(/[^0-9.-]/g, ''));
                    if (isNaN(v)) v = 0;
                    $(this).val(v.toFixed(2));
                    applyMask();
                    $(this).maskMoney('mask');
                });

            // === Pre-fill from old() if present, else leave empty (we'll seed as needed) ===
            (function prefillMaskedValues() {
                const map = [
                    ['#purchase_price', "{{ old('purchase_price') }}"],
                    ['#sale_price', "{{ old('sale_price') }}"],
                    ['#tier_1_price', "{{ old('tier_1_price') }}"],
                    ['#tier_2_price', "{{ old('tier_2_price') }}"],
                ];
                map.forEach(([sel, raw]) => {
                    if (raw !== '') {
                        const n = parseFloat(raw);
                        if (!isNaN(n)) {
                            const $el = $(sel);
                            $el.val(n.toFixed(2));
                            $el.maskMoney('mask');
                        }
                    }
                });
            })();

            // === Toggling with correct defaults ===
            function togglePurchaseFields(initial = false) {
                const checked = $('#is_purchased').is(':checked');
                const $price = $('#purchase_price');
                const $tax   = $('#purchase_tax_id');

                $price.prop('disabled', !checked);
                $tax.prop('disabled',   !checked);

                if (!checked) {
                    // off -> show Rp0.00 + placeholder
                    setMaskedZero($price);
                    $tax.val('');            // Option A: empty value selects "Pilih Pajak ..."
                    $tax.trigger('change');
                } else if (initial && !$price.val().trim()) {
                    // checked on initial load but empty -> show 0.00 to avoid blank
                    setMaskedZero($price);
                }
            }

            function toggleSaleFields(initial = false) {
                const checked = $('#is_sold').is(':checked');
                const $sale  = $('#sale_price');
                const $tier1 = $('#tier_1_price');
                const $tier2 = $('#tier_2_price');
                const $tax   = $('#sale_tax_id');

                [$sale, $tier1, $tier2].forEach($i => $i.prop('disabled', !checked));
                $tax.prop('disabled', !checked);

                if (!checked) {
                    // off -> show Rp0.00 + placeholder
                    setMaskedZero($sale);
                    setMaskedZero($tier1);
                    setMaskedZero($tier2);
                    $tax.val('');            // Option A: empty value selects placeholder
                    $tax.trigger('change');
                } else if (initial) {
                    // checked on initial load but any empty -> seed 0.00 for clarity
                    if (!$sale.val().trim())  setMaskedZero($sale);
                    if (!$tier1.val().trim()) setMaskedZero($tier1);
                    if (!$tier2.val().trim()) setMaskedZero($tier2);
                }
            }

            // Bind and run once
            $('#is_purchased').on('change', () => togglePurchaseFields(false));
            $('#is_sold').on('change',      () => toggleSaleFields(false));
            togglePurchaseFields(true);
            toggleSaleFields(true);

            // === Submit: unmask to raw numbers ===
            $('#product-form').on('submit', function () {
                const un = (sel) => $(sel).maskMoney('unmasked')[0] ?? 0;
                $('#purchase_price').val(un('#purchase_price'));
                $('#sale_price').val(un('#sale_price'));
                $('#tier_1_price').val(un('#tier_1_price'));
                $('#tier_2_price').val(un('#tier_2_price'));
            });

            function resetStockDependentValues() {
                const $section = $('#stock-dependent');

                // Text-like and number inputs
                $section.find('input[type="text"], input[type="number"], input[type="tel"], input[type="email"], input[type="search"], input[type="url"]')
                    .val('');

                // Hidden inputs that belong to this section (if any)
                $section.find('input[type="hidden"]').each(function () {
                    // only clear if it’s clearly part of stock-dependent data (avoid CSRF etc.)
                    const name = this.name || '';
                    if (name.startsWith('conversions') || name.startsWith('barcode') || name.startsWith('product_stock_alert')) {
                        $(this).val('');
                    }
                });

                // Checkboxes & radios
                $section.find('input[type="checkbox"], input[type="radio"]').prop('checked', false);

                // Selects → set to placeholder (Option A uses empty value "")
                $section.find('select').val('').trigger('change');

                // Textareas
                $section.find('textarea').val('');

                // If your Livewire component renders inputs for conversions, clear them too
                // (this already catches them because they’re inputs/selects inside the section).
                // Optional: if you have a Livewire listener, emit a reset event:
                if (window.Livewire && typeof Livewire.dispatch === 'function') {
                    // Listen in component with: protected $listeners = ['unitConversion:reset' => 'resetRows'];
                    Livewire.dispatch('unitConversion:reset');
                }
            }

            // === Stock managed behaviour (unchanged) ===
            function toggleStockManagedFields() {
                const on = $('#stock_managed').is(':checked');

                // Show/hide the "Lanjut Inisiasi Stock" button as before
                $('#stock-initiate-btn').toggle(on);

                // Enable/disable every input/select/textarea inside #stock-dependent
                const $section = $('#stock-dependent');

                if (!on) {
                    resetStockDependentValues();
                }

                if (window.Livewire && typeof Livewire.dispatch === 'function') {
                    console.log(on)
                    Livewire.dispatch('stock:lock', {'locked': !on}); // true = lock, false = unlock
                }

                $section.find('input, select, textarea, button').prop('disabled', !on);

                // Optional: if turning OFF, clear “Serial Number Required” check visually
                if (!on) {
                    $('#serial_number_required').prop('checked', false);
                }

                // If you’re using any Select2 inside #stock-dependent, trigger change:
                $section.find('select').trigger('change');
            }

            // Bind and run once
            $('#stock_managed').on('change keyup', toggleStockManagedFields);
            toggleStockManagedFields();
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

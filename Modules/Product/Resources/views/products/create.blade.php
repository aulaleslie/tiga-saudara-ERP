@extends('layouts.app')

@section('title', 'Buat Produk')

@section('content')
    <div class="container-fluid">
        @php
            $categoryOptions = collect($formattedCategories ?? [])
                ->map(fn($name, $id) => ['id' => $id, 'name' => $name])
                ->values()
                ->all();

            $brandOptions = $brands->map(fn($brand) => ['id' => $brand->id, 'name' => $brand->name])
                ->values()
                ->all();

            $taxOptions = collect($taxes ?? [])->map(fn($tax) => [
                'id' => $tax->id,
                'name' => $tax->name,
                'value' => $tax->value,
            ])->values()->all();

            $unitOptions = $units->map(fn($unit) => ['id' => $unit->id, 'name' => $unit->name])
                ->values()
                ->all();
        @endphp
        <form id="product-form" action="{{ route('products.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="idempotency_token" value="{{ $idempotencyToken }}">

            @if($errors->any())
                <div class="alert alert-danger">
                    <strong>Periksa kembali data yang Anda masukkan.</strong>
                    <ul class="mb-0 mt-2">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary mr-2">Kembali</a>
                        <x-button label="Tambah Produk" icon="bi-check" processing-text="Memproses…" />

                        <!-- Show when stock_managed is checked -->
                        <x-button
                            type="submit"
                            class="ml-2"
                            id="stock-initiate-btn"
                            formaction="{{ route('products.storeProductAndRedirectToInitializeProductStock') }}"
                            style="display: none;"
                            label="Tambah Produk & Lanjut Inisiasi Stock"
                            icon="bi-arrow-right"
                            processing-text="Memproses…"
                        />
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
                                    <x-input label="Kode Produk" name="product_code"/>
                                    <small class="form-text text-muted">Biarkan kosong untuk auto-generate (SKU-000001, SKU-000002, dll.)</small>
                                </div>
                            </div>

                            <!-- Kategori and Merek -->
                            <div class="form-row">
                                <div class="col-md-6">
                                    <label for="category_search">Kategori</label>
                                    <livewire:modules.product.category-search-dropdown
                                        name="category_id"
                                        placeholder="Pilih kategori..."
                                        :options="$categoryOptions"
                                        :selected="old('category_id')"
                                        :clearable="true"
                                        :allow-create="true"
                                        :error="$errors->first('category_id')"
                                    />
                                </div>
                                <div class="col-md-6">
                                    <label for="brand_search">Merek</label>
                                    <livewire:modules.product.brand-search-dropdown
                                        name="brand_id"
                                        placeholder="Pilih merek..."
                                        :options="$brandOptions"
                                        :selected="old('brand_id')"
                                        :clearable="true"
                                        :allow-create="true"
                                        :error="$errors->first('brand_id')"
                                    />
                                </div>
                            </div>

                            <!-- Purchase Section -->
                            <div class="form-row mt-4">
                                <div class="col-md-12">
                                    <livewire:modules.product.product-price-setup
                                        type="purchase"
                                        :isActive="(bool) old('is_purchased')"
                                        :price="old('purchase_price', $purchase_price ?? '')"
                                        :taxId="old('purchase_tax_id')"
                                        priceLabel="Harga Beli"
                                        checkboxLabel="Saya Beli Barang Ini"
                                        taxLabel="Pajak Beli"
                                        fieldPrefix="purchase"
                                        :taxOptions="$taxOptions"
                                        :priceError="$errors->first('purchase_price')"
                                        :taxError="$errors->first('purchase_tax_id')"
                                    />
                                </div>
                            </div>

                            <!-- Sale Section -->
                            <div class="form-row mt-4">
                                <div class="col-md-12">
                                    <livewire:modules.product.sale-price-setup
                                        :isActive="(bool) old('is_sold')"
                                        :price="old('sale_price', $sale_price ?? '')"
                                        :tier1Price="old('tier_1_price', $tier_1_price ?? '')"
                                        :tier2Price="old('tier_2_price', $tier_2_price ?? '')"
                                        :taxId="old('sale_tax_id')"
                                        checkboxLabel="Saya Jual Barang Ini"
                                        :taxOptions="$taxOptions"
                                        :priceError="$errors->first('sale_price')"
                                        :taxError="$errors->first('sale_tax_id')"
                                        :tier1Error="$errors->first('tier_1_price')"
                                        :tier2Error="$errors->first('tier_2_price')"
                                    />
                                </div>
                            </div>



                            <livewire:product.unit-configuration
                                :unit-options="$unitOptions"
                                :initial-stock-managed="(bool) old('stock_managed')"
                                :initial-serial-required="(bool) old('serial_number_required')"
                                :initial-base-unit-id="old('base_unit_id') ? (int) old('base_unit_id') : null"
                                :initial-barcode="(string) old('barcode', '')"
                                :initial-stock-alert="old('product_stock_alert') ? (int) old('product_stock_alert') : null"
                                :initial-conversions="old('conversions', [])"
                                :errors="$errors->toArray()"
                            />

                            <div class="form-row mt-4">
                                <div class="col-lg-12">
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="form-group">
                                                <label for="image">Gambar Produk <i
                                                        class="bi bi-question-circle-fill text-info"
                                                        data-toggle="tooltip" data-placement="top"
                                                        title="Max Files: 3, Max File Size: 1MB, Image Size: 400x400"></i></label>
                                                @php $oldDocs = old('document', []); @endphp
                                                @if(is_array($oldDocs) && count($oldDocs))
                                                    @foreach($oldDocs as $temp)
                                                        <input type="hidden" name="document[]" value="{{ $temp }}">
                                                    @endforeach
                                                @endif
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
        <livewire:modules.product.modals.category-quick-add-modal />
        <livewire:modules.product.modals.brand-quick-add-modal />
        <livewire:modules.setting.modals.tax-quick-add-modal />
        <livewire:modules.setting.modals.unit-quick-add-modal />
    </div>
@endsection

@section('third_party_scripts')
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script>
        $(function () {
            // Event listeners for Alpine modals are now handled by the searchable dropdown components

            window.addEventListener('unit-config:stock-toggle', function (event) {
                const on = !!(event.detail && event.detail.stockManaged);
                $('#stock-initiate-btn').toggle(on);
            });
            const initialStockManaged = $('input[name="stock_managed"]').val() === '1';
            $('#stock-initiate-btn').toggle(initialStockManaged);

            function toggleFormSubmissionLock(form, processing = false) {
                $(form).find('.submit-lock-btn').each(function () {
                    const $btn = $(this);
                    const spinner = $btn.find('.button-spinner');
                    const textEl = $btn.find('.button-text');
                    const defaultText = $btn.data('default-text') || (textEl.length ? textEl.text().trim() : $btn.text().trim());
                    const processingText = $btn.data('processing-text') || 'Processing…';

                    if (!$btn.data('default-text') && defaultText) {
                        $btn.data('default-text', defaultText);
                    }

                    if (processing) {
                        if (spinner.length) spinner.removeClass('d-none');
                        if (textEl.length) textEl.text(processingText);
                        $btn.prop('disabled', true).addClass('disabled');
                    } else {
                        if (spinner.length) spinner.addClass('d-none');
                        if (textEl.length) textEl.text($btn.data('default-text'));
                        $btn.prop('disabled', false).removeClass('disabled');
                    }
                });
            }

            // === Mask helpers ===
            function applyMask() {
                $('#purchase_price, #sale_price, #tier_1_price, #tier_2_price, .conversion-price-input').maskMoney({
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


            function togglePurchaseFields(initial = false) {
                const checked = $('#is_purchased').is(':checked');
                const $price = $('#purchase_price');

                $price.prop('disabled', !checked);

                if (!checked) {
                    setMaskedZero($price);
                } else if (initial && !$price.val().trim()) {
                    setMaskedZero($price);
                }
            }

            function toggleSaleFields(initial = false) {
                const checked = $('#is_sold').is(':checked');
                const $sale  = $('#sale_price');

                $sale.prop('disabled', !checked);

                if (!checked) {
                    setMaskedZero($sale);
                } else if (initial) {
                    if (!$sale.val().trim()) setMaskedZero($sale);
                }
            }

            // Bind and run once
            $('#is_purchased').on('change', () => togglePurchaseFields(false));
            $('#is_sold').on('change',      () => toggleSaleFields(false));
            togglePurchaseFields(true);
            toggleSaleFields(true);

            // === Conversion price fields ===
            function bindConversionPriceInputs() {
                $('.conversion-price-input').each(function () {
                    const $input = $(this);
                    if ($input.data('bound') === 1) return;
                    $input.data('bound', 1);

                    const hiddenSelector = $input.data('hidden');
                    const $hidden = hiddenSelector ? $(hiddenSelector) : null;
                    const updateHidden = (num) => {
                        if (!$hidden || !$hidden.length) return;
                        $hidden.val(num);
                        $hidden.trigger('input');
                    };

                    // initial mask
                    $input.maskMoney({
                        prefix: '{{ settings()->currency->symbol }}',
                        thousands: '{{ settings()->currency->thousand_separator }}',
                        decimal: '{{ settings()->currency->decimal_separator }}',
                        precision: 2,
                        allowZero: true,
                        allowNegative: false
                    });
                    $input.maskMoney('mask');

                    $input.on('focus', function () {
                        $input.maskMoney('destroy');
                        $input.val($input.val().replace(/[^0-9.-]/g, ''));
                        setTimeout(() => this.select(), 0);
                    });

                    $input.on('blur', function () {
                        let v = parseFloat($input.val().replace(/[^0-9.-]/g, ''));
                        if (isNaN(v)) v = 0;
                        updateHidden(v);
                        $input.val(v.toFixed(2));
                        $input.maskMoney({
                            prefix: '{{ settings()->currency->symbol }}',
                            thousands: '{{ settings()->currency->thousand_separator }}',
                            decimal: '{{ settings()->currency->decimal_separator }}',
                            precision: 2,
                            allowZero: true,
                            allowNegative: false
                        });
                        $input.maskMoney('mask');
                    });

                    $input.on('keyup change', function () {
                        let v = $input.maskMoney('unmasked')[0] ?? 0;
                        if (isNaN(v)) v = 0;
                        updateHidden(v);
                    });
                });
            }

            bindConversionPriceInputs();
            if (window.Livewire) {
                document.addEventListener('livewire:load', function () {
                    Livewire.hook('message.processed', bindConversionPriceInputs);
                });
            }

            // Observer for dynamically added conversion rows from Livewire
            const conversionTableObserver = new MutationObserver(() => {
                bindConversionPriceInputs();
            });
            const conversionTable = document.querySelector('.unit-conversion-table');
            if (conversionTable) {
                conversionTableObserver.observe(conversionTable, {
                    childList: true,
                    subtree: true,
                    attributes: false,
                });
            }

            // === Submit: unmask to raw numbers ===
            $('#product-form').on('submit', function (event) {
                if (this.dataset.submitting === 'true') {
                    event.preventDefault();
                    return;
                }

                this.dataset.submitting = 'true';
                toggleFormSubmissionLock(this, true);

                const un = (sel) => $(sel).maskMoney('unmasked')[0] ?? 0;
                $('#purchase_price').val(un('#purchase_price'));
                $('#sale_price').val(un('#sale_price'));
                $('#tier_1_price').val(un('#tier_1_price'));
                $('#tier_2_price').val(un('#tier_2_price'));

                $('.conversion-price-input').each(function () {
                    const $input = $(this);
                    const hiddenSelector = $input.data('hidden');
                    const $hidden = hiddenSelector ? $(hiddenSelector) : null;
                    const v = $input.maskMoney('unmasked')[0] ?? 0;
                    $input.val(v);
                    if ($hidden && $hidden.length) {
                        $hidden.val(v);
                        $hidden.trigger('input');
                    }
                });
            });

            window.addEventListener('product:submit-error', () => {
                const form = document.getElementById('product-form');
                if (!form) return;

                form.dataset.submitting = 'false';
                toggleFormSubmissionLock(form, false);
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
                    if (name.startsWith('conversions') || name.startsWith('barcode') || name.startsWith('product_stock_alert') || name === 'base_unit_id') {
                        $(this).val('');
                    }
                });

                // Checkboxes & radios
                $section.find('input[type="checkbox"], input[type="radio"]').prop('checked', false);

                // Selects → set to placeholder (Option A uses empty value "")
                $section.find('select').val('').trigger('change');

                // Textareas
                $section.find('textarea').val('');

                // Tell Alpine unit dropdown to clear itself
                window.dispatchEvent(new CustomEvent('unit-cleared'));
                // Reset Livewire-based base unit label to placeholder
                const $baseUnitDropdown = $section.find('[data-unit-dropdown="base_unit_id"]');
                if ($baseUnitDropdown.length) {
                    const $label = $baseUnitDropdown.find('[data-role="unit-dropdown-label"]');
                    const placeholder = $label.data('placeholder') || 'Pilih unit...';
                    $label.addClass('text-muted').text(placeholder);
                }

                // If your Livewire component renders inputs for conversions, clear them too
                // (this already catches them because they’re inputs/selects inside the section).
                // Optional: if you have a Livewire listener, emit a reset event:
                if (window.Livewire && typeof Livewire.dispatch === 'function') {
                    // Listen in component with: protected $listeners = ['unitConversion:reset' => 'resetRows'];
                    Livewire.dispatch('unitConversion:reset');
                }
            }
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
            headers: { "X-CSRF-TOKEN": "{{ csrf_token() }}" },

            success: function (file, response) {
                $('form').append('<input type="hidden" name="document[]" value="' + response.name + '">');
                // map the UI name to server name so removedFile can find it later
                window.uploadedDocumentMap = window.uploadedDocumentMap || {};
                uploadedDocumentMap[file.name] = response.name;
            },

            removedfile: function (file) {
                file.previewElement?.remove();
                let name = '';
                if (typeof file.file_name !== 'undefined') {
                    name = file.file_name; // mock or preloaded file
                } else {
                    name = (window.uploadedDocumentMap || {})[file.name]; // just-uploaded file
                }

                if (name) {
                    // Call temp delete for temp files (safe; no-op if already gone)
                    $.post("{{ route('dropzone.delete') }}", {
                        _token: "{{ csrf_token() }}",
                        file_name: name
                    });
                    // Remove the hidden input so it won't attach on submit
                    $('form').find('input[name="document[]"][value="' + name + '"]').remove();
                    if (window.uploadedDocumentMap) delete uploadedDocumentMap[file.name];
                }
            },

            init: function () {
                // === 1) Re-hydrate temp uploads from old('document') after validation errors ===
                const oldDocs = @json(old('document', []));
                if (Array.isArray(oldDocs) && oldDocs.length) {
                    oldDocs.forEach((name) => {
                        const mock = {
                            name,              // display name in the DZ list
                            size: 12345,       // dummy size
                            accepted: true,
                            file_name: name,   // IMPORTANT: lets removedFile find the hidden input value
                            _isTemp: true
                        };
                        this.emit('addedfile', mock);
                        this.emit('thumbnail', mock, "{{ route('dropzone.temp', ':name') }}".replace(':name', encodeURIComponent(name)));
                        this.emit('complete', mock);
                    });

                    // Keep maxFiles honest so users can still add more (e.g., 3 - oldDocs.length)
                    if (typeof this.options.maxFiles === 'number') {
                        this.options.maxFiles = Math.max(0, this.options.maxFiles - oldDocs.length);
                    }
                }

                // === 2) (optional) If you also preload existing media on EDIT pages, keep that here. ===
                // We'll refine this in Step 3 so removing existing media doesn't hit the temp delete endpoint.
            }
        };
    </script>

    <!-- Modals -->
    {{-- Brand and tax quick-add handled via Livewire components --}}
    @include('components.alpine.unit-quick-add-modal')
@endsection

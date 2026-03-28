@extends('layouts.app')

@section('title', 'Ubah Produk')

@section('content')
    <div class="container-fluid">
        <form id="product-form" action="{{ route('products.update', $product->id) }}" method="POST"
              enctype="multipart/form-data">
            @csrf
            @method('PUT')
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
                        <a href="{{ route('products.index') }}" class="btn btn-secondary mr-2">
                            Kembali
                        </a>
                        @can('products.edit')
                            <x-button label="Perbaharui Produk" icon="bi-check"/>
                        @endcan
                    </div>
                </div>

                <!-- Product Details Section -->
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <!-- Product Name and Code -->
                            <div class="form-row">
                                <div class="col-md-6">
                                    <x-input label="Nama Produk" name="product_name"
                                             value="{{ old('product_name', $product->product_name) }}" required/>
                                </div>
                                <div class="col-md-6">
                                    <x-input label="Kode Produk" name="product_code"
                                             value="{{ old('product_code', $product->product_code) }}"/>
                                    <small class="form-text text-muted">Biarkan kosong untuk mempertahankan kode yang ada</small>
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
                                        :selected="old('category_id', $product->category_id)"
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
                                        :selected="old('brand_id', $product->brand_id)"
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
                                        :isActive="(bool) old('is_purchased', $product->is_purchased)"
                                        :price="old('purchase_price', $price->purchase_price ?? '')"
                                        :taxId="old('purchase_tax_id', $price->purchase_tax_id)"
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
                            <div class="form-row">
                                <div class="col-md-12">
                                    <livewire:modules.product.sale-price-setup
                                        :isActive="(bool) old('is_sold', $product->is_sold)"
                                        :price="old('sale_price', $price->sale_price ?? '')"
                                        :tier1Price="old('tier_1_price', $price->tier_1_price ?? '')"
                                        :tier2Price="old('tier_2_price', $price->tier_2_price ?? '')"
                                        :taxId="old('sale_tax_id', $price->sale_tax_id)"
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
                                :locked="$hasStock"
                                :unit-options="$unitOptions"
                                :initial-stock-managed="(bool) old('stock_managed', $product->stock_managed)"
                                :initial-serial-required="(bool) old('serial_number_required', $product->serial_number_required)"
                                :initial-base-unit-id="old('base_unit_id', $product->base_unit_id) ? (int) old('base_unit_id', $product->base_unit_id) : null"
                                :initial-barcode="(string) old('barcode', $product->barcode ?? '')"
                                :initial-product-quantity="(int) old('product_quantity', $product->product_quantity)"
                                :initial-stock-alert="old('product_stock_alert', $product->product_stock_alert)"
                                :initial-conversions="old('conversions', $conversionFormData)"
                                :errors="$errors->toArray()"
                            />

                            <div class="form-row">
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
            const PRODUCT_NOMINAL_CONFIG = {
                prefix: 'RP ',
                thousands: '.',
                decimal: ',',
                precision: 2,
            };

            function parseNominalNumber(value) {
                if (value === null || value === undefined) return 0;

                let text = String(value).trim();
                if (!text) return 0;

                text = text.replace(/^RP\s*/i, '');
                text = text.replace(/\s+/g, '');
                text = text.replace(/[^0-9,.-]/g, '');
                if (!text || text === '-' || text === ',' || text === '.') return 0;

                const lastComma = text.lastIndexOf(',');
                const lastDot = text.lastIndexOf('.');
                let decimalSeparator = null;

                if (lastComma !== -1 && lastDot !== -1) {
                    decimalSeparator = lastComma > lastDot ? ',' : '.';
                } else if (lastComma !== -1) {
                    decimalSeparator = ',';
                } else if (lastDot !== -1) {
                    const dotMatches = text.match(/\./g);
                    const dotCount = dotMatches ? dotMatches.length : 0;
                    const fractionalDigits = text.slice(lastDot + 1).replace(/\D/g, '').length;

                    if (dotCount === 1 && fractionalDigits > 0 && fractionalDigits <= 2) {
                        decimalSeparator = '.';
                    }
                }

                let normalized = text;
                if (decimalSeparator === ',') {
                    normalized = normalized.replace(/\./g, '');
                    normalized = normalized.replace(',', '.');
                } else if (decimalSeparator === '.') {
                    normalized = normalized.replace(/,/g, '');
                } else {
                    normalized = normalized.replace(/[.,]/g, '');
                }

                const parsed = Number.parseFloat(normalized);
                if (!Number.isFinite(parsed) || parsed < 0) return 0;
                return parsed;
            }

            function toRawNominal(value) {
                const numeric = Number.isFinite(value) && value >= 0 ? value : 0;
                const rounded = Math.round(numeric * 100) / 100;
                if (Number.isInteger(rounded)) return String(rounded);
                return rounded.toFixed(2).replace(/\.?0+$/, '');
            }

            // --- Money mask helpers (conversion prices only - main prices use x-nominal-field component) ---
            // Main prices are now handled by the x-nominal-field component which manages its own
            // maskMoney binding. The maskNow() early initialization has been removed to prevent
            // conflicts with the component's focus/blur lifecycle.
            // Only conversion prices need jQuery maskMoney initialization here.
            function applyMask() {
                // Main prices (purchase_price, sale_price, tier_1_price, tier_2_price) now handled by x-nominal-field
                // Only apply maskMoney to conversion prices and disabled field mirrors
                $('.conversion-price-input').maskMoney({
                    prefix: PRODUCT_NOMINAL_CONFIG.prefix,
                    thousands: PRODUCT_NOMINAL_CONFIG.thousands,
                    decimal: PRODUCT_NOMINAL_CONFIG.decimal,
                    precision: PRODUCT_NOMINAL_CONFIG.precision,
                    allowZero: true,
                    allowNegative: false,
                });
            }
            function setMaskedZero($el){ $el.maskMoney('destroy'); $el.val('0'); applyMask(); $el.maskMoney('mask'); }

            // --- Numeric extraction (no formatting) ---
            function unmaskNumber($el){
                if(!$el.length) return 0;
                return parseNominalNumber($el.val());
            }

            // --- Mirror helpers (ensure disabled fields still submit) ---
            const MIRROR_TARGETS = [
                { sel: '[name="base_unit_id"]',     val: $el => $el.val() },
                { sel: '#serial_number_required',   val: $el => ($el.is(':checked') ? '1' : '0') },
                { sel: '[name="barcode"]',          val: $el => $el.val() },
                { sel: '[name="product_quantity"]', val: $el => $el.val() },
            ];
            function cssEscape(s){ return (s+'').replace(/(["'\\])/g,'\\$1'); }
            function mirrorSel(name){ return 'input[type="hidden"][data-mirror-of="'+cssEscape(name)+'"]'; }
            function ensureMirror(name, value){
                let $m = $(mirrorSel(name));
                if(!$m.length){ $m = $('<input type="hidden">').attr('name', name).attr('data-mirror-of', name).appendTo('#product-form'); }
                $m.val(value);
            }
            function removeMirror(name){ $(mirrorSel(name)).remove(); }
            function refreshMirrorsForDisabledTargets(){
                MIRROR_TARGETS.forEach(t=>{
                    const $el = $(t.sel);
                    if(!$el.length) return;
                    const name = $el.attr('name') || t.sel.replace('#','');
                    if($el.is(':disabled')) ensureMirror(name, t.val($el));
                    else removeMirror(name);
                });
            }
            function setDisabledWithMirror($el, disabled){
                if(!$el.length) return;
                const name = $el.attr('name');
                if(!name) return;
                $el.prop('disabled', disabled);
                if(disabled) ensureMirror(name, ($el.is(':checkbox') ? ($el.is(':checked')?'1':'0') : $el.val()));
                else removeMirror(name);
            }

            // Init masks for conversion prices only (main prices now use x-nominal-field component)
            // Note: maskNow() removed to prevent focus/blur conflicts with x-nominal-field component
            applyMask();

            // Always lock Stok on edit
            const $qtyInput = $('input[name="product_quantity"]');
            $qtyInput.prop('disabled', true).attr('readonly', true).attr('tabindex','-1');

            // Qty-based locks (now using hasStock from PHP)
            const lockByQty = {{ $hasStock ? 'true' : 'false' }};

            function applyQtyLocks() {
                const $base   = $('[name="base_unit_id"]');
                const $serial = $('#serial_number_required');
                const $barcode= $('[name="barcode"]');
                if (lockByQty) {
                    setDisabledWithMirror($base, true);
                    setDisabledWithMirror($serial, true);
                    setDisabledWithMirror($barcode, true);
                } else {
                    setDisabledWithMirror($base,   $base.is(':disabled'));
                    setDisabledWithMirror($serial, $serial.is(':disabled'));
                    setDisabledWithMirror($barcode,$barcode.is(':disabled'));
                }
                refreshMirrorsForDisabledTargets();
            }
            applyQtyLocks();

            // Keep mirrors tidy when editing enabled inputs
            $(document).on('change input', MIRROR_TARGETS.map(t=>t.sel).join(','), function(){
                const $el = $(this);
                if($el.is(':disabled')) return;
                const name = $el.attr('name') || '';
                removeMirror(name);
            });

            // Focus/blur handlers for main price fields removed
            // (now handled by x-nominal-field component)

            // Conversion price fields
            function bindConversionPriceInputs() {
                $('.conversion-price-input').each(function () {
                    const $input = $(this);
                    if ($input.data('bound') === 1) return;
                    $input.data('bound', 1);

                    const hiddenSelector = $input.data('hidden');
                    const $hidden = hiddenSelector ? $(hiddenSelector) : null;
                    const updateHidden = (num) => {
                        if (!$hidden || !$hidden.length) return;
                        $hidden.val(toRawNominal(num));
                        $hidden.trigger('input');
                    };
                    const maskOptions = {
                        prefix: PRODUCT_NOMINAL_CONFIG.prefix,
                        thousands: PRODUCT_NOMINAL_CONFIG.thousands,
                        decimal: PRODUCT_NOMINAL_CONFIG.decimal,
                        precision: PRODUCT_NOMINAL_CONFIG.precision,
                        allowZero: true,
                        allowNegative: false
                    };

                    const bindFocusRawHandler = function () {
                        $input.off('focus.conversionRaw').on('focus.conversionRaw', function (event) {
                            try {
                                $input.maskMoney('destroy');
                            } catch (e) {
                                // no-op if already destroyed
                            }
                            $input.val(toRawNominal(parseNominalNumber($input.val())));
                            setTimeout(() => this.select(), 0);

                            if (event && typeof event.stopImmediatePropagation === 'function') {
                                event.stopImmediatePropagation();
                            }
                        });
                    };

                    const applyMaskedState = function (numericValue) {
                        const v = Number.isFinite(numericValue) ? numericValue : 0;
                        $input.maskMoney(maskOptions);
                        $input.val(v);
                        $input.maskMoney('mask');

                        // Rebind focus after maskMoney init so raw-focus handler wins every cycle.
                        bindFocusRawHandler();
                    };

                    const initialValue = parseNominalNumber($input.val());
                    updateHidden(initialValue);
                    applyMaskedState(initialValue);

                    $input.off('blur.conversionRaw').on('blur.conversionRaw', function () {
                        const v = parseNominalNumber($input.val());
                        updateHidden(v);
                        applyMaskedState(v);
                    });

                    $input.off('keyup.conversionRaw change.conversionRaw')
                        .on('keyup.conversionRaw change.conversionRaw', function () {
                            const v = parseNominalNumber($input.val());
                            updateHidden(v);
                        });
                });
            }
            let bindQueued = false;
            function queueBindConversionPriceInputs() {
                if (bindQueued) return;
                bindQueued = true;
                requestAnimationFrame(function () {
                    bindQueued = false;
                    bindConversionPriceInputs();
                });
            }

            bindConversionPriceInputs();
            if (window.Livewire) {
                document.addEventListener('livewire:load', queueBindConversionPriceInputs);
                document.addEventListener('livewire:initialized', queueBindConversionPriceInputs);
                document.addEventListener('livewire:navigated', queueBindConversionPriceInputs);
                if (typeof Livewire.hook === 'function') {
                    try {
                        Livewire.hook('message.processed', queueBindConversionPriceInputs);
                    } catch (e) {
                        // Livewire v3 may not expose this hook name; events/observer still cover rebinds.
                    }
                }
            }

            const conversionTableObserver = new MutationObserver(queueBindConversionPriceInputs);
            conversionTableObserver.observe(document.body, {
                childList: true,
                subtree: true,
            });

            // --- FINAL: before submit, sync conversion prices and ensure mirrors for disabled fields ---
            $('#product-form').on('submit', function () {
                // Note: Main price fields (purchase_price, sale_price, tier_1_price, tier_2_price)
                // are now handled by x-nominal-field component which manages their own hidden inputs.
                // No unmasking needed for them.

                // Handle conversion price inputs
                $('.conversion-price-input').each(function(){
                    const $input = $(this);
                    const hiddenSelector = $input.data('hidden');
                    const $hidden = hiddenSelector ? $(hiddenSelector) : null;
                    const n = unmaskNumber($input);
                    try { $input.maskMoney('destroy'); } catch(e){}
                    $input.val(toRawNominal(n));
                    if ($hidden && $hidden.length) {
                        $hidden.val(toRawNominal(n));
                        $hidden.trigger('input');
                    }
                });
                // Ensure mirrors exist for any disabled targets
                refreshMirrorsForDisabledTargets();
            });
        });
    </script>

    <script src="{{ asset('js/dropzone.js') }}"></script>
    <script>
        (function () {
            if (!window.Dropzone) return;
            Dropzone.autoDiscover = false;

            const EXISTING_MEDIA = @json($existingMedia ?? []);
            const OLDS = @json(old('document', []));

            // Prevent double-init
            if (window.__dzDocument) { try { window.__dzDocument.destroy(); } catch (e) {} }

            // Build a Set of existing file_names to avoid double-preloading
            const existingNames = new Set((EXISTING_MEDIA || []).map(m => m.name));
            // Filter OLDS to only true temp files (not names of existing media)
            const oldsOnlyTemps = (OLDS || []).filter(n => !existingNames.has(n));

            const config = {
                url: '{{ route('dropzone.upload') }}',
                paramName: 'file',
                maxFilesize: 1,
                acceptedFiles: '.jpg,.jpeg,.png',
                maxFiles: 3,
                addRemoveLinks: true,
                dictRemoveFile: "<i class='bi bi-x-circle text-danger'></i> remove",
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },

                init: function () {
                    const dz = this;

                    // 1) Preload existing persisted media (use real URLs, not temp)
                    EXISTING_MEDIA.forEach(function (m) {
                        const mock = { name: m.name, size: m.size || 12345, accepted: true, file_name: m.name, media_id: m.id, _isExisting: true };
                        dz.emit('addedfile', mock);
                        dz.emit('thumbnail', mock, m.url);
                        dz.emit('complete', mock);
                        ensureHidden(m.name);
                    });

                    // 2) Rehydrate temp uploads from old('document') (temps only)
                    oldsOnlyTemps.forEach(function (name) {
                        const mock = { name, size: 12345, accepted: true, file_name: name, _isTemp: true };
                        dz.emit('addedfile', mock);
                        dz.emit('thumbnail', mock, "{{ route('dropzone.temp', ':name') }}".replace(':name', encodeURIComponent(name)));
                        dz.emit('complete', mock);
                        ensureHidden(name);
                    });

                    // 3) Keep maxFiles honest
                    const already = (EXISTING_MEDIA.length + oldsOnlyTemps.length);
                    dz.options.maxFiles = Math.max(0, dz.options.maxFiles - already);

                    // 4) Save handle
                    window.__dzDocument = dz;
                },

                success: function (file, response) {
                    file._serverName = response.name; // temp server filename
                    ensureHidden(response.name);
                },

                error: function (file) {
                    if (file._serverName) removeHidden(file._serverName);
                },

                removedfile: function (file) {
                    if (file.previewElement) file.previewElement.parentNode?.removeChild(file.previewElement);

                    // Existing media? -> DELETE media route
                    if (file.media_id) {
                        const url = "{{ route('products.media.destroy', [$product->id, '__MEDIA_ID__']) }}".replace('__MEDIA_ID__', file.media_id);
                        $.ajax({ url: url, type: 'DELETE', data: { _token: "{{ csrf_token() }}" } });
                        removeHidden(file.file_name);
                        return;
                    }

                    // Temp upload (new or from old())
                    const name = file._serverName || file.file_name || file.name;
                    if (name) {
                        $.post("{{ route('dropzone.delete') }}", { _token: "{{ csrf_token() }}", file_name: name });
                        removeHidden(name);
                    }
                },

                maxfilesexceeded: function (file) {
                    this.removeFile(file);
                    alert('Maksimal 3 gambar.');
                }
            };

            function cssEscape(s){ return (s+'').replace(/(["'\\])/g,'\\$1'); }
            function ensureHidden(val) {
                const sel = 'input[name="document[]"][value="'+cssEscape(val)+'"]';
                if (document.querySelector(sel)) return;
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'document[]';
                input.value = val;
                document.getElementById('product-form').appendChild(input);
            }
            function removeHidden(val) {
                document.querySelectorAll('input[name="document[]"]').forEach(function (el) {
                    if (el.value === val) el.remove();
                });
            }

            new Dropzone('#document-dropzone', config);
        })();
    </script>

    <!-- Modals -->
    {{-- Brand and tax quick-add handled via Livewire components --}}
    @include('components.alpine.unit-quick-add-modal')
@endsection

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
            // --- Money mask helpers ---
            function applyMask() {
                $('#purchase_price, #sale_price, #tier_1_price, #tier_2_price, .conversion-price-input').maskMoney({
                    prefix: '{{ settings()->currency->symbol }}',
                    thousands: '{{ settings()->currency->thousand_separator }}',
                    decimal: '{{ settings()->currency->decimal_separator }}',
                    precision: 2,
                    allowZero: true,
                    allowNegative: false,
                });
            }
            function maskNow($el){
                const raw = String($el.val() ?? '').replace(/[^0-9.-]/g,'');
                if(raw==='') return;
                const n = parseFloat(raw);
                if(!isNaN(n)){ $el.val(n.toFixed(2)); $el.maskMoney('mask'); }
            }
            function setMaskedZero($el){ $el.maskMoney('destroy'); $el.val('0.00'); applyMask(); $el.maskMoney('mask'); }

            // --- Numeric extraction (no formatting) ---
            function unmaskNumber($el){
                if(!$el.length) return 0;
                try {
                    const arr = $el.maskMoney('unmasked');
                    if (arr && arr.length) return +arr[0];
                } catch(e){}
                const s = String($el.val() ?? '').replace(/[^0-9.-]/g,'');
                const n = parseFloat(s);
                return isNaN(n) ? 0 : n;
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

            // Init masks
            applyMask();
            $('#purchase_price, #sale_price, #tier_1_price, #tier_2_price').each(function(){ maskNow($(this)); });

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

            // Focus/blur handlers for money fields (visual only)
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
                        $hidden.val(num);
                        $hidden.trigger('input');
                    };

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

            // --- FINAL: before submit, force all money inputs to raw numbers and mirror disabled ones ---
            $('#product-form').on('submit', function () {
                const un = (sel) => $(sel).maskMoney('unmasked')[0] ?? 0;
                $('#purchase_price').val(un('#purchase_price'));
                $('#sale_price').val(un('#sale_price'));
                $('#tier_1_price').val(un('#tier_1_price'));
                $('#tier_2_price').val(un('#tier_2_price'));

                // Handle conversion price inputs
                $('.conversion-price-input').each(function(){
                    const $input = $(this);
                    const hiddenSelector = $input.data('hidden');
                    const $hidden = hiddenSelector ? $(hiddenSelector) : null;
                    const n = unmaskNumber($input);
                    try { $input.maskMoney('destroy'); } catch(e){}
                    $input.val(n.toFixed(2));
                    if ($hidden && $hidden.length) {
                        $hidden.val(n);
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

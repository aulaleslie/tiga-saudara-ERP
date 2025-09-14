@extends('layouts.app')

@section('title', 'Ubah Produk')

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
                                             value="{{ old('product_code', $product->product_code) }}" required/>
                                </div>
                            </div>

                            <!-- Kategori and Merek -->
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
                                <div class="col-md-12">
                                    <div class="border p-3 mb-3">
                                        <div class="form-group">
                                            <input type="checkbox" name="is_purchased" id="is_purchased" value="1"
                                                {{ old('is_purchased', $product->is_purchased) ? 'checked' : '' }}>
                                            <label for="is_purchased"><strong>Saya Beli Barang Ini</strong></label>

                                            <div class="row mt-3">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Beli" name="purchase_price" step="0.01"
                                                             :disabled="!old('is_purchased', $product->is_purchased)"
                                                             value="{{ old('purchase_price', $price->purchase_price ?? '') }}"/>
                                                </div>
                                                <div class="col-md-6">
                                                    <x-select label="Pajak Beli" name="purchase_tax_id"
                                                              :options="$taxes->pluck('name','id')"
                                                              :disabled="!old('is_purchased', $product->is_purchased)"
                                                              selected="{{ old('purchase_tax_id', $price->purchase_tax_id) }}"/>
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
                                                {{ old('is_sold', $product->is_sold) ? 'checked' : '' }}>
                                            <label for="is_sold"><strong>Saya Jual Barang Ini</strong></label>

                                            <div class="row mt-3">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Jual" name="sale_price" step="0.01"
                                                             :disabled="!old('is_sold', $product->is_sold)"
                                                             value="{{ old('sale_price', $price->sale_price ?? '') }}"/>
                                                </div>
                                                <div class="col-md-6">
                                                    <x-select label="Pajak Jual" name="sale_tax_id"
                                                              :options="$taxes->pluck('name','id')"
                                                              :disabled="!old('is_sold', $product->is_sold)"
                                                              selected="{{ old('sale_tax_id', $price->sale_tax_id) }}"/>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Jual Partai Besar" name="tier_1_price" step="0.01"
                                                             :disabled="!old('is_sold', $product->is_sold)"
                                                             value="{{ old('tier_1_price', $price->tier_1_price ?? '') }}"/>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <x-input label="Harga Jual Reseller" name="tier_2_price" step="0.01"
                                                             :disabled="!old('is_sold', $product->is_sold)"
                                                             value="{{ old('tier_2_price', $price->tier_2_price ?? '') }}"/>
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
                                            @if($product->product_quantity > 0)
                                                <!-- If quantity > 0, checkbox is disabled so output current value -->
                                                <input type="hidden" name="stock_managed" value="{{ $product->stock_managed }}" />
                                            @else
                                                <!-- Otherwise, use 0 as the default hidden value -->
                                                <input type="hidden" name="stock_managed" value="0" />
                                            @endif
                                            <input type="checkbox" name="stock_managed" id="stock_managed" value="1"
                                                   class="input-icheck"
                                                {{ old('stock_managed', $product->stock_managed) ? 'checked' : '' }}
                                                {{ $product->product_quantity > 0 ? 'disabled' : '' }} />
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
                                            @if($product->product_quantity > 0)
                                                <!-- If quantity > 0, checkbox is disabled so output current value -->
                                                <input type="hidden" name="serial_number_required" value="{{ $product->serial_number_required }}" />
                                            @else
                                                <!-- Otherwise, use 0 as the default hidden value -->
                                                <input type="hidden" name="serial_number_required" value="0" />
                                            @endif
                                            <input type="checkbox" name="serial_number_required" id="serial_number_required" value="1"
                                                {{ old('serial_number_required', $product->serial_number_required) ? 'checked' : '' }}
                                                {{ $product->product_quantity > 0 ? 'disabled' : '' }}>
                                            <label for="serial_number_required"><strong>Serial Number Diperlukan</strong></label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Product Quantity and Stock Alert -->
                                <div class="form-row">
                                    <div class="col-md-6">
                                        <x-input label="Stok" name="product_quantity" type="number" step="1"
                                                 value="{{ old('product_quantity', $product->product_quantity) }}"
                                                 disabled/>
                                    </div>
                                    <div class="col-md-6">
                                        <x-input label="Peringatan Jumlah Stok" name="product_stock_alert" type="number"
                                                 step="1"
                                                 value="{{ old('product_stock_alert', $product->product_stock_alert) }}"/>
                                    </div>
                                </div>

                                <!-- Unit and Barcode -->
                                <div class="form-row">
                                    <div class="col-md-6">
                                        <x-select
                                            label="Unit Utama"
                                            name="base_unit_id"
                                            :options="$units->pluck('name', 'id')"
                                            selected="{{ old('base_unit_id', $product->base_unit_id) }}"
                                            :disabled="$product->stock_managed"
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <x-input
                                            label="Barcode Unit Utama"
                                            name="barcode"
                                            value="{{ old('barcode', $product->barcode) }}"
                                        />
                                    </div>
                                </div>

                                <!-- Livewire component for Unit Conversion Table -->
                                <div class="form-row">
                                    <div class="col-lg-12">
                                        <div class="card">
                                            <div class="card-body">
                                                <livewire:product.unit-conversion-table
                                                    :conversions="old('conversions', $product->conversions->toArray())"
                                                    :errors="$errors->toArray()"/>
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
    </div>
@endsection

@section('third_party_scripts')
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script>
        $(function () {
            // --- Money mask helpers ---
            function applyMask() {
                $('#purchase_price, #sale_price, #tier_1_price, #tier_2_price').maskMoney({
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

                // price fields → mirror raw numbers when disabled
                { sel: '#purchase_price',           val: $el => unmaskNumber($el).toFixed(2) },
                { sel: '#sale_price',               val: $el => unmaskNumber($el).toFixed(2) },
                { sel: '#tier_1_price',             val: $el => unmaskNumber($el).toFixed(2) },
                { sel: '#tier_2_price',             val: $el => unmaskNumber($el).toFixed(2) },
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

            // Qty-based locks
            const qtyVal = parseFloat($qtyInput.val() || '0') || 0;
            const lockByQty = qtyVal > 0;

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

            // Toggles
            function setMaskedIfEmpty($el){ if(!$el.val().trim()) setMaskedZero($el); }
            function togglePurchaseFields(initial=false){
                const checked = $('#is_purchased').is(':checked');
                const $price = $('#purchase_price');
                const $tax   = $('#purchase_tax_id');
                $price.prop('disabled', !checked);
                $tax.prop('disabled',   !checked);
                if (!checked) { setMaskedZero($price); $tax.val('').trigger('change'); }
                else if (initial) { setMaskedIfEmpty($price); }
                refreshMirrorsForDisabledTargets();
            }
            function toggleSaleFields(initial=false){
                const checked = $('#is_sold').is(':checked');
                const $sale  = $('#sale_price');
                const $tier1 = $('#tier_1_price');
                const $tier2 = $('#tier_2_price');
                const $tax   = $('#sale_tax_id');
                [$sale,$tier1,$tier2].forEach($i => $i.prop('disabled', !checked));
                $tax.prop('disabled', !checked);
                if (!checked) { setMaskedZero($sale); setMaskedZero($tier1); setMaskedZero($tier2); $tax.val('').trigger('change'); }
                else if (initial) { setMaskedIfEmpty($sale); setMaskedIfEmpty($tier1); setMaskedIfEmpty($tier2); }
                refreshMirrorsForDisabledTargets();
            }
            $('#is_purchased').on('change', () => togglePurchaseFields(false));
            $('#is_sold').on('change',      () => toggleSaleFields(false));
            togglePurchaseFields(true);
            toggleSaleFields(true);

            // Stock managed toggle
            function toggleStockManagedFields() {
                const on = $('#stock_managed').is(':checked');
                const $section = $('#stock-dependent');

                if (!on) {
                    const protect = lockByQty
                        ? '[name="product_quantity"],[name="base_unit_id"],#serial_number_required,[name="barcode"]'
                        : '[name="product_quantity"]';
                    $section.find('input[type="text"], input[type="number"], input[type="tel"], input[type="email"], input[type="search"], input[type="url"]')
                        .not(protect).val('');
                    $section.find('input[type="checkbox"], input[type="radio"]').not(protect).prop('checked', false);
                    $section.find('select').not(protect).val('').trigger('change');
                    $section.find('textarea').not(protect).val('');
                    if (window.Livewire && typeof Livewire.dispatch === 'function') {
                        Livewire.dispatch('unitConversion:reset');
                    }
                }

                if (window.Livewire && typeof Livewire.dispatch === 'function') {
                    Livewire.dispatch('stock:lock', { locked: !on });
                }

                const protect = lockByQty
                    ? '[name="product_quantity"],[name="base_unit_id"],#serial_number_required,[name="barcode"]'
                    : '[name="product_quantity"]';
                $section.find('input, select, textarea, button').not(protect).prop('disabled', !on);

                $qtyInput.prop('disabled', true).attr('readonly', true);
                refreshMirrorsForDisabledTargets();

                if (!on) $('#serial_number_required').prop('checked', false);
                $section.find('select').trigger('change');
            }
            $('#stock_managed').on('change keyup', toggleStockManagedFields);
            toggleStockManagedFields();

            // --- FINAL: before submit, force all money inputs to raw numbers and mirror disabled ones ---
            $('#product-form').on('submit', function () {
                // Set visible (enabled) money inputs to numeric strings
                ['#purchase_price','#sale_price','#tier_1_price','#tier_2_price'].forEach(function(sel){
                    const $el = $(sel);
                    if(!$el.length) return;
                    const n = unmaskNumber($el);
                    try { $el.maskMoney('destroy'); } catch(e){}
                    $el.val(n.toFixed(2));
                });
                // Ensure mirrors exist/are numeric for any price fields currently disabled
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
@endsection

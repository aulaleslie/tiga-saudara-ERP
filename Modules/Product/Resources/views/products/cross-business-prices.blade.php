@extends('layouts.app')

@section('title', 'Kelola Harga Multi-Bisnis: ' . $product->product_name)

@section('content')
    <div class="container-fluid">
        @if(session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif
        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif
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

        <form id="cross-business-price-form" action="{{ route('products.cross-business-prices.update', $product->id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group d-flex justify-content-between">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary" id="btn-back">
                            <i class="bi bi-arrow-left"></i> Kembali
                        </a>
                        <div>
                            <button type="button" class="btn btn-warning" id="btn-edit">
                                <i class="bi bi-pencil"></i> Ubah
                            </button>
                            <button type="button" class="btn btn-secondary d-none" id="btn-cancel">
                                Batal
                            </button>
                            <button type="submit" class="btn btn-primary d-none" id="btn-save">
                                <i class="bi bi-check"></i> Simpan
                            </button>
                        </div>
                    </div>
                </div>

                @php
                    $baseUnitName = $product->baseUnit?->name ?? $product->baseUnit?->short_name ?? 'Unit';
                    $formatDecimalDisplay = function($val) {
                        if ($val === null || $val === '') return '';
                        if (!is_numeric($val)) return $val;
                        return number_format((float) $val, 2, ',', '.');
                    };
                    $formatCanonicalDecimal = function($val) {
                        if ($val === null || $val === '') return '';
                        if (!is_numeric($val)) return $val;
                        return number_format((float) $val, 2, '.', '');
                    };
                @endphp

                <!-- Hidden inputs for snapshot evidence -->
                @if(!empty($conversionSnapshot))
                    <input type="hidden" name="conversion_snapshot" value="{{ $conversionSnapshot['data'] ?? '' }}">
                    <input type="hidden" name="conversion_snapshot_signature" value="{{ $conversionSnapshot['signature'] ?? '' }}">
                @endif

                <!-- Section 1: Harga Satuan Dasar -->
                <div class="col-lg-12 mb-4">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 font-weight-bold">Harga Satuan Dasar — {{ $baseUnitName }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Bisnis</th>
                                            <th>Harga Jual (Rp)</th>
                                            <th>Tier 1 (Rp)</th>
                                            <th>Tier 2 (Rp)</th>
                                            <th>Harga Beli (Rp)</th>
                                            <th>Harga Beli Rata-rata (Rp)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($prices as $index => $price)
                                            <tr>
                                                <td>
                                                    {{ $price['business_name'] ?? 'Setting ' . $price['setting_id'] }}
                                                    <input type="hidden" name="prices[{{ $index }}][setting_id]" value="{{ $price['setting_id'] }}">
                                                    <input type="hidden" name="prices[{{ $index }}][version]" value="{{ $price['version'] }}">
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <input type="text" class="form-control editable-price price-mask" name="prices[{{ $index }}][sale_price]" value="{{ $formatDecimalDisplay(old('prices.'.$index.'.sale_price', $price['sale_price'])) }}" data-original="{{ $formatCanonicalDecimal($price['sale_price']) }}" data-column="sale_price" readonly>
                                                        <button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="sale_price" title="Terapkan ke semua bisnis" style="display: none;">
                                                            <i class="bi bi-arrows-expand"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <input type="text" class="form-control editable-price price-mask" name="prices[{{ $index }}][tier_1_price]" value="{{ $formatDecimalDisplay(old('prices.'.$index.'.tier_1_price', $price['tier_1_price'])) }}" data-original="{{ $formatCanonicalDecimal($price['tier_1_price']) }}" data-column="tier_1_price" readonly>
                                                        <button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="tier_1_price" title="Terapkan ke semua bisnis" style="display: none;">
                                                            <i class="bi bi-arrows-expand"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <input type="text" class="form-control editable-price price-mask" name="prices[{{ $index }}][tier_2_price]" value="{{ $formatDecimalDisplay(old('prices.'.$index.'.tier_2_price', $price['tier_2_price'])) }}" data-original="{{ $formatCanonicalDecimal($price['tier_2_price']) }}" data-column="tier_2_price" readonly>
                                                        <button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="tier_2_price" title="Terapkan ke semua bisnis" style="display: none;">
                                                            <i class="bi bi-arrows-expand"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <input type="text" class="form-control editable-price price-mask" name="prices[{{ $index }}][last_purchase_price]" value="{{ $formatDecimalDisplay(old('prices.'.$index.'.last_purchase_price', $price['last_purchase_price'])) }}" data-original="{{ $formatCanonicalDecimal($price['last_purchase_price']) }}" data-column="last_purchase_price" readonly>
                                                        <button type="button" class="btn btn-sm btn-outline-primary btn-apply-all d-none ms-1" data-column="last_purchase_price" title="Terapkan ke semua bisnis" style="display: none;">
                                                            <i class="bi bi-arrows-expand"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control price-mask" value="{{ $formatDecimalDisplay($price['average_purchase_price']) }}" readonly disabled>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Harga Satuan Konversi -->
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 font-weight-bold">Harga Satuan Konversi</h5>
                        </div>
                        <div class="card-body">
                            @if(empty($conversionsData['headers']))
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle"></i> Produk ini belum memiliki konversi unit. Tambahkan konversi melalui menu ubah produk jika diperlukan.
                                </div>
                            @else
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Bisnis</th>
                                                @foreach($conversionsData['headers'] as $header)
                                                    <th>{{ $header['header_title'] }} (Rp)</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @php
                                                $convCellIndex = 0;
                                                $oldConversions = old('conversions');
                                                $oldConvMap = null;
                                                if (is_array($oldConversions)) {
                                                    $oldConvMap = [];
                                                    foreach ($oldConversions as $oldItem) {
                                                        if (is_array($oldItem) && isset($oldItem['setting_id'], $oldItem['conversion_id'])) {
                                                            $key = $oldItem['setting_id'] . '_' . $oldItem['conversion_id'];
                                                            $oldConvMap[$key] = $oldItem['price'] ?? '';
                                                        }
                                                    }
                                                }
                                            @endphp
                                            @foreach($conversionsData['matrix'] as $bIndex => $row)
                                                <tr>
                                                    <td>{{ $row['business_name'] ?? 'Setting ' . $row['setting_id'] }}</td>
                                                    @foreach($row['conversions'] as $cIndex => $cell)
                                                        @php
                                                            $convKey = $row['setting_id'] . '_' . $cell['conversion_id'];
                                                            if ($oldConvMap !== null) {
                                                                $currentPriceVal = array_key_exists($convKey, $oldConvMap)
                                                                    ? $oldConvMap[$convKey]
                                                                    : $cell['canonical_price'];
                                                            } else {
                                                                $currentPriceVal = $cell['canonical_price'];
                                                            }
                                                            $isOriginallyMissing = !$cell['is_existing'];
                                                            $displayVal = $currentPriceVal !== '' ? $formatDecimalDisplay($currentPriceVal) : '';
                                                        @endphp
                                                        <td>
                                                            <input type="hidden" name="conversions[{{ $convCellIndex }}][setting_id]" value="{{ $row['setting_id'] }}">
                                                            <input type="hidden" name="conversions[{{ $convCellIndex }}][conversion_id]" value="{{ $cell['conversion_id'] }}">
                                                            <input type="hidden" name="conversions[{{ $convCellIndex }}][version]" value="{{ $cell['version'] }}">

                                                            <div class="d-flex align-items-center">
                                                                <div class="position-relative flex-grow-1">
                                                                    <input type="text"
                                                                        class="form-control editable-price editable-conversion-price price-mask"
                                                                        name="conversions[{{ $convCellIndex }}][price]"
                                                                        value="{{ $displayVal }}"
                                                                        data-original="{{ $cell['canonical_price'] }}"
                                                                        data-originally-missing="{{ $isOriginallyMissing ? 'true' : 'false' }}"
                                                                        data-conversion-id="{{ $cell['conversion_id'] }}"
                                                                        data-setting-id="{{ $row['setting_id'] }}"
                                                                        placeholder=""
                                                                        readonly>
                                                                    <span class="missing-badge text-muted small position-absolute" style="top: 8px; left: 12px; pointer-events: none; {{ ($displayVal !== '' ? 'display: none;' : '') }}">Belum diatur</span>
                                                                </div>
                                                                <button type="button"
                                                                    class="btn btn-sm btn-outline-primary btn-apply-all-conv d-none ms-1"
                                                                    data-conversion-id="{{ $cell['conversion_id'] }}"
                                                                    title="Terapkan ke semua bisnis untuk konversi ini"
                                                                    style="display: none;">
                                                                    <i class="bi bi-arrows-expand"></i>
                                                                </button>
                                                            </div>
                                                        </td>
                                                        @php $convCellIndex++; @endphp
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@section('third_party_scripts')
    <script>
        $(document).ready(function () {
            const $btnEdit = $('#btn-edit');
            const $btnCancel = $('#btn-cancel');
            const $btnSave = $('#btn-save');
            const $form = $('#cross-business-price-form');
            const $editableInputs = $('.editable-price');

            // Regex patterns for strict decimal validation
            const idPattern = /^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/;
            const canonicalPattern = /^\d+(\.\d{1,2})?$/;

            // Convert string (Indonesian or canonical) to canonical decimal string ("1.234,56" -> "1234.56", "6853" -> "6853.00")
            function parseCanonicalDecimal(val) {
                if (val === null || val === undefined) return '';
                let str = String(val).trim();
                if (str === '') return '';

                if (idPattern.test(str)) {
                    let cleaned = str.replace(/\./g, '').replace(',', '.');
                    let num = parseFloat(cleaned);
                    return isNaN(num) ? str : num.toFixed(2);
                }

                if (canonicalPattern.test(str)) {
                    let num = parseFloat(str);
                    return isNaN(num) ? str : num.toFixed(2);
                }

                return str;
            }

            // Format float or canonical string to Indonesian display format ("1234.56" -> "1.234,56", "6853" -> "6.853,00")
            function formatLocaleDisplay(val) {
                if (val === null || val === undefined) return '';
                let str = String(val).trim();
                if (str === '') return '';

                let canonical = parseCanonicalDecimal(str);
                let num = parseFloat(canonical);
                if (isNaN(num)) return str;

                let parts = num.toFixed(2).split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                return parts.join(',');
            }

            // Convert formatted display value to raw editable canonical value on focus ("1.234,56" -> "1234.56" or "1234")
            function getRawCanonicalValue(val) {
                if (val === null || val === undefined) return '';
                let str = String(val).trim();
                if (str === '') return '';

                let canonical = parseCanonicalDecimal(str);
                let num = parseFloat(canonical);
                if (isNaN(num)) return str;

                // If whole number, present cleanly as raw digits e.g. "6853", else "1111.23"
                if (num % 1 === 0) {
                    return String(Math.round(num));
                }
                return num.toFixed(2);
            }

            // Prevent key, paste, or change events in view mode
            $editableInputs.on('keydown keypress keyup input paste change', function(e) {
                if ($(this).prop('readonly')) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            });

            // On focus in edit mode: reveal canonical raw value for easy typing (e.g. 6853 or 1111.23)
            $editableInputs.on('focus', function() {
                if (!$(this).prop('readonly')) {
                    let currentVal = $(this).val();
                    let rawVal = getRawCanonicalValue(currentVal);
                    $(this).val(rawVal);
                }
            });

            // On blur in edit mode: re-format to Indonesian display format if valid
            $editableInputs.on('blur', function() {
                if (!$(this).prop('readonly')) {
                    let currentVal = $(this).val();
                    let formatted = formatLocaleDisplay(currentVal);
                    $(this).val(formatted);
                    updateInputDirtyState($(this));
                }
            });

            // Dirty state detection for a single input
            function updateInputDirtyState($input) {
                const currentVal = $input.val();
                const originalVal = $input.data('original');
                const isOriginallyMissing = $input.data('originally-missing') === true || $input.data('originally-missing') === 'true';
                
                let isDirty = false;
                if (isOriginallyMissing) {
                    // For originally missing: dirty if currentVal is non-empty
                    isDirty = currentVal.trim() !== '';
                } else {
                    isDirty = parseCanonicalDecimal(currentVal) !== parseCanonicalDecimal(originalVal);
                }

                // Handle missing badge visibility for conversion inputs
                const $badge = $input.siblings('.missing-badge');
                if ($badge.length) {
                    if (currentVal.trim() === '') {
                        $badge.show();
                    } else {
                        $badge.hide();
                    }
                }

                // Base table apply-to-all button
                const $btn = $input.siblings('.btn-apply-all');
                if ($btn.length) {
                    if (isDirty && !$input.prop('readonly')) {
                        $btn.removeClass('d-none').show();
                    } else {
                        $btn.addClass('d-none').hide();
                    }
                }

                // Conversion apply-to-all button
                const $btnConv = $input.parent().siblings('.btn-apply-all-conv');
                if ($btnConv.length) {
                    // Only available if dirty, not readonly, and has a valid numeric value
                    const canonical = parseCanonicalDecimal(currentVal);
                    const isValidNumeric = canonical !== '' && !isNaN(parseFloat(canonical)) && parseFloat(canonical) >= 0;
                    if (isDirty && !$input.prop('readonly') && isValidNumeric) {
                        $btnConv.removeClass('d-none').show();
                    } else {
                        $btnConv.addClass('d-none').hide();
                    }
                }
            }

            function updateAllDirtyStates() {
                $editableInputs.each(function() {
                    updateInputDirtyState($(this));
                });
            }

            // Handle "Ubah" (Edit)
            $btnEdit.on('click', function () {
                $btnEdit.addClass('d-none');
                $btnCancel.removeClass('d-none');
                $btnSave.removeClass('d-none');

                // Enable inputs
                $editableInputs.each(function() {
                    $(this).prop('readonly', false);
                });

                // Update dirty state for all inputs
                updateAllDirtyStates();
            });

            // Handle manual edit inputs when enabled
            $editableInputs.on('input change keyup', function() {
                if (!$(this).prop('readonly')) {
                    updateInputDirtyState($(this));
                }
            });

            // Handle Base Apply-to-all button click
            $(document).on('click', '.btn-apply-all', function(e) {
                e.preventDefault();
                const $sourceBtn = $(this);
                const $sourceInput = $sourceBtn.siblings('.editable-price');
                const column = $sourceBtn.data('column');
                const sourceVal = $sourceInput.val();

                // Target all inputs for the same column in base table
                $editableInputs.filter('[data-column="' + column + '"]').each(function() {
                    const $targetInput = $(this);
                    if (!$targetInput.prop('readonly')) {
                        $targetInput.val(sourceVal);
                        updateInputDirtyState($targetInput);
                    }
                });
            });

            // Handle Conversion Apply-to-all button click (scoped by conversion ID)
            $(document).on('click', '.btn-apply-all-conv', function(e) {
                e.preventDefault();
                const $sourceBtn = $(this);
                const $sourceInput = $sourceBtn.siblings().find('.editable-conversion-price');
                const conversionId = $sourceBtn.data('conversion-id');
                const sourceVal = $sourceInput.val();

                // Target all conversion inputs for the exact same conversion ID
                $editableInputs.filter('.editable-conversion-price[data-conversion-id="' + conversionId + '"]').each(function() {
                    const $targetInput = $(this);
                    if (!$targetInput.prop('readonly')) {
                        $targetInput.val(sourceVal);
                        updateInputDirtyState($targetInput);
                    }
                });
            });

            // Handle "Batal" (Cancel)
            $btnCancel.on('click', function () {
                $btnCancel.addClass('d-none');
                $btnSave.addClass('d-none');
                $btnEdit.removeClass('d-none');

                // Revert values to original localized display and make readonly
                $editableInputs.each(function () {
                    const originalCanonical = $(this).data('original');
                    const isOriginallyMissing = $(this).data('originally-missing') === true || $(this).data('originally-missing') === 'true';

                    if (isOriginallyMissing) {
                        $(this).val('').prop('readonly', true);
                    } else {
                        const formattedDisplay = formatLocaleDisplay(originalCanonical);
                        $(this).val(formattedDisplay).prop('readonly', true);
                    }
                    updateInputDirtyState($(this));
                });
            });

            // Handle "Simpan" (Submit) protection & canonical unmasking
            $form.on('submit', function (e) {
                if ($btnSave.prop('disabled')) {
                    e.preventDefault();
                    return false;
                }

                // Ensure all editable inputs are enabled so they are included in request payload
                $editableInputs.prop('readonly', false).prop('disabled', false);

                // Unmask editable prices to canonical dot decimal format before submit
                $editableInputs.each(function() {
                    let val = $(this).val();
                    let isOriginallyMissing = $(this).data('originally-missing') === true || $(this).data('originally-missing') === 'true';

                    if (isOriginallyMissing && val.trim() === '') {
                        $(this).val('');
                    } else {
                        let canonical = parseCanonicalDecimal(val);
                        $(this).val(canonical);
                    }
                });

                $btnSave.prop('disabled', true).html('<i class="spinner-border spinner-border-sm"></i> Menyimpan...');
                $btnCancel.prop('disabled', true);
            });

            // Initial dirty check in case old input is restored
            updateAllDirtyStates();
        });
    </script>
@endsection

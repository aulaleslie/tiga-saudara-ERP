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

                <div class="col-lg-12">
                    <div class="card">
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
                                                @php
                                                    $formatDecimalDisplay = function($val) {
                                                        if (!is_numeric($val)) return $val;
                                                        return number_format((float) $val, 2, ',', '.');
                                                    };
                                                    $formatCanonicalDecimal = function($val) {
                                                        if (!is_numeric($val)) return $val;
                                                        return number_format((float) $val, 2, '.', '');
                                                    };
                                                @endphp
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
                const isDirty = parseCanonicalDecimal(currentVal) !== parseCanonicalDecimal(originalVal);
                const $btn = $input.siblings('.btn-apply-all');

                if ($btn.length) {
                    if (isDirty && !$input.prop('readonly')) {
                        $btn.removeClass('d-none').show();
                    } else {
                        $btn.addClass('d-none').hide();
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

            // Handle Apply-to-all button click
            $(document).on('click', '.btn-apply-all', function(e) {
                e.preventDefault();
                const $sourceBtn = $(this);
                const $sourceInput = $sourceBtn.siblings('.editable-price');
                const column = $sourceBtn.data('column');
                const sourceVal = $sourceInput.val();

                // Target all inputs for the same column
                $editableInputs.filter('[data-column="' + column + '"]').each(function() {
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
                    const formattedDisplay = formatLocaleDisplay(originalCanonical);
                    $(this).val(formattedDisplay).prop('readonly', true);
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
                    let canonical = parseCanonicalDecimal(val);
                    $(this).val(canonical);
                });

                $btnSave.prop('disabled', true).html('<i class="spinner-border spinner-border-sm"></i> Menyimpan...');
                $btnCancel.prop('disabled', true);
            });

            // Initial dirty check in case old input is restored
            updateAllDirtyStates();
        });
    </script>
@endsection

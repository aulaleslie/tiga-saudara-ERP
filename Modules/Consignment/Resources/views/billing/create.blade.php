@extends('layouts.app')

@section('title', 'Form Konversi Tagihan Supplier - ' . $confirmation->confirmation_number)

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.billing.index') }}">Tagihan Siap Konversi</a></li>
        <li class="breadcrumb-item active">Konversi {{ $confirmation->confirmation_number }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid" id="billingConversionApp"
         data-preview-url="{{ route('consignments.billing.preview', $confirmation->id) }}"
         data-is-pkp="{{ $isPkp ? '1' : '0' }}">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom">
                <h5 class="mb-0">Konversi Tagihan Supplier (Consignment Billing Conversion)</h5>
                <small class="text-muted">Konfirmasi: <strong>{{ $confirmation->confirmation_number }}</strong> | Supplier: <strong>{{ $confirmation->supplier->supplier_name }}</strong></small>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('consignments.billing.convert', $confirmation->id) }}" enctype="multipart/form-data" id="conversionForm">
                    @csrf

                    <div class="row mb-4">
                        <div class="col-md-4 form-group">
                            <label for="supplier_invoice_number">No. Faktur / Invoice Supplier <span class="text-danger">*</span></label>
                            <input type="text" name="supplier_invoice_number" id="supplier_invoice_number" class="form-control @error('supplier_invoice_number') is-invalid @enderror" value="{{ old('supplier_invoice_number') }}" required placeholder="Contoh: INV/2026/08/001">
                            @error('supplier_invoice_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="invoice_date">Tanggal Faktur / Invoice <span class="text-danger">*</span></label>
                            <input type="date" name="invoice_date" id="invoice_date" class="form-control @error('invoice_date') is-invalid @enderror" value="{{ old('invoice_date', date('Y-m-d')) }}" required>
                            @error('invoice_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="due_date">Tanggal Jatuh Tempo</label>
                            <input type="date" name="due_date" id="due_date" class="form-control @error('due_date') is-invalid @enderror" value="{{ old('due_date') }}">
                            @error('due_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="payment_term_id">Syarat Pembayaran (Payment Term)</label>
                            <select name="payment_term_id" id="payment_term_id" class="form-control">
                                <option value="">-- Pilih Syarat Pembayaran --</option>
                                @foreach($paymentTerms as $term)
                                    <option value="{{ $term->id }}" data-longevity="{{ $term->longevity }}" {{ old('payment_term_id') == $term->id ? 'selected' : '' }}>
                                        {{ $term->name }} ({{ $term->longevity }} hari)
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="tax_ref_no">No. Referensi Pajak / No. Faktur Pajak</label>
                            <input type="text" name="tax_ref_no" id="tax_ref_no" class="form-control" value="{{ old('tax_ref_no') }}" placeholder="Contoh: 010.000-26.00000001">
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="attachments">Lampiran Faktur / Invoice (PDF / Image)</label>
                            <input type="file" name="attachments[]" id="attachments" class="form-control-file" multiple>
                        </div>

                        <div class="col-md-12 form-group">
                            <label for="billing_notes">Catatan Tagihan</label>
                            <textarea name="billing_notes" id="billing_notes" class="form-control" rows="2" placeholder="Catatan opsional mengenai penagihan ini...">{{ old('billing_notes') }}</textarea>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-end">
                        <div class="col-md-3 form-group mb-0">
                            <label for="global_discount_type">Jenis Diskon Global</label>
                            <select id="global_discount_type" class="form-control">
                                <option value="fixed">Nominal (Rp)</option>
                                <option value="percentage">Persentase (%)</option>
                            </select>
                        </div>
                        <div class="col-md-3 form-group mb-0">
                            <label for="global_discount_value">Nilai Diskon Global</label>
                            <input type="text" inputmode="decimal" id="global_discount_value" class="form-control nominal-field" data-precision="2" value="0">
                        </div>
                        @if($isPkp)
                            <div class="col-md-3 form-group mb-0 form-check pl-4">
                                <input type="checkbox" class="form-check-input" id="is_tax_included" checked>
                                <label class="form-check-label" for="is_tax_included">Harga Termasuk Pajak</label>
                            </div>
                        @endif
                    </div>

                    <h5 class="mb-3">Rincian Line Item Purchase (Commercial Snapshots)</h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm" id="billingLinesTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Kode Produk</th>
                                    <th>Nama Produk</th>
                                    <th class="text-right">Qty Billed</th>
                                    <th class="text-right">Referensi Harga Unit</th>
                                    <th class="text-right">Referensi Total Baris</th>
                                    <th class="text-right" style="min-width:140px">Harga Unit</th>
                                    <th style="min-width:100px">Jenis Diskon</th>
                                    <th class="text-right" style="min-width:130px">Nilai Diskon</th>
                                    @if($isPkp)
                                        <th style="min-width:150px">Pajak</th>
                                    @endif
                                    <th class="text-right" style="min-width:140px">Total Baris</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                            <tfoot class="font-weight-bold" style="background-color:#ffffff;color:#212529;">
                                <tr>
                                    <td colspan="{{ $isPkp ? 9 : 8 }}" class="text-right" style="background-color:#ffffff;color:#212529;">Sub Total:</td>
                                    <td class="text-right" id="footSubTotal" style="background-color:#ffffff;color:#212529;">Rp 0</td>
                                </tr>
                                @if($isPkp)
                                    <tr>
                                        <td colspan="9" class="text-right" style="background-color:#ffffff;color:#212529;">Pajak:</td>
                                        <td class="text-right" id="footTaxAmount" style="background-color:#ffffff;color:#212529;">Rp 0</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td colspan="{{ $isPkp ? 9 : 8 }}" class="text-right" style="background-color:#ffffff;color:#212529;">Diskon Global:</td>
                                    <td class="text-right" id="footGlobalDiscount" style="background-color:#ffffff;color:#212529;">Rp 0</td>
                                </tr>
                                <tr>
                                    <td colspan="{{ $isPkp ? 9 : 8 }}" class="text-right" style="background-color:#ffffff;color:#212529;">Total Payable:</td>
                                    <td class="text-right" id="footTotalAmount" style="background-color:#ffffff;color:#212529;">Rp 0</td>
                                </tr>
                            </tfoot>
                        </table>
                        <div id="previewBlockers" class="alert alert-danger d-none"></div>
                    </div>

                    <div id="pricingIntentFields"></div>

                    <div class="d-flex justify-content-end">
                        <a href="{{ route('consignments.billing.index') }}" class="btn btn-secondary mr-2">Batal</a>
                        <button type="submit" class="btn btn-success" id="submitConversionBtn" disabled>
                            <i class="bi bi-check-circle"></i> Confirm & Generate Purchase / Payable
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const isPkp = document.getElementById('billingConversionApp').dataset.isPkp === '1';
            const previewUrl = document.getElementById('billingConversionApp').dataset.previewUrl;

            const supplierInvoiceNumberInput = document.getElementById('supplier_invoice_number');
            const invoiceDateInput = document.getElementById('invoice_date');
            const paymentTermSelect = document.getElementById('payment_term_id');
            const dueDateInput = document.getElementById('due_date');
            const globalDiscountType = document.getElementById('global_discount_type');
            const globalDiscountValue = document.getElementById('global_discount_value');
            const isTaxIncludedInput = document.getElementById('is_tax_included');
            const tbody = document.querySelector('#billingLinesTable tbody');
            const blockersBox = document.getElementById('previewBlockers');
            const submitBtn = document.getElementById('submitConversionBtn');
            const pricingIntentFields = document.getElementById('pricingIntentFields');

            const activeTaxes = @json($isPkp ? $activeTaxes->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'value' => $t->value]) : []);

            let currentLines = [];
            let rowState = {};

            function formatRp(v) {
                return 'Rp ' + Number(v || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            // Nominal-field formatter: mirrors the multi-business price editor's
            // interaction pattern (Indonesian thousand-separated display, raw number
            // while editing, canonical numeric value submitted to the server), adapted
            // to support a configurable decimal precision per field instead of a fixed
            // 2 decimals -- unit price needs up to 6 decimal places (e.g. 45000.333333)
            // while discount/override amounts use 2. The field never silently changes
            // the amount: an unparsable edit is visibly rejected (see wireNominalFields)
            // rather than coerced or discarded without feedback.
            function buildNominalFieldPattern(precision) {
                const idFrac = precision > 0 ? `(,\\d{1,${precision}})?` : '';
                const canonicalFrac = precision > 0 ? `(\\.\\d{1,${precision}})?` : '';
                return {
                    idPattern: new RegExp(`^\\d{1,3}(\\.\\d{3})*${idFrac}$|^\\d+${idFrac}$`),
                    canonicalPattern: new RegExp(`^\\d+${canonicalFrac}$`),
                };
            }

            function parseNominalToCanonical(val, precision) {
                if (val === null || val === undefined) return '';
                let str = String(val).trim();
                if (str === '') return '';

                const { idPattern, canonicalPattern } = buildNominalFieldPattern(precision);

                if (idPattern.test(str)) {
                    const cleaned = str.replace(/\./g, '').replace(',', '.');
                    const num = parseFloat(cleaned);
                    return isNaN(num) ? null : num.toFixed(precision);
                }
                if (canonicalPattern.test(str)) {
                    const num = parseFloat(str);
                    return isNaN(num) ? null : num.toFixed(precision);
                }
                // Unparsable: signal failure rather than silently changing the amount.
                return null;
            }

            function formatCanonicalToNominalDisplay(val, precision) {
                if (val === null || val === undefined || val === '') return '';
                const num = parseFloat(val);
                if (isNaN(num)) return String(val);
                const parts = num.toFixed(precision).split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                return parts.length > 1 ? parts.join(',') : parts[0];
            }

            function getRawNominalValue(val, precision) {
                if (val === null || val === undefined || val === '') return '';
                const num = parseFloat(val);
                if (isNaN(num)) return String(val);
                return num % 1 === 0 ? String(Math.round(num)) : num.toFixed(precision).replace(/0+$/, '').replace(/\.$/, '');
            }

            // A field's CANONICAL precision (data-precision) is what gets validated,
            // stored, and submitted to the server -- e.g. unit price keeps 6 decimal
            // places so a value like 45000.333333 survives round-tripping exactly. A
            // field's DISPLAY precision (data-display-precision, defaulting to the
            // canonical one when absent) is only how many decimals are shown once the
            // field is blurred/formatted; it never touches the stored value. Revealing
            // the raw value on focus always uses the FULL canonical precision, so an
            // edit made against a two-decimal display can never silently truncate a
            // six-decimal amount the operator did not touch.
            function nominalFieldPrecisions(input) {
                const canonical = parseInt(input.dataset.precision || '2', 10);
                const display = input.dataset.displayPrecision !== undefined
                    ? parseInt(input.dataset.displayPrecision, 10)
                    : canonical;
                return { canonical, display };
            }

            // Rejected, unparsable values currently shown on screen. Row inputs are
            // torn down and rebuilt on every preview refresh, so tracking by DOM node
            // would lose a rejection the instant any OTHER field's edit triggers a
            // rebuild -- the rejected field is no longer focused, gets discarded with
            // the rest of the table, and its replacement would silently show the old
            // accepted value with no is-invalid marker. Tracking by logical identity
            // instead lets renderRows() reapply the same rejected text/state to every
            // row's replacement input, not just whichever one happens to be focused.
            //
            // Row-level entries use a Map<rowId, Map<fieldClass, value>> rather than a
            // single concatenated string key: a row_id can itself contain "::"
            // (buildRowId() in ConsignmentBillingPricingCalculator joins the group key
            // and allocation ids with it), so no delimiter drawn from ordinary text
            // could be split back out of a joined "rowId+fieldClass" string
            // unambiguously. Nesting avoids needing to join or split anything.
            // Header-level fields (e.g. global discount) are tracked separately by
            // input.id and are never touched by row cleanup.
            const invalidRowFieldValues = new Map(); // rowId -> Map<fieldClass, value>
            const invalidHeaderFieldValues = new Map(); // input.id -> value

            function hasInvalidNominalFields() {
                return invalidRowFieldValues.size > 0 || invalidHeaderFieldValues.size > 0;
            }

            const ROW_NOMINAL_FIELD_CLASSES = ['row-unit-price', 'row-discount-value', 'row-total-baris'];

            function nominalFieldIdentity(input) {
                const tr = input.closest('tr[data-row-id]');
                if (tr) {
                    const fieldClass = ROW_NOMINAL_FIELD_CLASSES.find(function (cls) { return input.classList.contains(cls); });
                    return { rowId: tr.dataset.rowId, fieldClass: fieldClass };
                }
                return input.id ? { rowId: null, fieldClass: input.id } : null;
            }

            function markNominalFieldInvalid(input, rawValue) {
                input.classList.add('is-invalid');
                input.title = 'Nilai tidak valid: "' + rawValue + '". Perbaiki sebelum melanjutkan.';
                const identity = nominalFieldIdentity(input);
                if (identity) {
                    if (identity.rowId !== null) {
                        if (!invalidRowFieldValues.has(identity.rowId)) {
                            invalidRowFieldValues.set(identity.rowId, new Map());
                        }
                        invalidRowFieldValues.get(identity.rowId).set(identity.fieldClass, rawValue);
                    } else {
                        invalidHeaderFieldValues.set(identity.fieldClass, rawValue);
                    }
                }
                updateSubmitAvailability();
            }

            function clearNominalFieldInvalid(input) {
                input.classList.remove('is-invalid');
                input.removeAttribute('title');
                const identity = nominalFieldIdentity(input);
                if (identity) {
                    if (identity.rowId !== null) {
                        const rowMap = invalidRowFieldValues.get(identity.rowId);
                        if (rowMap) {
                            rowMap.delete(identity.fieldClass);
                            if (rowMap.size === 0) invalidRowFieldValues.delete(identity.rowId);
                        }
                    } else {
                        invalidHeaderFieldValues.delete(identity.fieldClass);
                    }
                }
                updateSubmitAvailability();
            }

            function getInvalidNominalValue(input) {
                const identity = nominalFieldIdentity(input);
                if (!identity) return undefined;
                if (identity.rowId !== null) {
                    const rowMap = invalidRowFieldValues.get(identity.rowId);
                    return rowMap ? rowMap.get(identity.fieldClass) : undefined;
                }
                return invalidHeaderFieldValues.get(identity.fieldClass);
            }

            function isNominalFieldInvalid(input) {
                return getInvalidNominalValue(input) !== undefined;
            }

            // Wires focus (reveal raw editable value) / blur (reformat + commit
            // canonical value) on every .nominal-field in the given container (or
            // document for header-level fields). onCommit receives the canonical
            // string value only when the edit was actually accepted. An unparsable,
            // non-empty entry is kept visible exactly as typed (not silently reverted)
            // and flagged is-invalid, and Confirm is blocked until the operator
            // corrects or clears every such entry.
            function wireNominalFields(container, onCommit) {
                container.querySelectorAll('.nominal-field').forEach(function (input) {
                    const { canonical: canonicalPrecision, display: displayPrecision } = nominalFieldPrecisions(input);

                    input.addEventListener('focus', function () {
                        if (isNominalFieldInvalid(input) || input.dataset.skipNextFocusFormat === '1') {
                            delete input.dataset.skipNextFocusFormat;
                            return;
                        }
                        // Reveal the FULL canonical-precision value for editing, never
                        // the (possibly lower-precision) display value -- otherwise
                        // merely focusing and blurring a field the operator never
                        // meant to change would silently truncate it, e.g. rounding a
                        // stored 45000.333333 unit price down to 45000.33.
                        const canonicalValue = input.dataset.canonicalValue ?? input.value;
                        input.value = getRawNominalValue(canonicalValue, canonicalPrecision);
                    });

                    input.addEventListener('blur', function () {
                        const canonical = parseNominalToCanonical(input.value, canonicalPrecision);

                        if (canonical === null && input.value.trim() !== '') {
                            // Unparsable and non-empty: keep the rejected text visible
                            // (never silently revert it) and block conversion until
                            // it is fixed, so a stale price can never be submitted.
                            markNominalFieldInvalid(input, input.value);
                            return;
                        }

                        clearNominalFieldInvalid(input);
                        const resolved = canonical === null ? '' : canonical;
                        input.dataset.canonicalValue = resolved;
                        input.value = formatCanonicalToNominalDisplay(resolved, displayPrecision);
                        if (onCommit) onCommit(input, resolved);
                    });

                    input.addEventListener('input', function () {
                        // Once the operator starts correcting a rejected value, the old
                        // rejection state no longer describes what's on screen; let the
                        // next blur re-validate it from scratch.
                        if (isNominalFieldInvalid(input)) {
                            clearNominalFieldInvalid(input);
                        }
                    });
                });
            }

            function setNominalFieldValue(input, canonicalValue) {
                input.dataset.canonicalValue = canonicalValue === null || canonicalValue === undefined ? '' : String(canonicalValue);
                const { display } = nominalFieldPrecisions(input);
                input.value = formatCanonicalToNominalDisplay(input.dataset.canonicalValue, display);
            }

            function updateDueDate() {
                const selectedOption = paymentTermSelect.options[paymentTermSelect.selectedIndex];
                if (!selectedOption || !selectedOption.value) return;

                const longevity = parseInt(selectedOption.getAttribute('data-longevity') || '0', 10);
                const invoiceDateVal = invoiceDateInput.value;
                if (!invoiceDateVal) return;

                const d = new Date(invoiceDateVal);
                if (isNaN(d.getTime())) return;

                d.setDate(d.getDate() + longevity);
                const yyyy = d.getFullYear();
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                dueDateInput.value = `${yyyy}-${mm}-${dd}`;
            }

            function buildPricingIntent() {
                const rows = {};
                for (const rowId in rowState) {
                    const s = rowState[rowId];
                    const entry = {};
                    if (s.unit_price !== '' && s.unit_price !== undefined) entry.unit_price = s.unit_price;
                    if (s.discount_type) entry.discount_type = s.discount_type;
                    if (s.discount_value !== '' && s.discount_value !== undefined) entry.discount_value = s.discount_value;
                    if (isPkp && s.tax_id !== '' && s.tax_id !== undefined) entry.tax_id = s.tax_id;
                    // An untouched, preloaded Total Baris must never be submitted as a
                    // manual override -- only send row_total_override once the operator
                    // has actually edited that field themselves.
                    if (s.row_total_touched && s.row_total_override !== '' && s.row_total_override !== undefined) {
                        entry.row_total_override = s.row_total_override;
                    }
                    if (Object.keys(entry).length > 0) rows[rowId] = entry;
                }

                return {
                    is_tax_included: isPkp ? (isTaxIncludedInput ? isTaxIncludedInput.checked : true) : false,
                    global_discount_type: globalDiscountType.value,
                    global_discount_value: parseFloat(globalDiscountValue.dataset.canonicalValue || globalDiscountValue.value) || 0,
                    rows: rows,
                };
            }

            function escapeHtmlAttr(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
            }

            function renderPricingIntentFields(intent) {
                // PHP parses "rows[<key>][...]" bracket syntax from the literal name
                // string; encodeURIComponent()-ing the row ID here would change the key
                // PHP receives (e.g. "/" becomes the 3 characters "%2F"), causing a
                // correctly-edited row to be rejected as an unknown/stale row ID on
                // submit. Only HTML-attribute-escape it, never URL-encode it.
                let html = '';
                html += `<input type="hidden" name="is_tax_included" value="${intent.is_tax_included ? 1 : 0}">`;
                html += `<input type="hidden" name="global_discount_type" value="${intent.global_discount_type}">`;
                html += `<input type="hidden" name="global_discount_value" value="${intent.global_discount_value}">`;
                for (const rowId in intent.rows) {
                    const row = intent.rows[rowId];
                    const safeRowId = escapeHtmlAttr(rowId);
                    for (const key in row) {
                        html += `<input type="hidden" name="rows[${safeRowId}][${key}]" value="${escapeHtmlAttr(row[key])}">`;
                    }
                }
                pricingIntentFields.innerHTML = html;
            }

            function cssEscapeAttr(value) {
                return window.CSS && CSS.escape ? CSS.escape(value) : String(value).replace(/["\\]/g, '\\$&');
            }

            function renderRows(lines) {
                // A full rebuild would otherwise discard whatever the operator is
                // mid-typing into the currently focused row field: capture its live
                // (unformatted) text and cursor position before tearing down the old
                // DOM, so it can be restored onto the corresponding new input
                // afterward. This is ONLY for the live in-progress text of the field
                // being typed into right now -- every field's committed rejected state
                // (including this one's, once it blurs) is separately tracked in
                // invalidRowFieldValues/invalidHeaderFieldValues and reapplied to every
                // row below, not just the focused one.
                let focusedFieldState = null;
                const active = document.activeElement;
                if (active && tbody.contains(active) && active.classList.contains('nominal-field')) {
                    const activeTr = active.closest('tr');
                    focusedFieldState = {
                        rowId: activeTr ? activeTr.dataset.rowId : null,
                        fieldClass: ROW_NOMINAL_FIELD_CLASSES.find(function (cls) { return active.classList.contains(cls); }),
                        value: active.value,
                        selectionStart: active.selectionStart,
                        selectionEnd: active.selectionEnd,
                    };
                }

                // Drop any rejection tracked for a row that no longer appears in this
                // preview response (e.g. approved evidence changed) -- otherwise a
                // vanished row's stale entry would permanently block Confirm with no
                // visible field left for the operator to correct. Header-level entries
                // (invalidHeaderFieldValues) are untouched here: they belong to fields
                // outside the row table entirely.
                const currentRowIds = new Set(lines.map(function (l) { return l.row_id; }));
                for (const rowId of Array.from(invalidRowFieldValues.keys())) {
                    if (!currentRowIds.has(rowId)) {
                        invalidRowFieldValues.delete(rowId);
                    }
                }

                tbody.innerHTML = '';
                lines.forEach(function (line) {
                    if (!rowState[line.row_id]) {
                        rowState[line.row_id] = {
                            unit_price: '',
                            discount_type: 'fixed',
                            discount_value: '',
                            tax_id: line.tax_id ?? '',
                            row_total_override: '',
                            // True only once the operator has actually EDITED the Total
                            // Baris field itself (not merely seen its preloaded value).
                            // An untouched, preloaded Total Baris must never be sent as
                            // a manual override -- that would silently re-derive the
                            // row from a value the operator never chose to change,
                            // instead of the ordinary unit-price/discount calculation.
                            row_total_touched: false,
                        };
                    }
                    const s = rowState[line.row_id];

                    let taxCell = '';
                    if (isPkp) {
                        const options = activeTaxes.map(function (t) {
                            return `<option value="${escapeHtmlAttr(t.id)}" ${String(t.id) === String(s.tax_id) ? 'selected' : ''}>${escapeHtmlAttr(t.name)} (${escapeHtmlAttr(t.value)}%)</option>`;
                        }).join('');
                        taxCell = `<td><select class="form-control form-control-sm row-tax">${options}</select></td>`;
                    }

                    // Unit price displayed always reflects what will be saved: the
                    // server's back-solved price when a manual Total Baris override is
                    // active, otherwise the operator's own entry (or the recalculated
                    // price).
                    const displayedUnitPrice = (!s.row_total_touched && s.unit_price !== '') ? s.unit_price : line.unit_price;
                    const tr = document.createElement('tr');
                    tr.dataset.rowId = line.row_id;

                    tr.innerHTML = `
                        <td>${escapeHtmlAttr(line.product_code)}</td>
                        <td>${escapeHtmlAttr(line.product_name)}</td>
                        <td class="text-right font-weight-bold">${Number(line.quantity).toLocaleString('id-ID', {maximumFractionDigits: 3})}</td>
                        <td class="text-right">${formatRp(line.original_unit_price)}</td>
                        <td class="text-right">${formatRp(line.original_total)}</td>
                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm nominal-field row-unit-price" data-precision="6" data-display-precision="2"></td>
                        <td>
                            <select class="form-control form-control-sm row-discount-type">
                                <option value="fixed" ${s.discount_type === 'fixed' ? 'selected' : ''}>Rp</option>
                                <option value="percentage" ${s.discount_type === 'percentage' ? 'selected' : ''}>%</option>
                            </select>
                        </td>
                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm nominal-field row-discount-value" data-precision="2"></td>
                        ${taxCell}
                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm nominal-field row-total-baris" data-precision="2"></td>
                    `;

                    tbody.appendChild(tr);

                    const unitPriceInput = tr.querySelector('.row-unit-price');
                    const discountValueInput = tr.querySelector('.row-discount-value');
                    const totalBarisInput = tr.querySelector('.row-total-baris');

                    setNominalFieldValue(unitPriceInput, displayedUnitPrice);
                    setNominalFieldValue(discountValueInput, s.discount_value);
                    // Total Baris always shows the current (recalculated or manually
                    // overridden) amount -- it is never blank -- but this initial
                    // canonical value is NOT itself an edit, so row_total_touched is
                    // left exactly as it was.
                    setNominalFieldValue(totalBarisInput, s.row_total_touched ? s.row_total_override : line.total_amount);

                    // Reapply any still-pending rejection for this row's fields (from
                    // ANY previous rebuild, not just this one) -- the operator's
                    // rejected text and the blocked-submit state must survive being
                    // torn down and rebuilt by another field's edit elsewhere on the
                    // page, exactly as if the input had never been replaced.
                    [unitPriceInput, discountValueInput, totalBarisInput].forEach(function (input) {
                        const rejectedValue = getInvalidNominalValue(input);
                        if (rejectedValue !== undefined) {
                            input.value = rejectedValue;
                            input.classList.add('is-invalid');
                            input.title = 'Nilai tidak valid: "' + rejectedValue + '". Perbaiki sebelum melanjutkan.';
                        }
                    });

                    wireNominalFields(tr, function (input, canonicalValue) {
                        if (input === unitPriceInput) {
                            // Only a genuine change to unit price supersedes an
                            // earlier manual Total Baris override -- merely focusing
                            // and blurring Harga Unit without editing it must not
                            // cancel a deliberate override the operator already made,
                            // NOR must it bake the currently-displayed (possibly
                            // override-derived) price into the underlying unit-price
                            // intent. Leaving rowState.unit_price untouched on a no-op
                            // blur means clearing Total Baris afterward correctly
                            // restores the ORIGINAL calculation, not one anchored to
                            // whatever price the override happened to be showing.
                            const previousUnitPrice = displayedUnitPrice;
                            const changed = parseFloat(canonicalValue) !== parseFloat(previousUnitPrice);

                            if (changed) {
                                rowState[line.row_id].unit_price = canonicalValue;
                                // A fresh unit-price edit supersedes any earlier manual
                                // Total Baris override: the row goes back to being
                                // driven by unit price/discount, matching "editing
                                // unit price or discount recalculates editable Total
                                // Baris."
                                rowState[line.row_id].row_total_touched = false;
                                rowState[line.row_id].row_total_override = '';
                            }
                        } else if (input === discountValueInput) {
                            const previousDiscountValue = s.discount_value === '' ? '0' : s.discount_value;
                            const changed = parseFloat(canonicalValue) !== parseFloat(previousDiscountValue);

                            if (changed) {
                                rowState[line.row_id].discount_value = canonicalValue;
                                rowState[line.row_id].row_total_touched = false;
                                rowState[line.row_id].row_total_override = '';
                            }
                        } else if (input === totalBarisInput) {
                            // Only a genuine change counts as touching Total Baris:
                            // focusing and blurring the preloaded value without
                            // editing it must not turn it into a manual override, or
                            // it would silently re-derive the row from a value the
                            // operator never actually chose to change. Compare against
                            // the CURRENTLY DISPLAYED amount (the recalculated total,
                            // or an existing override), not always line.total_amount,
                            // so re-confirming an already-overridden value on a later
                            // rebuild does not spuriously "untouch" it either.
                            const previousDisplayedTotal = rowState[line.row_id].row_total_touched
                                ? rowState[line.row_id].row_total_override
                                : String(line.total_amount);

                            if (canonicalValue === '') {
                                // Clearing the field reverts to the ordinary
                                // unit-price/discount calculation, matching what the
                                // server does when no override is submitted -- it must
                                // not leave a blank Total Baris while the server keeps
                                // computing the old (or a stale) amount. The field is
                                // immediately restored to the recalculated total once
                                // this preview refresh returns, via setNominalFieldValue
                                // in the next renderRows() pass.
                                rowState[line.row_id].row_total_touched = false;
                                rowState[line.row_id].row_total_override = '';
                            } else {
                                const changed = parseFloat(canonicalValue) !== parseFloat(previousDisplayedTotal);
                                rowState[line.row_id].row_total_override = canonicalValue;
                                if (changed) {
                                    rowState[line.row_id].row_total_touched = true;
                                }
                            }
                        }
                        refreshPreview();
                    });

                    tr.querySelector('.row-discount-type').addEventListener('change', function (e) {
                        rowState[line.row_id].discount_type = e.target.value;
                        rowState[line.row_id].row_total_touched = false;
                        rowState[line.row_id].row_total_override = '';
                        refreshPreview();
                    });
                    if (isPkp) {
                        tr.querySelector('.row-tax').addEventListener('change', function (e) {
                            rowState[line.row_id].tax_id = e.target.value;
                            rowState[line.row_id].row_total_touched = false;
                            rowState[line.row_id].row_total_override = '';
                            refreshPreview();
                        });
                    }
                });

                // Restore whatever the operator was mid-typing (not yet blurred, so
                // not yet in invalidRowFieldValues) onto the corresponding new input,
                // taking priority over any already-committed rejected value reapplied
                // above -- the live keystrokes are more current than the last
                // committed state for this specific field.
                if (focusedFieldState && focusedFieldState.fieldClass) {
                    const targetTr = tbody.querySelector(`tr[data-row-id="${cssEscapeAttr(focusedFieldState.rowId)}"]`);
                    const targetInput = targetTr ? targetTr.querySelector('.' + focusedFieldState.fieldClass) : null;

                    if (targetInput) {
                        targetInput.value = focusedFieldState.value;
                        targetInput.dataset.skipNextFocusFormat = '1';
                        targetInput.focus();
                        if (focusedFieldState.selectionStart !== null && focusedFieldState.selectionStart !== undefined) {
                            try {
                                targetInput.setSelectionRange(focusedFieldState.selectionStart, focusedFieldState.selectionEnd);
                            } catch (e) { /* selection API not supported for this input state; harmless to skip */ }
                        }
                    }
                }

                updateSubmitAvailability();
            }

            // Confirm must reflect BOTH the latest server-reported preview validity
            // AND whether any nominal field on screen currently holds a rejected,
            // uncorrected value -- either alone being satisfied is not enough.
            // previewPending additionally blocks Confirm the instant a new preview
            // request is issued and until its matching response is applied: without
            // this, the button would keep reflecting the PREVIOUS (now stale) preview
            // result while a recalculation for the operator's latest edit is still in
            // flight, letting them submit an amount that was never actually reviewed.
            let lastPreviewValid = false;
            let previewPending = true; // starts true: the initial refreshPreview() call has not resolved yet.

            function updateSubmitAvailability() {
                submitBtn.disabled = previewPending || !lastPreviewValid || hasInvalidNominalFields();
            }

            // Requests can resolve out of order (a fast keystroke's request can outrace
            // an earlier, slower one still in flight). Only the response matching the
            // most recently issued request is applied; every earlier one is discarded
            // so stale totals or a stale Confirm state can never overwrite a newer one.
            let previewRequestSeq = 0;
            let latestAbortController = null;

            function refreshPreview() {
                const intent = buildPricingIntent();
                renderPricingIntentFields(intent);

                const payload = new URLSearchParams();
                // No fallback values: the preview must reflect exactly what has been
                // typed so far, including a fully blank invoice header on first load.
                const invoiceNumberVal = supplierInvoiceNumberInput.value;
                if (invoiceNumberVal) payload.set('supplier_invoice_number', invoiceNumberVal);
                if (invoiceDateInput.value) payload.set('invoice_date', invoiceDateInput.value);
                if (dueDateInput.value) payload.set('due_date', dueDateInput.value);
                if (paymentTermSelect.value) payload.set('payment_term_id', paymentTermSelect.value);
                payload.set('is_tax_included', intent.is_tax_included ? '1' : '0');
                payload.set('global_discount_type', intent.global_discount_type);
                payload.set('global_discount_value', intent.global_discount_value);
                for (const rowId in intent.rows) {
                    for (const key in intent.rows[rowId]) {
                        payload.append(`rows[${rowId}][${key}]`, intent.rows[rowId][key]);
                    }
                }

                const requestSeq = ++previewRequestSeq;
                if (latestAbortController) {
                    latestAbortController.abort();
                }
                const abortController = new AbortController();
                latestAbortController = abortController;

                // Block Confirm the instant this edit is issued, not just once its
                // response arrives: the previous lastPreviewValid describes an amount
                // that no longer reflects what's on screen.
                previewPending = true;
                updateSubmitAvailability();

                fetch(previewUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                    },
                    body: payload.toString(),
                    signal: abortController.signal,
                })
                    .then(r => r.json())
                    .then(function (result) {
                        // A newer request has since been issued; this response is stale.
                        if (requestSeq !== previewRequestSeq) return;

                        currentLines = result.lines || [];
                        renderRows(currentLines);

                        document.getElementById('footSubTotal').textContent = formatRp(result.totals.sub_total);
                        if (isPkp) {
                            const taxFoot = document.getElementById('footTaxAmount');
                            if (taxFoot) taxFoot.textContent = formatRp(result.totals.tax_amount);
                        }
                        document.getElementById('footGlobalDiscount').textContent = formatRp(result.totals.global_discount_amount);
                        document.getElementById('footTotalAmount').textContent = formatRp(result.totals.total_amount);

                        previewPending = false;
                        lastPreviewValid = !!result.valid;
                        if (!result.valid) {
                            blockersBox.textContent = 'Preview tidak dapat dibuat: ' + (result.blockers || []).join('; ');
                            blockersBox.classList.remove('d-none');
                        } else {
                            blockersBox.classList.add('d-none');
                        }
                        updateSubmitAvailability();
                    })
                    .catch(function (err) {
                        if (err && err.name === 'AbortError') return; // superseded by a newer request
                        if (requestSeq !== previewRequestSeq) return;

                        previewPending = false;
                        lastPreviewValid = false;
                        blockersBox.textContent = 'Gagal memuat preview. Silakan periksa input Anda.';
                        blockersBox.classList.remove('d-none');
                        updateSubmitAvailability();
                    });
            }

            function debounce(fn, delayMs) {
                let timer = null;
                return function (...args) {
                    clearTimeout(timer);
                    timer = setTimeout(() => fn.apply(this, args), delayMs);
                };
            }

            const debouncedRefreshPreview = debounce(refreshPreview, 400);

            // Typing the invoice number is the only header field with no change/blur
            // signal that also drives Confirm's enabled state -- without this, filling
            // it in last leaves the button disabled until some other control changes.
            supplierInvoiceNumberInput.addEventListener('input', debouncedRefreshPreview);

            $(paymentTermSelect).select2({
                width: '100%',
                allowClear: true,
                placeholder: '-- Pilih Termin Pembayaran --'
            }).on('change', function () {
                updateDueDate();
                refreshPreview();
            });
            invoiceDateInput.addEventListener('change', function () {
                if (paymentTermSelect.value && !dueDateInput.value) {
                    updateDueDate();
                }
                refreshPreview();
            });
            dueDateInput.addEventListener('change', refreshPreview);
            globalDiscountType.addEventListener('change', refreshPreview);
            setNominalFieldValue(globalDiscountValue, '0', 2);
            wireNominalFields(document, function (input) {
                if (input === globalDiscountValue) refreshPreview();
            });
            if (isTaxIncludedInput) {
                isTaxIncludedInput.addEventListener('change', refreshPreview);
            }

            document.getElementById('conversionForm').addEventListener('submit', function (e) {
                // Defense in depth: never submit a pricing intent built from a
                // rejected/uncorrected nominal field, even if Confirm's disabled
                // state were somehow stale.
                if (previewPending || hasInvalidNominalFields() || !lastPreviewValid) {
                    e.preventDefault();
                    updateSubmitAvailability();
                    return;
                }
                renderPricingIntentFields(buildPricingIntent());
            });

            refreshPreview();
        });
    </script>
    @endpush
@endsection

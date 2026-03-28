@props(['name', 'label', 'value' => '', 'disabled' => false, 'error' => null, 'currency' => null])

{{--
  Reusable Nominal Field Component - Deterministic RP Currency Field
  =================================================================

  This component provides deterministic product currency formatting across all forms.
  It manages focus/blur/submit lifecycle automatically using an internal parser/formatter
  so behavior is stable regardless of browser locale and DB currency settings.

  ARCHITECTURE PATTERN: Visible/Hidden Input Dual Pattern
  -------------------------------------------------------

  The component uses TWO input elements to separate concerns:

  1. Hidden Input (type="hidden", name="{{ $name }}")
     - Stores the actual raw numeric value
     - Used for form submission (receives raw numbers)
     - Has wire:model binding if used inside Livewire (for dynamic updates)
     - This is the "source of truth" for the data layer

  2. Visible Input (type="text", class="nominal-field-visible")
     - Displays formatted currency (e.g., "RP 1.000.000,00")
     - Focus: raw, Blur: formatted
     - NO wire:model, NO wire:focus, NO wire:blur (avoid conflicts!)
     - This is the "UX layer" - what the user sees and interacts with

  LIFECYCLE: Page Load → User Interaction → Form Submit
  ------------------------------------------------------

  1. PAGE LOAD:
     - Hidden input has raw value
     - Visible input is rendered as deterministic "RP " formatted value

  2. FOCUS:
     - Visible input switches to canonical raw number (easy to edit)
     - Auto-selects text for quick replacement

  3. KEYUP/CHANGE:
     - Hidden input is updated with extracted raw value
     - Maintains data sync for Livewire if used

  4. BLUR:
     - Visible input formatted to deterministic "RP " output
     - Hidden input synced with final canonical raw value

  5. FORM SUBMIT:
     - Hidden input already contains raw numeric value
     - No unmasking needed - submit as-is

  PROPS:
  ------
  - name (required): Field name for form submission (goes in hidden input)
  - label (required): Display label
  - value (required): Initial raw numeric value
  - disabled (optional, default false): Disables both inputs
  - error (optional): Validation error message
  - currency (optional): Kept for backward compatibility (ignored by deterministic product formatter)

  FIXED PRODUCT FORMAT:
  ---------------------
  - Symbol: "RP "
  - Thousands separator: "."
  - Decimal separator: ","
  - Precision: 2

  WHY THIS PATTERN?
  -----------------

  Problem: Livewire and plugin-driven masking both want to control input DOM state.
  - Re-renders can reset plugin state
  - focus/blur plugin lifecycles can reinterpret raw digits
  - wire:model in visible input causes re-renders that break formatting

  Solution: Separate concerns and use deterministic parser/formatter
  - Hidden input: Livewire data binding (safe from jQuery)
  - Visible input: deterministic JS formatting (no locale/plugin dependency)
  - They communicate via the hidden input's value

  Result: Clean, predictable behavior independent of Livewire re-renders

  EXAMPLE USAGE:
  --------------

  <!-- Basic usage -->
  <x-nominal-field
    name="purchase_price"
    label="Harga Beli"
    :value="$product->purchase_price ?? 0"
  />

  <!-- With validation -->
  <x-nominal-field
    name="sale_price"
    label="Harga Jual"
    :value="old('sale_price', $product->sale_price)"
    :error="$errors->first('sale_price')"
  />

  <!-- Disabled state -->
  <x-nominal-field
    name="tier_1_price"
    label="Harga Bulk"
    :value="$product->tier_1_price"
    :disabled="!$product->stock_managed"
  />

  For more details, see: fix-nominal-field-formatting-consistency change
--}}

@php
    use Illuminate\Support\Str;

    // Generate unique IDs for this field instance
    $fieldId = 'nominal-field-' . Str::random(8);
    $hiddenId = $fieldId . '-hidden';
    $visibleId = $fieldId . '-visible';

    // Format value for display (raw numeric, no currency formatting at render time)
    $displayValue = $value ? (string)$value : '';

    // Fixed product nominal format (deterministic, not system-configurable).
    $symbol = 'RP ';
    $thousandsSeparator = '.';
    $decimalSeparator = ',';
@endphp

<div class="form-group">
    <label for="{{ $visibleId }}">{{ $label }}
        @if($disabled)
            <span class="text-muted">(Tidak dapat diubah)</span>
        @endif
    </label>

    <!-- Hidden input: stores raw numeric value for form submission -->
    <input type="hidden"
           id="{{ $hiddenId }}"
           name="{{ $name }}"
           class="nominal-field-hidden"
           data-field-id="{{ $fieldId }}"
           value="{{ $displayValue }}"
    />

    <!-- Visible input: deterministic RP formatting -->
    <input type="text"
           id="{{ $visibleId }}"
           class="form-control nominal-field-visible @error($name) is-invalid @enderror"
           placeholder="0{{ $decimalSeparator }}00"
           data-field-id="{{ $fieldId }}"
           data-hidden="#{{ $hiddenId }}"
           value="{{ $displayValue }}"
           {{ $disabled ? 'disabled' : '' }}
    />

    <!-- Validation error message -->
    @if($error)
        <span class="invalid-feedback d-block" role="alert">
            <strong>{{ $error }}</strong>
        </span>
    @endif
</div>

<script>
    (function() {
        'use strict';

        if (window.__deterministicNominalFieldBooted) {
            return;
        }
        window.__deterministicNominalFieldBooted = true;

        const RP_PREFIX = 'RP ';
        const THOUSANDS = '.';
        const DECIMAL = ',';
        const PRECISION = 2;

        function triggerInputEvent(el) {
            if (typeof $ !== 'undefined') {
                $(el).trigger('input');
                return;
            }

            el.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function toRawString(value) {
            const numeric = Number.isFinite(value) && value >= 0 ? value : 0;
            const rounded = Math.round(numeric * 100) / 100;

            if (Number.isInteger(rounded)) {
                return String(rounded);
            }

            return rounded.toFixed(PRECISION).replace(/\.?0+$/, '');
        }

        function parseNominal(value) {
            if (value === null || value === undefined) {
                return 0;
            }

            let text = String(value).trim();
            if (!text) {
                return 0;
            }

            text = text.replace(/^RP\s*/i, '');
            text = text.replace(/\s+/g, '');
            text = text.replace(/[^0-9,.-]/g, '');

            if (!text || text === '-' || text === ',' || text === '.') {
                return 0;
            }

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
                const fractional = text.slice(lastDot + 1).replace(/\D/g, '').length;

                if (dotCount === 1 && fractional > 0 && fractional <= PRECISION) {
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
            if (!Number.isFinite(parsed) || parsed < 0) {
                return 0;
            }

            return parsed;
        }

        function formatNominal(value) {
            const numeric = Number.isFinite(value) && value >= 0 ? value : 0;
            const rounded = Math.round(numeric * 100) / 100;
            const fixed = rounded.toFixed(PRECISION);
            const parts = fixed.split('.');
            const grouped = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, THOUSANDS);

            return RP_PREFIX + grouped + DECIMAL + parts[1];
        }

        function getHiddenInput(visible) {
            const selector = visible.getAttribute('data-hidden');
            if (!selector) {
                return null;
            }
            return document.querySelector(selector);
        }

        function initSingleField(visible) {
            if (!visible || visible.dataset.nominalFieldInitialized === '1') {
                return;
            }

            const hidden = getHiddenInput(visible);
            if (!hidden) {
                return;
            }

            const syncHiddenFromVisible = function() {
                const parsed = parseNominal(visible.value);
                hidden.value = toRawString(parsed);
                triggerInputEvent(hidden);
                return parsed;
            };

            const initial = parseNominal(hidden.value || visible.value || '0');
            hidden.value = toRawString(initial);
            visible.value = formatNominal(initial);
            triggerInputEvent(hidden);

            visible.addEventListener('focus', function() {
                const raw = parseNominal(hidden.value || visible.value);
                visible.value = toRawString(raw);
                setTimeout(function() {
                    visible.select();
                }, 0);
            });

            visible.addEventListener('blur', function() {
                const parsed = parseNominal(visible.value);
                hidden.value = toRawString(parsed);
                visible.value = formatNominal(parsed);
                triggerInputEvent(hidden);
            });

            visible.addEventListener('input', syncHiddenFromVisible);
            visible.addEventListener('change', syncHiddenFromVisible);

            visible.dataset.nominalFieldInitialized = '1';
        }

        function initAllNominalFields() {
            const fields = document.querySelectorAll('.nominal-field-visible');
            fields.forEach(function(field) {
                initSingleField(field);
            });
        }

        let initQueued = false;
        function queueInitAll() {
            if (initQueued) {
                return;
            }
            initQueued = true;
            requestAnimationFrame(function() {
                initQueued = false;
                initAllNominalFields();
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAllNominalFields);
        } else {
            initAllNominalFields();
        }

        const observer = new MutationObserver(function() {
            queueInitAll();
        });
        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });

        if (window.Livewire) {
            document.addEventListener('livewire:load', queueInitAll);
            document.addEventListener('livewire:initialized', queueInitAll);
            document.addEventListener('livewire:navigated', queueInitAll);
            if (typeof window.Livewire.hook === 'function') {
                try {
                    window.Livewire.hook('message.processed', queueInitAll);
                } catch (e) {
                    // Livewire v3 may not expose this hook name; events/observer still cover rebinds.
                }
            }
        }
    })();
</script>

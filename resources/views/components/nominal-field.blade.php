@props(['name', 'label', 'value' => '', 'disabled' => false, 'error' => null, 'currency' => null])

{{--
  Reusable Nominal Field Component - Real-Time Indonesian Currency Field
  =======================================================================

  This component provides continuous, real-time nominal formatting across all forms,
  delegating to the shared real-time financial formatter (public/js/financial-input.js) that
  also backs the Sales/Purchase/POS payment amount fields. Centralizing on one formatter
  avoids the drift between two separate parsers.

  ARCHITECTURE PATTERN: Visible/Hidden Input Dual Pattern
  -------------------------------------------------------

  The component uses TWO input elements to separate concerns:

  1. Hidden Input (type="hidden", name="{{ $name }}")
     - Stores the canonical decimal value (e.g. "120000.23")
     - Used for form submission
     - Has wire:model binding if used inside Livewire (for dynamic updates)
     - This is the "source of truth" for the data layer

  2. Visible Input (type="text", class="nominal-field-visible", marked [data-financial-amount])
     - Displays the Indonesian-grouped value continuously (e.g. "120.000,23") -- no currency
       symbol is embedded in the editable text
     - Never reveals raw/unformatted text, including while focused
     - NO wire:model, NO wire:focus, NO wire:blur (avoid conflicts!)
     - This is the "UX layer" - what the user sees and interacts with

  LIFECYCLE: Page Load → User Interaction → Form Submit
  ------------------------------------------------------

  1. PAGE LOAD:
     - Hidden input has the canonical value
     - Visible input is rendered as the Indonesian-grouped display

  2. TYPING:
     - The shared formatter updates the visible display after every accepted keystroke
     - The hidden input is synced to the same canonical value via an `input` event so
       Livewire's wire:model binding stays current

  3. FORM SUBMIT:
     - Hidden input already contains the canonical numeric value
     - No unmasking needed - submit as-is

  PROPS:
  ------
  - name (required): Field name for form submission (goes in hidden input)
  - label (required): Display label
  - value (required): Initial canonical numeric value
  - disabled (optional, default false): Disables both inputs (hidden canonical value stays
    available to the form)
  - error (optional): Validation error message
  - currency (optional): Kept for backward compatibility (ignored -- the required symbol-free
    Indonesian `.`/`,` separator profile is always used regardless of currency settings)

  WHY THIS PATTERN?
  -----------------

  Problem: Livewire and plugin-driven masking both want to control input DOM state.
  - Re-renders can reset plugin state
  - wire:model in visible input causes re-renders that break formatting

  Solution: Separate concerns and use the shared real-time formatter
  - Hidden input: Livewire data binding (safe from jQuery)
  - Visible input: shared real-time JS formatting (no locale/plugin dependency)
  - They communicate via the hidden input's value

  Result: Clean, predictable behavior independent of Livewire re-renders, and identical
  editing behavior to the payment amount fields.

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

  For more details, see: add-realtime-financial-input-formatting change
--}}

@php
    use Illuminate\Support\Str;

    // Generate unique IDs for this field instance
    $fieldId = 'nominal-field-' . Str::random(8);
    $hiddenId = $fieldId . '-hidden';
    $visibleId = $fieldId . '-visible';

    // Canonical numeric value for both hidden storage and initial visible rendering. The
    // shared formatter parses/(re)formats this on init -- no currency symbol is added here.
    $displayValue = $value !== null && $value !== '' ? (string)$value : '';
@endphp

<div class="form-group">
    <label for="{{ $visibleId }}">{{ $label }}
        @if($disabled)
            <span class="text-muted">(Tidak dapat diubah)</span>
        @endif
    </label>

    <!-- Hidden input: stores the canonical decimal value for form submission / Livewire binding -->
    <input type="hidden"
           id="{{ $hiddenId }}"
           name="{{ $name }}"
           class="nominal-field-hidden"
           data-field-id="{{ $fieldId }}"
           value="{{ $displayValue }}"
    />

    <!-- Visible input: shared real-time Indonesian formatting, no currency symbol -->
    <input type="text"
           id="{{ $visibleId }}"
           class="form-control nominal-field-visible @error($name) is-invalid @enderror"
           placeholder="0,00"
           data-field-id="{{ $fieldId }}"
           data-hidden="#{{ $hiddenId }}"
           data-financial-amount
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

        function getHiddenInput(visible) {
            const selector = visible.getAttribute('data-hidden');
            if (!selector) {
                return null;
            }
            return document.querySelector(selector);
        }

        function triggerInputEvent(el) {
            if (typeof $ !== 'undefined') {
                $(el).trigger('input');
                return;
            }
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function initSingleField(visible) {
            if (!visible || visible.dataset.nominalFieldInitialized === '1') {
                return;
            }
            if (typeof window.FinancialInput === 'undefined') {
                // Shared formatter script not yet loaded; retry on the next dynamic-render pass.
                return;
            }

            const hidden = getHiddenInput(visible);
            if (!hidden) {
                return;
            }

            window.FinancialInput.enhance(visible);

            const syncHiddenFromVisible = function() {
                const canonical = window.FinancialInput.getCanonicalValue(visible);
                hidden.value = canonical === null ? '' : canonical;
                triggerInputEvent(hidden);
            };

            // The shared formatter's initial enhance() already parsed the visible field's
            // server-rendered value; sync the hidden field to that same canonical value now
            // (covers cases where the visible value differed slightly, e.g. formatting nuance).
            syncHiddenFromVisible();

            if (typeof $ !== 'undefined') {
                $(visible).on('financial-amount:change', syncHiddenFromVisible);
            } else {
                visible.addEventListener('financial-amount:change', syncHiddenFromVisible);
            }
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

        if (!window.__nominalFieldObserverBooted) {
            window.__nominalFieldObserverBooted = true;

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
        }
    })();
</script>

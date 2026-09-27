## Context

The application uses jQuery maskMoney (v3.1.1) for currency field formatting across 16 files. Currency settings (symbol, separators) are database-driven via `settings()->currency->*`. The product create/edit pages handle 5 nominal fields:
1. Harga Beli (purchase_price)
2. Harga Jual (sale_price)
3. Harga Jual Partai Besar (tier_1_price)
4. Harga Jual Reseller (tier_2_price)
5. Konversi Unit → Harga (conversion prices in unit-configuration component)

**Current Problem:**
- Create page: Correct behavior (raw on focus, formatted on blur)
- Edit page: Broken behavior (formatted on focus due to `maskNow()` early activation + Livewire wire:model re-renders destroying jQuery state)
- Conversion table: Three competing systems (jQuery maskMoney + Livewire wire:model + wire:focus/blur handlers) cause unpredictable behavior

**Why It Happens:**
maskMoney state is ephemeral—it survives user interactions (focus/blur) but NOT DOM re-renders. When Livewire re-renders the input, jQuery maskMoney state is lost, and the input reverts to the Livewire model's formatted value.

## Goals / Non-Goals

**Goals:**
1. Fix edit page to show raw numbers on focus (match create page behavior)
2. Fix conversion table to have reliable focus/blur behavior independent of Livewire re-renders
3. Create `<x-nominal-field>` reusable component that becomes the single source of truth for nominal field formatting
4. Establish a pattern that can scale to 9+ payment forms (Phase 2, future)
5. Standardize null-safety and precision config across all nominal fields
6. Reduce code duplication by centralizing currency formatting logic

**Non-Goals:**
- Do NOT change currency model, database schema, or settings structure (already working)
- Do NOT refactor payment forms in this change (Phase 2 future work)
- Do NOT change maskMoney library version or behavior
- Do NOT add new currency features (e.g., multi-currency support)
- Do NOT modify Livewire core behavior or wire:model mechanics

## Decisions

### Decision 1: Create Reusable Component Over Inline Fixes

**Option A: Quick-fix (remove maskNow, add proper init)**
- Minimal changes, fixes edit page immediately
- But: Leaves code duplication, doesn't prevent future inconsistencies

**Option B: Reusable component (recommended)**
- More work upfront but solves scalability
- Single place to manage all masking logic
- Future-proof for 9+ payment forms

**Choice: Option B**
**Rationale:** Application already uses reusable components (x-input, product-price-setup, sale-price-setup). Nominal field formatting is complex enough and used frequently enough to warrant a dedicated component. Patterns established now make Phase 2 (payment forms) trivial. Reduces maintenance burden long-term.

### Decision 2: jQuery maskMoney for Formatting (No Livewire Alternative)

**Option A: Use Livewire wire:focus/blur handlers exclusively**
- Keeps formatting in Livewire PHP layer
- Avoids jQuery state loss from re-renders
- But: Less proven UX, conversion table already tried this (doesn't work well)

**Option B: Pure jQuery maskMoney (recommended)**
- Proven pattern across 9 payment forms (all working)
- POS terminals form shows best practice with reusable settings
- No state loss if we avoid wire:focus/blur competing handlers
- Well-tested, battle-hardened library

**Choice: Option B**
**Rationale:** maskMoney is proven across 16 files in the codebase. The problem isn't maskMoney—it's Livewire interfering with it. Solution: remove the interference (wire:focus/blur), not replace the formatter.

### Decision 3: Elimination of Competing Livewire Handlers

**Current Conversion Table Problem:**
```javascript
// THREE systems fight for control:
1. <input wire:model="displayPrices.{{ $index }}" />           // Livewire model
2. wire:focus="showRawPrice()" / wire:blur="syncPrice()"      // Livewire handlers
3. jQuery maskMoney binding                                     // jQuery handler
```

**Solution:**
- Remove `wire:focus="showRawPrice()"` and `wire:blur="syncPrice()"`
- Keep `wire:model` only on the HIDDEN input that stores actual numeric value
- VISIBLE input is jQuery-only, synced to hidden via maskMoney value extraction
- Livewire updates are triggered by hidden input, not visible input

**Implementation Pattern:**
```blade
<!-- Hidden input: Livewire's actual data storage -->
<input type="hidden"
       name="conversions[{{ $index }}][price]"
       wire:model="conversions.{{ $index }}.price" />

<!-- Visible input: jQuery maskMoney formatting only -->
<input type="text"
       class="form-control conversion-price-input"
       data-hidden="#conv-price-{{ $rowKey }}"
       value="{{ $displayPrices[$index] ?? '' }}"
       />
```

Before form submit, jQuery extracts raw number from visible input and writes to hidden input.

**Rationale:** Separation of concerns. Visible input handles UX (formatting). Hidden input handles data (state). No interference.

### Decision 4: Component Props Design

```blade
<x-nominal-field
  name="purchase_price"
  label="Harga Beli"
  :value="$price"
  :disabled="!$isActive"
  :error="$errors->first('purchase_price')"
/>
```

**Props:**
- `name` (required): Field name for form submission
- `label` (required): Display label
- `value` (required): Initial value (raw numeric)
- `disabled` (optional, default false): Disabled state
- `error` (optional): Validation error message
- `currency` (optional): Currency data object (auto-loaded if not provided)

**Internals (hidden from user):**
- Reads `settings()->currency->*` automatically if currency not provided
- Generates unique IDs for hidden inputs and event bindings
- Manages maskMoney lifecycle entirely
- Handles form submission extraction

**Rationale:** Simple, intuitive props matching x-input pattern already in use. Component complexity is encapsulated internally.

### Decision 5: Initialization Timing for Edit Page

**Problem:** `maskNow()` activates maskMoney immediately, causing state conflict with focus/blur handlers.

**Solution:** Lazy initialization
```javascript
// Line 241: applyMask() - just configure, DON'T activate
$(...).maskMoney({ prefix: ..., thousands: ..., decimal: ..., precision: 2 })

// Line 253-265: Focus/blur handlers
// On blur, initialize display:
$(...).on('blur', function() {
  let v = parseFloat($(this).val().replace(/[^0-9.-]/g, ''));
  if (isNaN(v)) v = 0;
  $(this).val(v.toFixed(2));
  applyMask();           // reconfigure
  $(this).maskMoney('mask');  // NOW activate only when needed
});
```

This ensures maskMoney is activated only during blur, not on page load. Focus handler destroys it, blur handler reactivates it. Clean lifecycle.

**Rationale:** maskMoney state survives focus/blur cycles but not page load. By not pre-activating on load, we avoid the re-render conflict entirely.

### Decision 6: Backward Compatibility

**Approach:** Create new component, then gradually migrate existing fields

**Phase 1 (This change):**
- Create `<x-nominal-field>` component
- Update product create/edit to use it (5 fields)
- Remove old maskNow() and conflicting Livewire handlers

**Phase 2 (Future):**
- Migrate 9 payment forms to component
- Update any other nominal fields

**Rationale:** New component doesn't break anything. Old code continues working until explicitly migrated. Low risk.

## Risks / Trade-offs

**Risk 1: Component abstraction hides jQuery complexity**
- Mitigation: Document the lifecycle clearly; include inline comments in component; create README for future maintainers

**Risk 2: Hiding one input (input[type=hidden]) might confuse developers**
- Mitigation: Clear naming (data-hidden, data-mirror-of); component documentation; code comments explaining why dual inputs exist

**Risk 3: Livewire developers might try to add wire:model to visible input**
- Mitigation: Comment in template explaining why visible input must NOT have wire:model; document in design decision

**Risk 4: Conversion table with many rows might have performance impact with multiple maskMoney instances**
- Mitigation: jQuery maskMoney is lightweight; each row is independent; MutationObserver already watches for new rows and binds handlers. No measurable impact expected.

**Risk 5: Browser backward compatibility for maskMoney**
- Mitigation: Already using maskMoney in production (16 files); no version change. Browsers already support it.

**Trade-off 1: More code in component vs simpler templates**
- We add ~80 lines to component, but save 50+ lines in each form. NET: fewer lines, better maintainability.

**Trade-off 2: Separation of visible/hidden inputs vs single input**
- Adds complexity to understand, but eliminates Livewire/jQuery conflicts entirely. Worth it.

## Migration Plan

**Deploy Steps:**
1. Create `<x-nominal-field>` component
2. Update product create.blade.php to use new component (5 fields)
3. Update product edit.blade.php to use new component (5 fields)
4. Remove `maskNow()` from edit.blade.php
5. Remove `showRawPrice()` and `syncPrice()` from UnitConfiguration.php (Livewire)
6. Remove `wire:focus` and `wire:blur` from unit-configuration.blade.php (template)
7. Test create and edit pages: all 5 fields, conversion table
8. Verify form submission: raw numbers received by backend

**Rollback Strategy:**
- If issues occur, revert the 7 commits to restore old behavior
- Old behavior was: create works, edit broken, conversion table unreliable
- Risk: Users on edit page see old behavior (formatted on focus), but product still functions

**Testing Coverage:**
- Unit: Component renders with correct props
- Integration: Form submission extracts raw numbers correctly
- Browser: Focus/blur behavior in create, edit, conversion table
- Edge cases: Disabled fields, validation errors, large numbers, decimal places

**Deployment Window:**
- No database changes
- No API changes
- No cache invalidation needed
- Safe to deploy anytime during business hours

## Open Questions

1. Should we add JavaScript unit tests for maskMoney integration, or rely on browser tests?
   - Recommendation: Add 3-4 key scenarios (focus, blur, submit) as tests

2. Should we provide a `<x-nominal-field-inline>` variant for inline edits (if that's a future need)?
   - Recommendation: Not in scope for Phase 1; add if Phase 2 reveals need

3. Should conversion table support negative prices (returns/adjustments)?
   - Current config: `allowNegative: false`. Recommend keeping as-is; can change in future if needed.

4. Should we provide a CSS class for styling focused nominal fields differently?
   - Recommendation: Use existing form-control focus states; no special styling needed

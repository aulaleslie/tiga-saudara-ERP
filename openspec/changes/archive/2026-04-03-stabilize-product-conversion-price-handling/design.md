## Context

The product create and edit flows already use a fixed Indonesian Rupiah display profile for product nominal fields, but conversion prices are implemented through a more fragile path than the main price fields. The current conversion-row implementation spans three layers:

1. `App\Livewire\Product\UnitConfiguration` renders and mutates dynamic conversion rows.
2. The Livewire Blade partial renders both a visible masked conversion price input and a hidden submitted/raw input.
3. The page templates for create and edit each attach their own conversion-price masking and synchronization logic, while the Livewire partial also ships an inline binder for the same fields.

That means the same field can be affected by duplicated mask initialization, duplicated blur/focus handling, and selector-based hidden-input lookup that depends on generated row keys remaining safe to embed in CSS selectors. When the selector contract breaks, the user still sees a filled price while the request payload arrives with `conversions.*.price = null`.

This change is cross-cutting because it touches the Livewire component boundary, both page templates, and request normalization on create/edit. It also interacts with an existing OpenSpec capability for deterministic product nominal fields, so the design needs to keep that contract intact while making dynamic conversion rows reliable.

## Goals / Non-Goals

**Goals:**
- Make product conversion price entry reliable across product create and edit flows.
- Establish one owner for conversion price masking and hidden/raw value synchronization.
- Remove dependence on CSS-selector-safe generated IDs for linking visible and hidden conversion-price inputs.
- Ensure submitted conversion prices are normalized into canonical numeric values before validation on both create and update requests.
- Preserve the existing user-facing validation rules and RP display format for conversion prices.

**Non-Goals:**
- Refactor all nominal-field handling across the application into a new shared library.
- Redesign the visual layout of the product create or edit pages.
- Change the business rule that a conversion price is required when a conversion unit is chosen.
- Replace Livewire, jQuery maskMoney, or the broader product stock-management flow in this change.

## Decisions

### Decision 1: Make `UnitConfiguration` the single owner of conversion-price UI behavior

**Choice:**
All conversion-price masking, focus/blur lifecycle, raw-value synchronization, and rerender rebinding will be owned by the `UnitConfiguration` component markup/script instead of being split between the component partial and the page templates.

**Rationale:**
- The conversion rows are created, removed, and rerendered by `UnitConfiguration`, so the component is the natural lifecycle owner.
- Page-level scripts should not need to know the internal wiring of a nested Livewire component.
- Removing duplicate binders reduces race conditions, double initialization, and debugging ambiguity.

**Alternatives considered:**
- Keep page-level ownership in both create and edit templates: rejected because it duplicates the same logic in two pages and keeps the component leaky.
- Extract a larger shared frontend utility first: rejected for now because it broadens scope beyond the immediate product conversion problem.

### Decision 2: Replace selector-string hidden-field lookup with a DOM-relative contract

**Choice:**
The visible conversion-price input will locate its hidden/raw companion relative to the same cell or row, rather than through `data-hidden="#some-id"` and jQuery selector resolution.

**Rationale:**
- DOM-relative lookup removes dependence on generated row keys being selector-safe.
- It simplifies the component contract: the hidden field is "next to" the visible field, not "somewhere else identified by a selector string".
- This remains compatible with the current visible-plus-hidden architecture while removing its most brittle link.

**Alternatives considered:**
- Keep selector-based lookup but sanitize generated IDs: rejected as only a partial fix because it preserves the fragile contract.
- Remove the hidden field immediately: rejected for the first pass because it is a larger behavioral change and the existing form already depends on hidden/raw submission.

### Decision 3: Keep the hidden/raw input bridge for now, but enforce canonical sync semantics

**Choice:**
The design will preserve the hidden submitted input for conversion prices, while making the synchronization contract explicit:
- focus shows raw editable value
- blur reapplies RP formatting
- input/blur/update events sync the hidden numeric value
- form submit performs a final sync before request dispatch

**Rationale:**
- This aligns conversion rows with the existing "canonical raw value independent from display formatting" model already used for product nominal fields.
- It limits implementation risk by avoiding a larger server/client contract rewrite in the same change.
- It gives the backend a stable numeric payload even if the visible field is currently formatted.

**Alternatives considered:**
- Eliminate hidden inputs and submit the visible field directly: rejected for now because it changes request semantics more broadly and may ripple into other field-handling assumptions.

### Decision 4: Normalize nested conversion prices server-side in both create and update requests

**Choice:**
`StoreProductInfoRequest` and `UpdateProductRequest` will normalize `conversions.*.price` into canonical numeric strings/values during `prepareForValidation()`, alongside the existing boolean normalization already performed there.

**Rationale:**
- Frontend masking remains useful for usability, but the backend should own the final interpretation of submitted price text.
- This creates a second safety net if the UI submits formatted values like `RP 65.000,00`.
- It keeps validation and logging aligned to the actual canonical data contract.

**Alternatives considered:**
- Rely entirely on frontend unmasking: rejected because the current bug demonstrates that frontend-only correctness is too fragile.
- Add normalization only to create request: rejected because edit flow uses the same conversion price UX and should obey the same contract.

### Decision 5: Write conversion-price formatter in vanilla JavaScript, not jQuery

**Choice:**
The conversion-price formatter IIFE in `unit-configuration.blade.php` will use vanilla JavaScript APIs (`addEventListener`, `querySelector`, `closest`, `dispatchEvent`) instead of jQuery (`$().on`, `$().val`, `$().trigger`, `$().closest`).

**Rationale:**
- The Livewire component's `<script>` tag executes during `@yield('content')` body parsing, which runs **before** `@include('includes.main-js')` loads jQuery. The existing guard clause `if (typeof $ === 'undefined') { return; }` silently aborts the entire IIFE on every initial page load, leaving all conversion-price fields without focus/blur handlers, formatting, hidden-field sync, MutationObserver, or form-submit sync.
- The `x-nominal-field` component already uses vanilla JS for the identical visible/hidden input lifecycle pattern and initializes reliably regardless of jQuery loading order.
- Replacing jQuery's `.trigger('input')` with native `dispatchEvent(new Event('input', { bubbles: true }))` also fixes a silent data-sync defect: Livewire's deferred `wire:model` listener detects only native DOM events, so jQuery-triggered events never propagate price changes to the server component state. This causes entered prices to be overwritten on any subsequent Livewire rerender (e.g., adding a row).

**Alternatives considered:**
- Move the script to `@push('scripts')` to execute after jQuery: rejected because Livewire component inline scripts do not reliably push to stacks during morph/rerender, and it preserves a fragile ordering dependency.
- Add a `DOMContentLoaded` fallback while keeping jQuery: rejected because it adds complexity without eliminating the root dependency; any future layout change could re-break timing.
- Load jQuery in `<head>`: rejected because it changes the global page loading strategy for one component's local fix.

## Risks / Trade-offs

- [Keeping the hidden/raw input model still leaves more moving parts than a single submitted field] -> Mitigation: remove the selector fragility and duplicate binders now, and keep the hidden/visible contract explicit and tested.
- [Component-owned inline script may still be harder to test than a dedicated asset file] -> Mitigation: keep the ownership boundary clean in this change and defer extraction to a later refactor if needed.
- [Backend normalization could mask some frontend issues by accepting formatted values] -> Mitigation: retain regression tests that assert hidden/raw synchronization and not just successful validation.
- [Create and edit flows may drift again if one request class or page is updated without the other] -> Mitigation: define specs and tasks that explicitly cover both create and update paths.
- [Livewire component inline scripts execute before jQuery loads] -> Mitigation: write the formatter in vanilla JS so it has zero external library dependencies; follow the proven pattern from the nominal-field component.
- [jQuery `.trigger('input')` does not fire native DOM events, so Livewire `wire:model` never syncs client-entered prices to the server] -> Mitigation: use `dispatchEvent(new Event('input', { bubbles: true }))` for hidden-input sync, which Livewire's deferred wire:model detects and batches with the next server request.

## Migration Plan

1. Refactor the conversion-row markup contract so the visible conversion price input resolves its hidden/raw companion by DOM relationship rather than selector string.
2. Remove duplicate conversion-price binders from the product create and edit page templates, leaving `UnitConfiguration` as the single owner.
3. Update create and update request preparation to normalize nested conversion prices before validation.
4. Add regression coverage for create/edit submission, validation failure round-trips, and dynamic row add/remove after Livewire rerenders.
5. Validate manually and via tests that existing validation messages remain unchanged for genuinely missing or invalid conversion prices.
6. Rewrite the conversion-price formatter IIFE in vanilla JavaScript, removing the jQuery dependency, and use native `dispatchEvent` for hidden-input sync so `wire:model` deferred updates propagate to the server.

**Rollback:**
- Restore the previous page-level binders and request preparation if the component-owned approach causes unexpected regressions.
- Because this change is behavior-preserving at the business-rule level, rollback is primarily a code rollback rather than a data migration.

## Open Questions

- ~~Should the conversion-price masking logic remain inline in the Livewire Blade partial, or should it be moved into a dedicated product-form asset once ownership is consolidated?~~ **Resolved**: Keep inline for now; the formatter must be self-contained vanilla JS because Livewire component scripts execute before external libraries load. Extraction to a separate asset can be considered later but adds no current benefit.
- Should the same backend conversion-price normalization helper be reused for main product nominal fields in a later cleanup to reduce duplicated parsing logic?

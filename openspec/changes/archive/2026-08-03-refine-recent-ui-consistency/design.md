## Context

The app loads CoreUI 3 (Bootstrap 4 core, `resources/sass/app.scss`) plus a utilities-only Tailwind v4 bundle (`resources/css/tw.css`, no preflight, no forms plugin) and Select2 for enhanced selects. Three recent features shipped views written for other stacks:

- Global payment filter panels (purchase/sale tables) use Bootstrap 5 classes (`form-select`, `form-switch`, `fw-bold`, `data-bs-*`) that do not exist in the loaded CSS — selects render unstyled.
- The receiving-completion modal uses BS5 `btn-close` (invisible), `data-bs-dismiss` (dead), `visually-hidden`, `fs-5`, `bg-opacity-10`.
- The correction edit page is pure Tailwind with bare inputs and `alert()` dialogs, visually inconsistent with every CoreUI page.

Behavioral defects: URL-restored card selection on the global payment pages does not recompute table filter flags and is never passed to summary cards (refresh desync); `purchaseReceivingCompleted` has no listener so the list never refreshes; local-mode Pelunasan divides by 100 (legacy scaling removed elsewhere by the currency normalization change).

## Goals / Non-Goals

**Goals:**
- All touched views render correctly under CoreUI 3 / Bootstrap 4 with the existing Select2 conventions.
- Refresh of a global payment URL fully restores table results, summary totals, and card highlight.
- Shortfall completion immediately updates the visible list and shows success feedback without reload.
- Pelunasan (local mode) shows correct rupiah amounts.

**Non-Goals:**
- No framework upgrade (CoreUI 4/5, Bootstrap 5) — that is a separate decision.
- No redesign of pages that already follow app conventions.
- No changes to correction/completion business logic, services, routes, or database.

## Decisions

1. **Translate to BS4/CoreUI dialect rather than shim BS5 classes in CSS.** Adding CSS aliases (e.g., `.form-select { @extend .custom-select }`) would silently legitimize the drift and leave `data-bs-*` JS attributes broken anyway. Direct class translation keeps one dialect in the codebase. Mapping: `form-select` → `custom-select` (native) or Select2 where sibling pages use it; `btn-close` → `close` with `<span>&times;</span>`; `data-bs-dismiss` → `data-dismiss`; `fw-bold` → `font-weight-bold`; `fs-5` → `h5`-scale utility or inline heading; `text-end` may remain (Tailwind provides it) but prefer BS4 `text-right` for consistency; `visually-hidden` → `sr-only`; `bg-opacity-10` → light background class; `form-switch` → CoreUI 3 `c-switch` or plain checkbox as used elsewhere.
2. **Rebuild corrections/edit.blade.php with CoreUI cards/forms.** Keep the existing vanilla-JS preview/submit logic (it is fetch-based and framework-agnostic) but: wrap sections in `card`/`card-body`, use `form-control`/`form-group`, replace `alert()` with the app's flash/toast pattern (session flash on redirect for success; inline alert block for errors), and debounce `input`-driven preview calls (~400ms) while keeping immediate `change` on the payment select.
3. **Restore card-filter state by extracting derivation into a shared method.** `applyPurchaseFilter($type)` logic moves to a private `applyCardFilterType(?string $type)`; both the `#[On('purchase-filter')]` handler and `mount()` (when `selectedCardFilter` arrives via URL) call it. Same in `SaleTable`.
4. **Pass state to summary cards at mount instead of adding more events.** `global-index` blades already have the request query; pass `:globalBusinessFilter`, `:documentDateFrom`, `:documentDateTo`, `:selectedCardFilter` from the request into `<livewire:...summary-cards>`. The existing `*-filters-changed` event keeps handling post-mount updates. Alternative (URL attributes on the cards component) rejected: two components binding the same URL params is fragile in Livewire 3.
5. **List refresh via `#[On('purchaseReceivingCompleted')]` on `PurchaseTable`** that calls `$this->resetPage()` (a no-op re-render suffices since render() re-queries). Success feedback: modal dispatches a browser toast/flash-compatible event rather than relying on `session()->flash` which never renders without navigation; simplest conforming approach is the pattern already used by other modals in the app (inspect and reuse; fall back to a dismissible inline alert region on the page).
6. **Pelunasan fix is a one-line removal of `/100`** in `PurchaseSummaryCards::getPelunasanProperty()` local-mode branch, with a test asserting stored-rupiah totals (mirrors currency-storage-convention).

## Risks / Trade-offs

- [Class translation misses an instance] → grep-driven sweep of the touched files for the known BS5 tokens; visual smoke check of each page.
- [Rebuilding the correction page breaks its JS selectors] → keep all `id`/`name` attributes and the `.correction-input` class unchanged; only container markup changes.
- [Mount-time card filter application changes query results existing tests rely on] → run the existing GlobalPurchasePaymentFilters/Table and Sale equivalents; add mount-restoration tests rather than altering existing ones.
- [Summary cards receive filters via both mount props and events] → event handler remains authoritative post-mount; props only seed initial state.

## Migration Plan

Pure view/component changes; deploy normally. Rollback = revert commit. No data or schema impact.

## Open Questions

- Which toast/flash pattern is canonical for Livewire modals in this app (to reuse for completion success)? Resolve during implementation by inspecting an existing modal (e.g., POS or payment modals) — default to dismissible inline alert if none is standard.

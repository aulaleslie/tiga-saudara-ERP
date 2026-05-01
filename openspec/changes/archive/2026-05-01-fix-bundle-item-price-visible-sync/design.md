## Context

Product bundle item auto-pricing is already computed server-side in `BundleTable::updateProductRow()` using `product_id + session setting_id` lookup against `product_prices.sale_price`. Livewire state and hidden form values receive the resolved numeric value, but the visible price input can appear blank because Alpine component state is preserved during Livewire DOM morphs and does not always reinitialize display formatting from updated server state.

## Goals / Non-Goals

**Goals:**
- Ensure the visible `Harga Informasi Item` field always reflects the latest Livewire value immediately after product selection.
- Preserve existing behavior: source price from active-setting `product_prices.sale_price`, keep field editable, keep submitted value numeric.
- Use a deterministic approach resilient to Livewire v3 morph cycles.

**Non-Goals:**
- Changing bundle pricing model or data schema.
- Changing POS/Sales runtime pricing behavior.
- Reworking global currency field behavior outside bundle item editor scope.

## Decisions

1. Force re-initialization of the bundle item price Alpine instance when the resolved price context changes.
- Rationale: Current watcher-based sync can miss updates when Alpine state is preserved. Re-keying the specific input instance on row identity + selected product + informational price guarantees a fresh Alpine init path and visible value hydration.
- Alternative considered: rely only on `$wire.$watch` nested-path synchronization. Rejected due to non-deterministic behavior observed in preserved Alpine state during morph.

2. Keep server-side source-of-truth unchanged in Livewire component.
- Rationale: Query path and setting resolution are already correct and logged; issue is presentation sync.
- Alternative considered: move all price derivation to client-side JS. Rejected due to duplicated business rules and setting-scoping risk.

3. Add focused regression test coverage for visible behavior expectation.
- Rationale: Existing behavior validated data persistence but not immediate visual hydration on select.
- Alternative considered: manual verification only. Rejected due to recurrence risk.

## Risks / Trade-offs

- [Risk] Overly dynamic `wire:key` may reset in-progress typing if key inputs change unexpectedly. → Mitigation: include only stable identity + server-driven price transitions, not transient keystroke values.
- [Risk] Multiple currencyField definitions may still cause future drift. → Mitigation: scope this change narrowly and document follow-up consolidation separately.
- [Risk] Browser-level timing differences in morph lifecycle. → Mitigation: validate on create and edit bundle flows with Livewire interaction tests and manual QA.

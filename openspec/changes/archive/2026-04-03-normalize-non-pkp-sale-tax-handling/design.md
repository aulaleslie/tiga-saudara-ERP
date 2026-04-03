## Context

The sale module currently has multiple write paths that depend on cart-derived tax and subtotal state:

- Livewire create via `app/Livewire/Sale/CreateForm.php`
- Livewire edit via `app/Livewire/Sale/EditForm.php`
- Controller-backed store/update via `Modules/Sale/Http/Controllers/SaleController.php`
- Shared persistence logic via `Modules/Sale/Services/SaleService.php`

Unlike the purchase module, sales does not currently normalize non-PKP economics at the write boundary. The existing non-PKP behavior mainly strips `tax_id` and zeros some tax fields, but it still trusts incoming `sub_total` and header totals from cart state. This is especially risky on edit, where historical sale details are restored into the cart and can reintroduce tax-bearing subtotals even when the current setting is non-PKP.

The result is a silent inconsistency risk: a non-PKP sale may save successfully with `tax_id = null` and `product_tax_amount = 0`, while `sub_total`, `total_amount`, and `due_amount` still reflect tax-inclusive amounts.

## Goals / Non-Goals

**Goals:**
- Enforce one non-PKP sale persistence invariant regardless of whether the write path is Livewire or controller-backed.
- Normalize non-PKP sale details so persisted `sub_total` values are tax-excluded when hidden or restored tax-bearing state is present.
- Recompute non-PKP sale header totals from normalized line economics instead of trusting cart-level totals blindly.
- Align sale edit cart restore behavior with the persistence rule so non-PKP edit does not silently keep hidden tax-bearing state alive.
- Add regression coverage for non-PKP sale create and edit flows.

**Non-Goals:**
- Redesigning PKP sale tax UX or changing explicit PKP tax-assignment rules.
- Changing purchase, POS, or import tax behavior in this change.
- Migrating or backfilling historical sale data that is already persisted inconsistently.
- Reworking all sale cart aggregation behavior beyond what is necessary for non-PKP normalization.

## Decisions

**1. Enforce the invariant at the sale write boundary**

Introduce a shared sale-level normalization step that receives candidate sale header values, detail inputs, and the current `is_pkp` state, then returns persistence-safe values.

- Rationale: the invariant is about what may be stored, not just what the UI displays.
- Alternatives considered:
  - Controller-only normalization: rejected because Livewire create and edit are primary write paths.
  - Cart-only cleanup: rejected because restore paths and future writers can still bypass cart hygiene.
  - Separate create/edit logic: rejected because it duplicates rules and creates drift again.

**2. Use tax-excluded persistence semantics for non-PKP sales**

When `is_pkp` is false, sale persistence must ignore hidden tax-bearing state and recompute saved economic values from tax-excluded amounts.

- Header normalization:
  - force `tax_id = null`
  - force `tax_percentage = 0`
  - force `tax_amount = 0`
  - recompute `total_amount` and `due_amount` from normalized detail subtotals, discount, shipping, and paid amount inputs
- Detail normalization:
  - force `tax_id = null`
  - force `product_tax_amount = 0`
  - persist `sub_total` using the tax-excluded amount

- Rationale: stripping identifiers alone is insufficient when the saved money values still include tax.
- Alternatives considered:
  - Preserve gross `sub_total` while zeroing tax fields: rejected because it leaves non-PKP sales economically overstated.

**3. Treat edit cart restore as defense-in-depth, not the primary guarantee**

Update sale edit/cart restore so non-PKP flows do not silently repopulate tax-bearing line state where the user cannot meaningfully manage it, while still relying on save-time normalization as the authoritative protection.

- Rationale: edit is the most likely path to keep inconsistent values alive across saves.
- Alternatives considered:
  - Leave restore behavior unchanged and normalize only on save: acceptable for correctness, weaker for observability and operator expectations.

**4. Reuse existing aggregation where possible, but normalize after aggregation**

Keep `SaleCartAggregator` focused on combining cart rows, and perform the economic normalization in a dedicated sale normalizer that consumes aggregated or raw detail inputs consistently.

- Rationale: aggregation and normalization are different responsibilities.
- Alternatives considered:
  - Move non-PKP normalization rules into the aggregator: rejected because aggregation should not become policy-aware in ways that are hard to reason about and reuse.

## Risks / Trade-offs

- [Risk] Existing edit flows may rely on gross subtotals restored from old sales and expect them to round-trip unchanged. → Mitigation: normalize only in non-PKP mode and add regression tests around edit persistence.
- [Risk] Recomputing tax-excluded subtotals may be wrong when restored row state is incomplete or inconsistent. → Mitigation: centralize fallback rules inside the normalizer and cover both create and edit payload shapes in tests.
- [Risk] Sale create and sale edit currently use slightly different persistence paths. → Mitigation: make the normalizer service the shared policy boundary even if entry-point orchestration remains different.
- [Risk] Historical non-PKP sales already saved inconsistently will remain inconsistent until touched or separately backfilled. → Mitigation: treat historical cleanup as a separate follow-up change.

## Migration Plan

- No schema migration is required.
- Deploy the sale normalization logic and regression tests together.
- Rollback is limited to application code and does not require data rollback, though sales saved during the rollout window would retain whichever economic normalization was active at save time.

## Open Questions

- Should non-PKP sale edit actively clear existing `tax_ref_no`, or is setting it to null only at persistence sufficient?
- Should sale normalizer operate on aggregated detail inputs only, or should it support both aggregated rows and raw cart rows so create and edit can share one call shape?
- Should non-PKP edit cart restore visually clear hidden tax state immediately, or is save-time normalization plus recalculated totals sufficient for the first iteration?

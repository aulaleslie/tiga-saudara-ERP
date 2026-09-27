## Context

The purchase module currently has multiple write paths:

- Livewire create via `app/Livewire/Purchase/CreateForm.php`
- Livewire edit via `app/Livewire/Purchase/EditForm.php`
- Controller-backed store/update via `Modules/Purchase/Http/Controllers/PurchaseController.php`
- Alpine purchase cart state that posts into the controller path

An earlier change hardened the controller path so non-PKP purchases strip `tax_id` and zero `product_tax_amount`. That is no longer sufficient because the primary `/purchases/create` and `/purchases/{id}/edit` screens persist purchases through Livewire submit handlers that compute and save tax-bearing totals directly from cart state.

The current failure mode is deeper than a hidden selector bug. In non-PKP mode, product defaults or restored purchase details can still place `tax_id`, `product_tax_amount`, and tax-inflated subtotals into cart state. When those values are trusted during persistence, the saved purchase becomes economically wrong: the user never selected tax, but the stored totals still include it.

## Goals / Non-Goals

**Goals:**
- Enforce a single non-PKP purchase persistence rule regardless of whether the write path is Livewire or controller-backed.
- Apply Option B semantics for non-PKP purchases: remove tax and recompute persisted economic amounts using tax-excluded values.
- Keep purchase cart/UI behavior consistent with the persistence rule so hidden tax state does not linger invisibly during create or edit.
- Add regression coverage for the Livewire purchase create and edit paths, not just the controller path.

**Non-Goals:**
- Changing sale, POS, or purchase import tax behavior in this change.
- Redesigning all tax UX across the product, sale, or POS modules.
- Backfilling or migrating historical purchases that were already saved incorrectly.

## Decisions

**1. Enforce the invariant at the purchase write boundary**

Introduce a shared purchase-level normalization step that receives candidate purchase header/detail values plus the current `is_pkp` context and returns persistence-safe values.

- Rationale: the real invariant is about what may be stored, not just what the cart should display.
- Alternatives considered:
  - `ProductCart` only: improves UX but leaves controller, Alpine, and future writers exposed.
  - `CreateForm`/`EditForm` only: fixes the broken screens but duplicates logic and diverges from the controller path again.

**2. Use Option B semantics for non-PKP purchases**

When `is_pkp` is false, purchase persistence must treat hidden tax as invalid and recompute saved values from tax-excluded amounts.

- Header normalization:
  - force `tax_id = null`
  - force `tax_percentage = 0`
  - force `tax_amount = 0`
- Detail normalization:
  - force `tax_id = null`
  - force `product_tax_amount = 0`
  - persist `sub_total` using the tax-excluded amount
- Totals normalization:
  - recompute purchase `total_amount` and `due_amount` from normalized detail subtotals, global discount, and shipping

- Rationale: simply nulling tax identifiers while preserving tax-inflated totals would silently overcharge non-PKP purchases and keep the data economically inconsistent.
- Alternatives considered:
  - Option A, preserve gross totals but zero tax fields: rejected because it hides the tax component instead of removing it.

**3. Keep cart hygiene as defense-in-depth, not the primary guarantee**

Update purchase cart behavior so non-PKP mode does not auto-seed purchase tax from `ProductPrice.purchase_tax_id`, and non-PKP edit/cart restore does not preserve hidden line tax in ways the user cannot inspect.

- Rationale: this reduces operator confusion and keeps the cart visually aligned with persistence, but the save path remains the authoritative protection.
- Alternatives considered:
  - leave cart behavior untouched and rely only on save-time normalization: acceptable for correctness, weaker for UX and debugging.

**4. Align purchase-only UI affordances with non-PKP behavior**

Tax-only purchase fields that are intentionally absent on non-PKP create should not remain editable on non-PKP edit if the workflow cannot persist tax.

- Rationale: hidden or disabled tax assignment paired with visible tax-only fields creates mixed signals about what the system will save.
- Alternatives considered:
  - no UI adjustment: functionally possible, but it keeps the workflow misleading.

## Risks / Trade-offs

- [Risk] Existing edit flows may rely on gross line totals stored in cart state when toggling tax inclusion. → Mitigation: normalize only at non-PKP persistence and add regression tests around create/edit totals.
- [Risk] Recomputing from `sub_total_before_tax` may be wrong if a writer omitted that value or stored inconsistent data. → Mitigation: centralize the fallback rules inside the normalizer and cover both Livewire and controller payload shapes in tests.
- [Risk] Touching both cart hygiene and persistence in one change increases scope. → Mitigation: keep the shared normalizer minimal and use cart/UI adjustments only where they directly remove hidden non-PKP tax state.
- [Risk] Historical non-PKP purchases already saved with tax remain inconsistent. → Mitigation: treat historical cleanup as a separate follow-up, not part of this implementation.

## Migration Plan

- No schema migration is required.
- Deploy the write-side normalization and regression tests together.
- Rollback is straightforward because the change is limited to purchase persistence and UI behavior; reverting the code restores previous behavior without data migration.

## Open Questions

- Should non-PKP purchase edit actively clear previously stored `tax_ref_no`, or is hiding the field sufficient while leaving existing values untouched?
- Should the shared normalizer accept already-calculated cart totals, or should it always recompute header totals solely from normalized line values plus discount/shipping inputs?

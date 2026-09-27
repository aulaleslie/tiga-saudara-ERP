## Context

PKP purchase and sale flows use separate Livewire cart components, but both render the tax selector with the same interaction shape: a deferred `product_tax` binding plus an `updateTax()` change handler. The handler recalculates totals and persists the selected tax into the cart row, while submit-time validation later checks the cart row's `product_tax` to determine whether a PKP line has a valid tax assignment.

The failure mode appears when the user selects a tax for the first time on a previously taxless row. Because the change handler currently reads the selected tax from deferred Livewire component state, the first `updateTax()` call can observe stale `null` instead of the newly chosen value. That leaves the cart row unchanged and causes a false PKP validation failure even though the user already selected a tax in the UI.

Constraints:

- The system must preserve manual tax assignment in PKP mode and must not reintroduce automatic default-tax fallback for taxless rows.
- Purchase and sale carts should stay behaviorally aligned because they share the same user expectation and defect pattern.
- The fix should avoid broad lifecycle rewrites in already-large cart components.

## Goals / Non-Goals

**Goals:**

- Ensure the first explicit tax selection in PKP purchase and sale carts is applied in the same interaction that triggered it.
- Keep cart totals, tax-inclusive calculations, bundle recalculation, and quick-add tax flows working as they do today.
- Add regression tests that prove cart-row tax persistence does not depend on deferred component state already being synchronized.

**Non-Goals:**

- Reworking the overall Livewire cart architecture.
- Changing non-PKP tax stripping rules or introducing automatic tax assignment for PKP rows.
- Refactoring sale edit PKP validation consistency beyond what is necessary to fix the cart selection defect.

## Decisions

### Pass the selected tax value directly into `updateTax()`

Both tax selectors will pass the selected value directly to `updateTax(rowId, productId, selectedTaxId)`, and each component will normalize that input before recalculating totals and updating the cart row.

Rationale:

- It removes the timing dependency on `wire:model.defer`.
- It is the smallest change that fixes both purchase and sale carts consistently.
- It preserves the current request pattern and avoids introducing extra Livewire round-trips.

Alternatives considered:

- Change the select binding to `wire:model.live` or `wire:model.change`.
  Rejected because it still depends on Livewire synchronization ordering and broadens request churn.
- Move tax recalculation to an `updatedProductTax*` lifecycle hook.
  Rejected because it would require a wider refactor across both cart components and increase regression risk.

### Treat the change handler as the source of truth for normalization

`updateTax()` will accept nullable or empty-string values, normalize them to either `null` or an integer tax ID, and write the normalized value back into `$this->product_tax[...]` before cart persistence.

Rationale:

- It keeps the normalization logic close to where cart-row persistence happens.
- It makes the handler resilient when called by tests, UI events, or quick-add follow-up flows.

Alternatives considered:

- Rely only on the HTML select and existing bound state.
  Rejected because the race condition exists specifically because that state is not always current when the handler runs.

### Add regression tests at the cart-component level

The regression tests will target the purchase and sale `ProductCart` Livewire components directly. Each test will simulate a taxless row, call `updateTax()` with an explicit selected tax value, and assert that the cart row immediately persists the tax and recalculates monetary fields.

Rationale:

- The defect originates in cart interaction timing, not in controller or request validation alone.
- Cart-level tests are smaller, faster, and more deterministic than full browser-style submit reproductions.

Alternatives considered:

- Only add create/edit form submit tests.
  Rejected because those tests would confirm the symptom but not pin the timing-sensitive cart behavior that caused it.

## Risks / Trade-offs

- [View-to-method coupling] Passing `$event.target.value` from the view makes the Blade markup slightly more coupled to the method signature. → Mitigation: keep the signature identical across purchase and sale carts and document it through regression tests.
- [String normalization differences] HTML select values arrive as strings and can differ from previously expected integer/null shapes. → Mitigation: normalize centrally in `updateTax()` before tax lookup or cart updates.
- [Uncovered adjacent inconsistency] Sale edit PKP enforcement may still be inconsistent with the other submit flows. → Mitigation: keep this change scoped to tax-selection persistence and note the separate consistency follow-up if needed.

## Migration Plan

1. Update purchase and sale cart Blade tax selectors to pass the selected value into `updateTax()`.
2. Update both `ProductCart` components to normalize the explicit selected value and persist it to the cart row before recalculating totals.
3. Add regression tests for purchase and sale cart tax selection persistence.
4. Run the focused Livewire test suite for purchase and sale cart tax behavior.

Rollback strategy:

- Revert the Blade and `ProductCart` method-signature changes together, because the view and handler must remain aligned.

## Open Questions

- None required for implementation. The defect mechanism and minimal fix path are clear enough to proceed.

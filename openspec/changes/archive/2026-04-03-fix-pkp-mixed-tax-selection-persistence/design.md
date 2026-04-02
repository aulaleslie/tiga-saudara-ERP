## Context

PKP tax assignment in purchase and sale carts is handled inside separate Livewire cart components, but both modules share the same behavioral contract: the UI tax selector, the in-memory Livewire property, the persisted shopping-cart row options, recalculation routines, and submit-time validation must all agree on the same per-line `tax_id`. The recent fix addressed the first explicit selection on a single row by passing the selected value directly into `updateTax()`, but the mixed-row path remains fragile when one row visually uses the tax marked as default and another row uses a non-default tax.

The current risk is not that submit validation is overly strict. Validation correctly checks whether each cart row has a persisted `product_tax`. The real problem is state divergence between what the selector shows and what the cart row stores after a sequence of mixed selections and recalculations.

## Goals / Non-Goals

**Goals:**
- Ensure every PKP cart row has a single authoritative persisted tax source after user interaction.
- Preserve explicit user choice on each row independently, regardless of whether the selected tax is marked `is_default`.
- Keep purchase and sale cart behavior aligned so mixed-row flows do not diverge by module-specific row-key handling.
- Define regression coverage for multi-row mixed-tax behavior before implementation.

**Non-Goals:**
- Reintroducing automatic fallback to default or latest taxes for taxless PKP rows.
- Changing tax calculation formulas or non-PKP tax stripping behavior.
- Redesigning the cart UI beyond clarifying persisted selection behavior.

## Decisions

### Decision: Treat cart row options as the submit-time source of truth
Submit and validation paths already read `options.product_tax` from the shopping cart. The implementation should preserve that contract and make all cart recalculation paths update and read the same persisted value rather than infer tax state from selector ordering or deferred Livewire state.

Alternative considered:
- Reading only from Livewire component arrays during submit. Rejected because submit paths already rely on cart row options and sale/purchase create and edit flows are built around cart persistence.

### Decision: Normalize line identity explicitly in both modules
Purchase rows currently key tax state by product ID, while sale rows key state by synthetic line IDs. The implementation should make row identity explicit and consistent inside each module so that tax updates, tax-included recalculation, and quick-add tax creation always target the intended line and never rely on implicit UI ordering.

Alternative considered:
- Keeping the current mixed key strategy and patching individual handlers. Rejected because the bug appears in cross-handler sequences, which indicates a structural state-targeting problem rather than a single missing assignment.

### Decision: Default tax ordering must remain presentation-only
The tax marked `is_default` may appear first in dropdown options, but it must not be treated as selected unless the row already persists that tax or the user explicitly chooses it. This avoids false equivalence between “default-looking” and “persisted.”

Alternative considered:
- Auto-persisting the default tax when the dropdown renders. Rejected because it conflicts with the existing `tax-assignment` contract that tax selection in PKP mode is explicit.

### Decision: Add mixed-row regression tests at the Livewire/cart boundary
The missing coverage is multi-row behavior with mixed default and non-default selections. Tests should assert persisted cart state, recalculated totals, and submit readiness after sequences such as selecting default on one row and non-default on another, then toggling tax inclusion or submitting.

Alternative considered:
- Relying on single-row tests plus manual QA. Rejected because the current bug escaped through exactly that gap.

## Risks / Trade-offs

- [Purchase and sale carts use different row identifiers] → Mitigation: document and test the intended identity model in each module before changing persistence logic.
- [Fixing one handler may still leave stale state in recalculation paths] → Mitigation: test `updateTax`, tax-included toggles, and submit validation as one sequence rather than in isolation.
- [UI may still visually imply a default selection when persisted tax is null] → Mitigation: ensure the placeholder remains the only selected state until a row explicitly persists a tax.
- [Regression coverage may be noisy due to current sqlite test instability] → Mitigation: keep scenario design precise so tests can be run selectively once the environment issue is addressed.

## Migration Plan

No data migration is required. Deployment is code-only.

Rollback is low risk: revert the cart tax persistence changes if mixed-row regressions appear, while keeping the proposal/spec artifacts for the next iteration.

## Open Questions

- Whether purchase cart line identity should remain product-ID based when duplicate product rows are impossible, or whether it should still be refactored toward row-based targeting for consistency with sale.
- Whether the current UI ever renders a tax marked `is_default` as effectively selected when the row still persists `null`, or whether the confusion happens only after a specific interaction sequence.

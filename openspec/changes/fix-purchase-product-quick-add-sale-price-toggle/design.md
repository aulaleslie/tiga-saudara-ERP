## Context

The purchase create/edit pages mount the shared `ProductQuickAddModal` at page level and reuse it for both purchase and sales entry contexts. In purchase context, the modal starts with `is_sold = false` and currently renders the sale-pricing section only when `@if($is_sold)` evaluates true inside the Blade view.

The underlying Livewire component already exposes the expected state transition: the `modal_is_sold` checkbox is bound with `wire:model.live="is_sold"`, and `updatedIsSold()` only clears pricing fields when the checkbox is turned off. Existing component tests also show the Livewire property model is healthy, which points to a view-layer rendering issue inside the Bootstrap/CoreUI-managed modal rather than a business-rule failure.

This modal already combines Livewire, Alpine currency inputs, nested dropdown components, and Bootstrap/CoreUI modal focus management. That combination makes conditional mount/unmount of entire form sections a higher-risk rendering strategy than keeping the section mounted and toggling visibility or disabled state.

## Goals / Non-Goals

**Goals:**
- Make the sale-pricing inputs appear reliably when a purchase-context user enables `Saya Jual Barang Ini`.
- Preserve the existing sales-context behavior where sale pricing is visible and required.
- Keep quick-add reset behavior intact so disabled sale-pricing state clears values when the checkbox is turned off or the modal resets.
- Add regression coverage for purchase-context rendering so this interaction does not silently break again.

**Non-Goals:**
- Redesigning the product quick-add modal layout.
- Replacing Bootstrap/CoreUI modal management.
- Changing product pricing persistence rules outside this toggle/rendering bug.
- Introducing a separate purchase-only or sales-only product quick-add modal.

## Decisions

### Decision 1: Keep the sale-pricing DOM mounted and toggle state instead of conditionally mounting it

**Choice**: Replace the purchase-context `@if($is_sold)` gate with an always-rendered sale-pricing section that switches visibility and disabled state based on `is_sold`, while leaving sales context forced on as it is today.

**Rationale**: The repo already uses this pattern in the shared sale-price setup partial, and it is safer in a modal that mixes Livewire subtree updates with Alpine-initialized inputs and nested Livewire children. Keeping the section mounted avoids relying on Livewire to inject a new subtree into an already open modal.

**Alternatives considered**:
- *Keep `@if($is_sold)` and debug the rerender path*: Lower change surface, but it preserves a fragile rendering pattern and does not reduce the probability of similar modal regressions.
- *Split purchase and sales into separate modal views*: More explicit, but excessive for a single toggle-state bug and would duplicate shared quick-add behavior.

### Decision 2: Reuse the repo's existing active/inactive pricing-section pattern

**Choice**: Model the purchase-context sale-pricing block after the existing pricing setup partials that always render fields and disable them when inactive.

**Rationale**: This aligns the modal with a pattern already used in the codebase, which improves consistency and makes future maintenance easier.

**Alternatives considered**:
- *Introduce new Alpine-only visibility management*: Would add another state source and increase the risk of Livewire/Alpine divergence.
- *Hide with CSS only but keep inputs enabled*: Risks accidental submission of stale values after the toggle is turned off.

### Decision 3: Cover both state transition and rendered output in regression tests

**Choice**: Add or update Livewire feature coverage so purchase-context rendering is asserted when `is_sold` is toggled on and off, including reset behavior after save or explicit disable.

**Rationale**: The component already has state-level tests for sales context, but this bug lives in the rendered purchase-path interaction. Tests need to assert what the user actually sees.

**Alternatives considered**:
- *Rely on existing save-path tests only*: Insufficient because the current failure occurs before submission.

## Risks / Trade-offs

- **[Risk] Always-rendered sale-pricing fields may preserve stale Alpine display state** → Mitigation: continue clearing the Livewire sale-pricing properties on disable/reset and ensure the rendered containers reinitialize or reflect disabled/hidden state consistently.
- **[Risk] Hidden nested dropdowns may still participate in layout or focus behavior** → Mitigation: use the same disabled-state handling already used elsewhere and keep the hidden state scoped to the sale-pricing container only.
- **[Risk] Purchase-context fix could regress sales quick-add** → Mitigation: keep the sales-context force-on behavior unchanged and preserve the existing sales quick-add test coverage.

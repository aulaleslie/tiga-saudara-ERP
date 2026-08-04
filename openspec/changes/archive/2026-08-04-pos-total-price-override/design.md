## Context

The POS cart calculates its grand total from cart rows. Existing line-price overrides use the POS action-approval request, supervisor queue, one-time token, and final cashier confirmation flow. Packed rows already demonstrate the required accounting model: an exact minor-unit `line_total` is authoritative while `unit_price` is a blended display value. The cart can contain normal, packed, bundled, serial-tracked, and split-owner rows; checkout posts from the cart snapshot.

The desired feature allows a cashier to quote an exact new total for the whole current basket without splitting customer-facing rows. It must work for both increases and decreases and must preserve downstream reconciliation.

## Goals / Non-Goals

**Goals:**

- Allow an exact non-negative target total for the current mutable POS cart.
- Govern the action by a distinct direct permission and existing supervised approval lifecycle.
- Materialize the target into exact authoritative row totals and effective displayed unit prices without changing visible row count.
- Preserve tax, payment, receipt, packed pricing, bundle allocation, serial, and split-owner posting reconciliation.
- Maintain an audit trail that explains the original total, target, allocation, requester, approver, and executor.

**Non-Goals:**

- Do not modify completed checkouts, historical receipts, sales, or transaction snapshots.
- Do not split cart rows or show different prices per physical unit to the cashier.
- Do not introduce document discounts or surcharges as independent accounting objects.
- Do not allow an override to survive any cart mutation.

## Decisions

### 1. Use a distinct cart-level action and permission

Introduce `TOTAL_PRICE_OVERRIDE` and `pos.overrides.total-price`. The request targets the POS session/cart, rather than a line. Direct permission and Super Admin bypass execute immediately; other users create the same approval request, supervisor decision, one-time token, and explicit-confirmation flow already used by price overrides.

Using `pos.overrides.price` was considered, but separate authority and reporting are required because changing an entire basket has materially different commercial impact from changing one row.

### 2. Treat the requested total as an exact target, not a writable grand total

The application calculates target row gross amounts in minor units. It allocates the target proportionally to each current row's pre-override gross amount using deterministic largest-remainder rounding ordered by cart `line_id`; the sum of allocated row totals MUST equal the requested total. A zero-valued source basket has no meaningful proportional distribution and is rejected unless no rows exist (which is also not overridable).

Writing `grand_total` directly was rejected because totals are derived and would diverge from checkout posting. A signed bill-level adjustment was rejected because higher totals are supported and the user requires adjusted unit prices on rows.

### 3. Use authoritative allocated `line_total` with an effective unit price

For each row, store the allocated exact minor-unit `line_total`, a rounded effective `unit_price = line_total / qty` for presentation, and `price_source = TOTAL_OVERRIDE`. The totals calculator continues to use `line_total` as authoritative. This keeps a quantity-three row visible as one row while allowing an exact Rp10.000 line total even though a single whole-Rupiah price cannot divide evenly across three units.

Splitting rows into Rp3.333/Rp3.334 subrows was rejected because it confuses the cashier. Accepting a rounding mismatch was rejected because payments, receipts, and posted sales must equal the approved target.

### 4. Freeze an applied override and invalidate it on cart mutation

The approval request payload includes requested total, source total, a canonical cart fingerprint, and reason. Applying a token rechecks that fingerprint. Any cart mutation (line add/remove, quantity, serial, customer/tier, line-price, or total-price change) cancels pending and approved-but-unconsumed total-price requests and clears an applied total override before recomputing normal pricing. This prevents a supervisor decision for one basket applying to another.

The allocation snapshot is retained in approval/audit context. Packed/bundle pricing data remains present for audit and stock/allocation, but its repricing path MUST not overwrite a frozen total override until the override is invalidated.

### 5. Surface the action as a cart-level approval state

The cart snapshot exposes pending/approved total-price approval state separately from line approval arrays. The sell UI provides a total-price modal and a cart-total action that follows the existing `ApprovalManager` lifecycle. The supervisor queue displays source total, requested total, delta, reason, and cart/session target.

### 6. Preserve existing checkout calculations and posting interfaces

No checkout endpoint accepts a new arbitrary total. Once applied, the normal snapshot contains the allocated line totals; existing payment validation, tax calculation, receipt mapping, split planner, and posting adapters consume it normally. Allocation must run before checkout and be covered by end-to-end tests for ordinary, packed, bundle, serial, and split-owner carts.

## Risks / Trade-offs

- [Per-unit price cannot always divide evenly into a requested total] → Use exact minor-unit `line_total` as the monetary source of truth and label the rounded unit price as effective.
- [A user changes the cart while approval is pending] → Fingerprint requests and cancel/invalidate requests on every relevant cart mutation; recheck at token consumption.
- [Packed/bundle reprice routines overwrite allocation] → Treat `TOTAL_OVERRIDE` as frozen pricing and explicitly clear it before normal repricing paths resume.
- [Proportional allocation changes individual row amounts] → Use current gross values and deterministic line-ID remainder allocation; show the final row amounts before checkout and persist the allocation in audit context.
- [Existing checkout paths interpret `line_total` differently] → Add focused tests around totals, payments, receipt snapshots, split-owner groups, and persistence before rollout.

## Migration Plan

1. Add the permission/action mappings and cart-total override endpoint/UI behind the existing POS authorization surface.
2. Deploy additive code only; approval requests remain in the existing JSON payload and audit context, so no table migration is required unless an explicit indexed audit projection is later requested.
3. Roll back by removing access to the UI/route. Existing approved or applied carts remain calculable because their line totals are self-contained in the cart snapshot.

## Open Questions

- None for the initial scope. The effective unit-price label and total-override audit wording will follow the current Indonesian POS UI terminology during implementation.

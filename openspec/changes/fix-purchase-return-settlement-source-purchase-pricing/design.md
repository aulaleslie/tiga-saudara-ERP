## Context

Purchase return settlement is driven by `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php`. Each settlement line carries a `nominal` (the `Nilai Penyelesaian`) and a `max_nominal` ceiling, both seeded during `mount()` from the stored `PurchaseReturnDetail`.

Those stored values originate in the return **creation** form, which reads `product_prices.last_purchase_price` — a per-setting rolling catalogue value — rather than the price on the purchase being returned. One live creation path (`ProductSearchDropdown::select()`) additionally calls `lastPurchasePrice()` with no `settingId` argument, so `Product::priceRow()` falls back to the product's own `setting_id` instead of the session's, and can resolve to a different tenant's price row or to `null` → `0.00`.

Settlement already knows better. `getUnpaidPurchasesProperty()` reads `purchase_details.unit_price` for the target purchase, and the `.target_purchase_id` branch of `updatedSettlementLines()` uses it to reprice. The defect is that this repricing is reachable from only one of the four situations where a line acquires a target purchase, and its result is then clamped by the catalogue-derived `max_nominal`.

Current behaviour, per path:

| Path | Assigns target | Reprices |
|---|---|---|
| `mount()` non-serial auto-assign (line 121) | yes | no |
| `.method` serial auto-select (line 324) | yes | no |
| `.method` non-serial auto-select (line 338) | yes | no |
| `.target_purchase_id` manual change (line 355) | yes | yes, but capped |

The immediate operational driver is cancelling an unpaid purchase by returning its full quantity: the credit must equal the purchase total for `due_amount` to net to zero.

## Goals / Non-Goals

**Goals:**

- Make the selected source purchase the single authority for a targeted `MODIFY_PURCHASE` settlement value.
- Apply that authority uniformly across all four paths above, via one shared code path rather than four copies.
- Repair existing draft settlements without a data migration, by recomputing at hydration.
- Keep the submit-time validation ceiling consistent with the recomputed value so correct values can be saved.

**Non-Goals:**

- Fixing the catalogue reads in the return **creation** form (`ProductSearchDropdown:92`, the dead `ProductSearchPurchaseReturn:72`, `ReplacementProductSearch:82-89`). Tracked as follow-up; settlement recomputation makes them non-determinative for settled outcomes.
- Changing how the approval/execution path in `PurchasesReturnSettlementController` **applies** a settlement — its credit accrual, `due_amount` capping, and `SupplierCredit` overflow behaviour are relied upon unchanged. Its approval-time *validation* ceiling is amended; see Decision 8.
- Changing the untargeted `MODIFY_PURCHASE` flow used for cash refunds on paid purchases.
- Changing `PRODUCT_REPAIR` or `BROKEN_STOCK`.
- Introducing a purchase-cancellation feature. Full-quantity return against a source nota is the supported route.

## Decisions

### 1. The rule is targeted-vs-untargeted, not paid-vs-unpaid

A line with a target purchase takes that purchase's price. A line without one keeps its stored value and existing ceiling.

This was reached by rejecting a paid/unpaid split. The operator's established workflow for an already-paid purchase — where the supplier refunds cash — is to select `MODIFY_PURCHASE` and leave the target blank. Keying behaviour on payment status would have disturbed that flow and forced a decision about orphaned overpayments; keying it on target presence leaves it untouched, because no purchase is being modified.

This also explains, and leaves in place, the existing `$originPaid <= 0` guard on auto-selection: auto-assignment stays limited to unpaid purchases, while manual targeting of a paid purchase remains possible.

### 2. One shared resolver, called from all four paths

A single method resolves the value for a line: given the line and its `target_purchase_id`, return the target purchase detail's `unit_price` × the settled quantity, or `null` when no target is set or the target's price cannot be resolved. Callers apply the result only when it is non-null and the line is eligible.

The alternative — repeating the calculation at each site — was rejected because the current bug is precisely that one of four sites has the logic and three do not.

### 3. Quantity semantics must be preserved, not unified

The two line shapes differ, and the difference is load-bearing:

- **Serial lines** are one physical unit each. They carry `max_nominal = $detail->unit_price` (per-unit) and **no** `quantity` key.
- **Non-serial lines** represent a whole detail. They carry `max_nominal = $detail->sub_total` (line total) and an explicit `quantity`.

The existing `(float) ($line['quantity'] ?? 1)` at line 370 is correct for both only because serial lines fall through to `1`. The shared resolver must retain this defaulting. Normalising both shapes to a single representation would inflate serial credits by the parent detail's quantity.

### 4. The target price is uncapped in the form; overflow is handled at approval

The `min($newNominal, $maxNominal)` clamp is removed. The form computes the true purchase-derived value even when it exceeds the return line's stored value.

This is safe because the approval path already handles excess. `PurchasesReturnSettlementController` increments the `SupplierCredit` by the full settlement amount, then applies only `min($itemAmount, $purchase->due_amount)` to the target purchase as a `PurchasePayment`, leaving any remainder as `remaining_amount` on the credit. Capping in the form would discard value that the downstream design is built to retain.

The same reasoning removes the need for a partial-payment special case: the approval path resets a paid or partially-paid target's payments and reinstates full `due_amount` before applying the credit.

### 5. Validation tracks the recomputed value

`rulesForLineSubmit()` currently caps `nominal` at `max_nominal`. Left alone, it would reject exactly the values this change is meant to produce. The rule becomes: cap at the purchase-derived value when the line is targeted, otherwise at `max_nominal`.

Decision 4 and this decision must ship together — either alone produces a broken form.

### 6. Recomputation is limited to DRAFT lines

Only lines in `DRAFT` status (including lines reset from `REJECTED`) are recomputed. `SUBMITTED` and `APPROVED` lines retain their stored nominal.

This follows the `$isEditable` precedent already present in the `.method` branch, and avoids retroactively altering amounts that have been through approval.

### 7. Correct by recomputation, not by migration

Because hydration recomputes, existing draft settlements are corrected when next opened. A backfill migration was considered and rejected: it would need to reproduce the same resolution logic in a second place, and would not help lines whose target is chosen after the migration ran.

### 8. Approval-time validation exempts targeted settlements from the return-line subtotal ceiling

*Amended during implementation. The original design listed the approval path as a non-goal; that proved untenable and the reasoning is recorded here.*

Approval independently re-validated every settlement against the return detail's `sub_total`. Decision 4 removes that ceiling in the form, so a targeted settlement priced from the source purchase can legitimately exceed it — and would then be rejected at approval, leaving values that can be saved but never approved. The two ceilings must move together.

The exemption is therefore keyed on `target_purchase_id` being present, and on nothing else. Targeted settlements remain bounded by the separate, pre-existing check against the target purchase's `total_amount`, so they are not unbounded — only bounded by the correct document.

The exemption deliberately does **not** consider the detail's `po_id`. That column records the return line's originating purchase, captured at return creation; it is not a target the operator selected. Keying on it would exempt untargeted settlements — the cash-refund flow for already-paid purchases — from a ceiling that still legitimately applies to them, since nothing about those lines is repriced. Note that the adjacent resolution of *which* purchase to apply a credit to does fall back to `po_id`; that fallback is pre-existing, serves a different question, and is unchanged.

Alternatives rejected: validating against `total_amount` for all settlement methods (weakens the guard on methods this change does not touch), and dropping approval-time validation entirely (removes a defence-in-depth check against directly manipulated records).

## Risks / Trade-offs

**Visible value changes on in-flight drafts** → Draft lines already reviewed by operators may show a different `Nilai Penyelesaian` after deployment. This is the intended correction, but it is unannounced from the operator's perspective. Mitigation: it is confined to `MODIFY_PURCHASE` lines with a target purchase, and the new value is the one consistent with the nota displayed beside it.

**Settlement value can now exceed the return's own recorded total** → With the cap removed, a line's credit may exceed what the return document recorded. The excess is retained as supplier credit rather than lost. Mitigation: behaviour is deliberate and matches the approval path's existing overflow design; covered by tests asserting the higher-price case.

**Serial-line inflation if the resolver mishandles quantity** → Dropping the `?? 1` default, or normalising line shapes, would multiply serial credits by the parent detail quantity. Mitigation: explicit test coverage for a serial line on a multi-quantity detail.

**Target purchase lacks a matching detail** → A target purchase may have no `purchase_details` row for the line's product, or the lookup list may be stale. The resolver returns `null` and the line keeps its existing value rather than silently becoming zero. Mitigation: the resolver never returns `0` as a "resolved" price; absence is distinct from zero.

**Upstream catalogue defect remains** → Creation-side values are still catalogue-derived, so the return document's own totals and the approval screen can still display misleading amounts even though settlement is now correct. Mitigation: recorded as explicit follow-up in the proposal; settled outcomes are unaffected.

## Context

The POS multi-payment checkout system supports splitting transactions across items from multiple business settings with different ownership. When a customer pays with multiple payment methods (e.g., Non-Cash 40K + Cash 50K), the current implementation:

1. **Allocates payments by ownership priority**: `PosCheckoutOwnershipPriorityAllocationService` computes a detailed allocation matrix showing which payment methods go to which settings
2. **Collapses allocation to flat map**: `SplitPosCheckoutPostingAdapter` reduces the per-payment detail to a simple `split_key → total_float` map
3. **Discards method-level detail**: `InlinePosCheckoutPostingAdapter` receives the full original payment array but uses only the first payment method, creating a single `SalePayment` record per sale instead of per payment method

This loses the granularity needed for accurate payment method reporting and cash reconciliation.

Additionally:
- EDC reference validation uses an overly restrictive alphanumeric-only regex on the frontend
- Gratitude modal text doesn't clearly indicate the change amount

## Goals / Non-Goals

**Goals:**
- Create one `SalePayment` record per payment method per sale, preserving granular payment method tracking
- Allocate cash to non-POS-setting products first (ownership priority), then non-cash to fill gaps
- Simplify EDC reference validation to "not empty" check only
- Display clear change amount in gratitude modal ("Total Kembalian Rp X.XXX")
- Maintain backward compatibility with single-payment checkouts
- Ensure existing tests pass (multi-payment allocation tests should verify 2+ SalePayment records)

**Non-Goals:**
- Change the allocation matrix algorithm logic in `PosCheckoutOwnershipPriorityAllocationService` beyond fixing the payment direction (cash vs non-cash)
- Modify SalePayment schema or create a new payments table
- Alter customer selection, product selection, or stock allocation logic
- Add new UI elements beyond gratitude modal text updates

## Decisions

### Decision 1: Preserve allocation matrix detail through to InlinePostingAdapter

**Chosen:** Pass per-group payment slices (array of `{payment_method_id, amount_minor_units, is_cash, reference}`) into `groupContext['payment']['payments']` before calling `InlinePostingAdapter`.

**Rationale:** The allocation matrix is already computed at the split adapter level. Rather than losing it in the collapse-to-flat-map step, we preserve method-level detail and pass it to the inline adapter. This ensures the inline adapter knows exactly which payment methods contributed to each sale.

**Alternatives considered:**
- Recompute allocations in the inline adapter — would require passing the full allocation matrix and ownership context, adding complexity to the inline adapter (currently simpler)
- Store allocation detail in a new `pos_checkout_payments` relationship — requires new schema; unnecessary given we can use the existing `SalePayment` per-method-per-sale pattern
- Query allocations from session after finalization — fragile, requires serializing and recovering complex state

### Decision 2: Loop over payment methods in InlinePostingAdapter to create multiple SalePayments

**Chosen:** When `is_multi_payment` is true, iterate over `$payment['payments']` (now group-scoped) and create one `SalePayment` per entry with its specific method and amount.

**Rationale:** `SalePayment` already exists as the granular payment record; one row per method per sale is the simplest and most auditable approach. No schema changes needed.

**Alternatives considered:**
- Create a new `pos_checkout_payments` table linking to checkout — adds schema complexity and join overhead
- Store all methods in a single `SalePayment` with JSON array in note field — loses relational structure, harder to query and report on

### Decision 3: Fix payment allocation direction in ownership-priority service

**Chosen:** Swap the allocation order for cash: cash goes to non-terminal-owned groups first, then overflow. Non-cash stays: terminal-owned first, then overflow.

**Rationale:** Matches the business rule: "cash should be fulfilled to non-POS settings first to respect their ownership." This ensures cash is not hoarded at the terminal setting when other settings' products are being sold.

**Alternatives considered:**
- Keep current logic (cash proportional, non-cash terminal-first) — violates stated business rule
- Make allocation direction configurable by setting — adds runtime config complexity without clear benefit (rule is consistent across settings)

### Decision 4: Simplify EDC reference validation

**Chosen:** Remove the regex `^[a-zA-Z0-9]{1,20}$` from frontend. Keep only "not empty" validation on both frontend and real-time checks.

**Rationale:** Business requirement is "only validate not empty, no format rules." Regex was over-engineering; different EDC systems may accept different reference formats.

**Alternatives considered:**
- Make regex configurable by payment method — adds complexity without clear requirement
- Validate format on backend only — defers user feedback, poor UX

### Decision 5: Update gratitude modal text to "Total Kembalian Rp X.XXX"

**Chosen:** Change the JavaScript that sets `#gratitude-change-amount` text from `"Kembalian: ..."` to `"Total Kembalian ..."`.

**Rationale:** User feedback indicated the wording should be clearer about total change amount.

**Alternatives considered:**
- Add additional UI elements (new paragraph, badge) — unnecessary, existing element and styling suffice

## Risks / Trade-offs

**[Risk: Multiple SalePayment records per sale]** → Some reporting queries that assume one SalePayment per Sale may break.
  - **Mitigation:** Run full test suite (existing `POSCheckoutMultiPaymentFinalizeTest` already expects 2+ SalePayment records); add assertions to verify counts.

**[Risk: Allocation matrix complexity]** → The per-group payment slice extraction in SplitAdapter is new code with many edge cases (empty payments, negative amounts, rounding).
  - **Mitigation:** Add unit tests for allocation matrix slicing; validate that per-group allocations sum to checkout grand total; use largest-remainder method (already in use) for deterministic rounding.

**[Risk: Backward compatibility]** → Single-payment checkouts must still work, must create exactly one SalePayment.
  - **Mitigation:** Keep the `is_multi_payment` flag check; single-payment path unchanged; test both paths.

**[Trade-off: Allocation complexity]** → Ownership-priority allocation is complex (non-cash to terminal first, cash to non-terminal first, overflow proportional). Could be simplified to purely proportional.
  - **Justification:** Business rule is explicit; complexity is unavoidable if we honor it; the algorithm already exists and is proven.

**[Risk: Change calculation]** → Only cash component should count toward change. Multi-payment checkout passes `total_cash_minor_units` to finalize; if non-cash overpayment exists, no change is given (correct). If calculation is wrong, could hide overpayments.
  - **Mitigation:** Test scenarios: non-cash overpayment (0 change), cash overpayment (change from cash only), mixed payment with cash overpay (change from cash after non-cash fills gap).

## Open Questions

1. Should SalePayment records be ordered by payment method or creation time when multiple exist?
   - **Recommendation:** Creation order (already preserved by database insert order); no special sorting logic needed.

2. Should EDC reference be required only for SPECIFIC non-cash methods (e.g., Bank Transfer) or ALL non-cash methods?
   - **Current behavior**: Checked via `$paymentMethod->requires_reference` flag; no change to this logic.

3. If a payment method's amount is 0 or negative in the allocation, should we skip creating a SalePayment for it?
   - **Recommendation:** Yes, skip SalePayment creation for amount ≤ 0; validate this in the slicing logic.

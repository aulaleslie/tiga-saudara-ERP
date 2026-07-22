## Context

The active POS sell screen uses `public/js/pos-staged-payment.js` as an in-browser state machine. It currently confirms each payment stage, stores staged payments in the session, and automatically calls `/pos/sell/checkout/finalize` when the remainder reaches zero. A zero-down-payment debt path also attempts to jump directly to finalization. The debt additions reused normal payment validation and form reset behavior, which creates contradictions: zero is enabled and then rejected, partial cash is required to cover the full balance, and resetting a payment stage clears the debt flag and term.

Packed pricing intentionally converts configured Rupiah prices to integer minor units in `PosCartService::buildPricingBasis()`. `PackedLinePricingService` keeps its authoritative totals and breakdown prices in minor units, and line totals are converted back to major units elsewhere. `PosReceiptService`, however, passes `box_price_applied` and `loose_price_applied` directly to `format_currency()`, producing a 100-times display error. It also renders hardcoded `[K]` and a first-letter base-unit abbreviation because the packed breakdown does not preserve both actual unit labels.

The receipt is a fixed 72 mm document with a 68 mm content area. Its tables rely on browser auto-layout and apply no non-wrapping monetary-column contract, so large values compete with labels and product names.

This is a brownfield correction. It must preserve checkout idempotency, supervisor authorization, multi-payment session recovery, split-owner posting, existing product-price storage, historical transaction snapshots, and both completed and draft receipt routes.

## Goals / Non-Goals

**Goals:**

- Require an explicit, transaction-level confirmation immediately before every checkout-finalize request.
- Treat debt as persistent checkout state and support zero or positive partial down payments below the grand total.
- Preserve the complete debt context across staged payment, approval, cancellation, retry, and finalization transitions.
- Keep packed calculation data in integer minor units while converting it exactly once at the receipt presentation boundary.
- Snapshot and print real conversion/base-unit labels for new packed lines, with safe relational fallbacks for historical snapshots.
- Make receipt monetary columns deterministic and legible on the existing 72 mm print surface.
- Cover the corrected state transitions and receipt output with focused regression tests.

**Non-Goals:**

- Changing database product/conversion prices from Rupiah to minor units or altering their schema.
- Rewriting historical POS transaction snapshots.
- Changing Sale debt collection, split-owner allocation, stock posting, tax, or session cash reconciliation rules.
- Adding a new payment or checkout endpoint.
- Redesigning the entire receipt or POS sell screen.

## Decisions

### D1: Separate payment-stage confirmation from final transaction confirmation

Introduce one finalization gateway in the staged-payment state machine. All branches that currently call `finalizeCheckout()` directly will instead prepare and display the final transaction summary; only its explicit proceed action may invoke finalization. The summary will include total, staged/paid amount, change for normal checkout or outstanding amount for debt, and debt customer/term context when applicable.

An individual payment that leaves a normal checkout balance outstanding will continue to use the existing stage-confirmation modal. A payment that completes or overpays the bill will transition to final confirmation rather than showing a redundant stage confirmation followed by automatic posting. A debt down payment will be staged and then transition to the debt final confirmation even though a remainder intentionally remains.

Alternative: add a second confirmation after every existing stage confirmation. Rejected because the final payment would require two nearly identical confirmations and encourage cashiers to click through both without reviewing them.

### D2: Represent debt mode independently from the current payment-entry form

Maintain a checkout-level debt context containing `is_debt`, `payment_term_id`, customer resolution, and the intended outstanding balance. Resetting method, amount, or reference fields for another stage MUST NOT reset this context. A full modal/cart reset after success or explicit abandonment may clear it.

Validation will branch by checkout mode:

- Normal mode retains existing method-specific rules and requires full settlement before finalization.
- Debt mode requires a named customer and payment term.
- Debt amount `0` requires no method and is eligible for final confirmation.
- Debt amount `> 0` requires a valid method/reference and must be strictly less than the grand total/outstanding amount; cash does not inherit the normal full-settlement minimum.
- A debt payment equal to or above the grand total is rejected as a mode mismatch.

After a positive debt down payment is staged, the remaining balance becomes the displayed outstanding amount and the flow proceeds to final confirmation rather than requesting further payment.

Alternative: special-case only `validateBeforeSubmit()`. Rejected because `updateStageValidation()`, post-stage reset, final payload construction, and approval retry would still disagree about debt state.

### D3: Preserve idempotency and approval semantics at the finalization gateway

The gateway will build a single canonical finalize payload from the stable payment-chain and debt context. The idempotency key is generated once per confirmation attempt and reused if the request is retried within that attempt. The existing `ApprovalManager` remains the authorization mechanism, but an approval-required response must not reset payment or debt state. After approval, the cashier must still explicitly proceed with the same visible final summary before the token-bearing finalize call.

UI locking will prevent duplicate proceed actions while finalization is in flight. Canceling final confirmation performs no backend finalize call and does not discard staged payments.

Alternative: let `ApprovalManager`'s generic “Lanjutkan Aksi?” dialog serve as final confirmation. Rejected because authorized cashiers would not see it, and it does not show the payment/debt summary.

### D4: Keep packed calculations in minor units and normalize at the receipt boundary

The existing internal representation remains unchanged:

```text
configured DB price       210000 Rupiah
pricing basis             21000000 minor units
packed breakdown          21000000 minor units
receipt presentation      210000 Rupiah
```

`PosReceiptService` will convert every packed breakdown price through one named minor-to-major helper before calling `format_currency()`. Both `getReceiptData()` and `getTransactionReceiptData()` will share the same packed-breakdown presentation builder so completed and draft receipts cannot drift.

Alternative: stop multiplying packed pricing inputs by 100. Rejected because totals, discounts, tax extraction, split allocation, and packed unit tests already rely on integer minor-unit arithmetic; changing that boundary would create a much broader accounting risk.

### D5: Snapshot both actual unit labels with packed pricing metadata

When `buildPricingBasis()` resolves the conversion used for packing, it will also resolve and carry stable display labels for the conversion unit and base unit, preferring `short_name` and falling back to `name`. `PackedLinePricingService` will copy those labels into the breakdown that is persisted in transaction line metadata. The receipt will render forms such as `1 DUS @ Rp210.000` and `1 RIM @ Rp45.000`, without brackets or synthetic initials.

For historical snapshots without labels, receipt reconstruction will use loaded conversion/product unit relationships as a fallback. If a line has no `conversion_id` because it entered through the base-product path, it may resolve the product's configured packing conversion consistently with the pricing-basis selection. A neutral full unit name such as `Unit` is the last fallback; `[K]` and derived first letters are never used. No historical row is updated.

Alternative: resolve all unit names live at print time. Rejected because later unit configuration changes would alter the meaning of newly printed historical receipts and base-entry packed lines may not retain a conversion ID.

### D6: Use an explicit fixed receipt column contract

The item table will use fixed layout with dedicated quantity and right-aligned amount columns; the product column receives the remaining width and may wrap. Totals and payment tables will use a shared monetary-cell class with `white-space: nowrap`, tabular numerals, and an explicit width. Long labels may wrap independently. A compact amount font class based on formatted length will keep unusually large values inside the printable width without removing digits or separators.

HTML-level tests will verify the semantic classes and complete formatted values. A print-oriented manual check at 72 mm remains necessary because PHPUnit cannot reproduce every printer driver's font metrics.

Alternative: globally reduce receipt font size. Rejected because it harms normal receipt legibility and does not establish which column is allowed to wrap.

## Risks / Trade-offs

- **Nested Bootstrap modals can leave backdrops or focus in an invalid state** → Use one controlled transition between staged-payment and final-confirmation surfaces, restore focus on cancel, and test modal event ordering.
- **A staged payment exists in the session when final confirmation is canceled** → Preserve it intentionally and clearly show it in the payment chain; the existing explicit checkout cancellation/chain-clear action remains the abandonment path.
- **Approval may expire while final confirmation is open** → Keep the state intact, surface the authorization error, and allow a new approval request without restaging payment.
- **Historical snapshots may not identify the exact packing unit** → Prefer snapshot labels, then the line conversion, then the same product conversion selection rule used by packed pricing; never mutate history and use a neutral full-name fallback if still unresolved.
- **Multiple conversions per product are currently ambiguous because packing selects the first conversion** → Preserve existing selection behavior in this regression change and snapshot the chosen labels; defining multi-conversion packing priority remains out of scope.
- **Very large values have physical width limits even with a compact font** → Reserve the monetary width, apply bounded compact sizing, and include representative large-value print UAT rather than truncating.
- **Frontend state behavior has limited automated infrastructure** → Factor validation and transition decisions into small deterministic functions where practical, add focused coverage using the repository's available JS test tooling or stable feature-level assertions, and document manual UAT for modal/approval transitions.

## Migration Plan

1. Deploy application and static asset changes together so the new modal markup and JavaScript state machine stay compatible.
2. No database migration or data backfill is required. New packed transaction snapshots begin carrying unit labels; old snapshots use read-time fallbacks.
3. Verify normal cash, overpayment, multi-stage, zero-debt, partial-debt, approval-required debt, draft receipt, completed receipt, and large-total print cases in staging.
4. Rollback consists of reverting the application/static asset release. Newly stored unit-label metadata is additive JSON and remains harmless to the previous reader.

## Open Questions

None blocking. Product packing still assumes one effective conversion per product; this change records the conversion chosen by the existing rule rather than redefining that domain behavior.

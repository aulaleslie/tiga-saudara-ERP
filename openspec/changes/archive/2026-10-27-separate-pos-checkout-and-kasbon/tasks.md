# Tasks

Verification scope: run only affected test files/filters and targeted visual checks. Do not run or require the full suite.

## 1. Overflow-safe allocation

- [x] 1.1 Replace oversized product arithmetic in PosCheckoutPaymentSplitService with exact overflow-safe quotient/remainder calculation, preserving ordering and ties; extend and run the focused PosCheckoutPaymentSplitServiceTest for the logged Rp52,985,000 / Rp30,000,000 case, exact shares, small/zero amounts, and rounding ties.
- [x] 1.2 Add and run a focused large-value Kasbon posting regression with groups Rp39,385,000 and Rp13,600,000; assert allocated payments total Rp30,000,000, debt totals Rp22,985,000, terms propagate, and cash events reflect actual cash received.

## 2. Explicit payment flow and authoritative reset

- [x] 2.1 Add explicit full-payment/Kasbon identity to the staged module and session chain with legacy is_debt recovery; verify focused recovery cases preserve flow and same-flow progress across close/reopen/reload.
- [x] 2.2 Reuse or add a cart/session-scoped reset operation under existing mutation locks and authorization; add focused feature tests verifying payment entries, references, evidence associations, terms, and approval metadata clear while cart/customer/notes remain and unauthorized reset fails.
- [x] 2.3 Serialize flow transitions against staging/finalization, clear recovery state, and refresh finalize identity after reset; add focused tests for reset failure and late/in-flight request handling so discarded payments cannot return.

## 3. Main actions and switching experience

- [x] 3.1 Render prominent Bayar Lunas with supporting payment-method text and the vertical save/save-print/Kasbon stack; verify focused permission rendering cases hide unauthorized actions and retain server authorization/prerequisite guards.
- [x] 3.2 Add responsive styles that collapse absent actions/columns; visually verify all-actions, partial-stack, primary-only, save-only, no-actions, and narrow-screen layouts without empty gaps or clipping.
- [x] 3.3 Route main actions and authorized in-modal switches through one transition handler with preflight diagnostics preserved; verify both entry points select the intended flow and unauthorized switches are absent.
- [x] 3.4 Add destination-specific styled confirmation for dirty inputs or staged payments and connect it to the authoritative reset; extend and run focused JavaScript cases for both directions, confirm/cancel, evidence/reference clearing, clean switching, and same-flow preservation.

## 4. Multiple Kasbon down payments

- [x] 4.1 Provide Add Payment and explicit Finish Kasbon controls, supporting zero or multiple down payments without automatically finalizing after one stage; run focused staged-payment JavaScript cases covering zero, one, and mixed-method entries.
- [x] 4.2 Enforce the combined down-payment limit and full-payment completion rules in UI and backend; add and run focused debt feature cases for mixed cash/transfer down payments, total-equal/exceed rejection, references/evidence, approval, and term propagation.

## 5. Focused integration checks

- [x] 5.1 Run the affected PHP filters/files and staged-payment JavaScript test fixture only; record commands and outcomes, confirming split sales, actual cash reconciliation, retry behavior, and the reported large-value checkout.
- [x] 5.2 Perform a targeted UI smoke check of full-payment multiple methods, Kasbon multiple down payments, switching in both directions with cancellation, and permission-adaptive layout; record results and any environment limitations without requiring a full-suite run.

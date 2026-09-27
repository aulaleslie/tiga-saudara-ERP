## Context

The POS Staged Checkout modal tracks payment stages and inputs on the client side using the `public/js/pos-staged-payment.js` module. Currently, when the `openModal` function is called, the system sets up the internal state and optionally restores payment chains, but it doesn't explicitly reset the HTML input elements. Because the modal is just hidden (`modal('hide')`) when a transaction is completed, the next transaction will display the inputs (such as payment method selection, amount, and reference number) exactly as they were left in the previous transaction.

## Goals / Non-Goals

**Goals:**
- Clear all input states (amount, method, EDC reference) when the staged checkout modal is opened for a new transaction.

**Non-Goals:**
- Modifying the payment processing logic or backend state management.
- Refactoring the entire modal lifecycle.

## Decisions

- **Decision 1:** Call `resetStageForm()` inside `openModal()`.
  - **Rationale:** `resetStageForm()` is an existing helper in `pos-staged-payment.js` that resets `selectedPaymentMethod`, clears the inputs (`stagedMethodSearchInput`, `stagedAmountInput`, `stagedEdcReferenceInput`), updates visibility, and handles validation resets. Adding this to the top of `openModal()` ensures the form is fully cleared before displaying it.

## Risks / Trade-offs

- **[Risk] Unexpected focus behavior:** `resetStageForm()` attempts to focus the search input. Since `openModal()` calls it before the modal is actually fully shown (`modal('show')`), the focus might silently fail in some browsers because the element is not visible yet.
  - **Mitigation:** This is acceptable and low-risk. The existing code handles focus gracefully. Bootstraps `.modal('show')` takes care of standard modal focus management.

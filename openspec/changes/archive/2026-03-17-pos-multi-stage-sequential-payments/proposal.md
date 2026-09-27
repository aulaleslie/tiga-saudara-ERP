## Why

Current POS payment modal requires users to select ALL payment methods upfront and enter amounts for each, then confirm everything at once. This creates friction in real-world selling scenarios where customers often pay incrementally (e.g., partial cash, remainder via card). The redesign shifts to a staged, sequential payment flow where each payment is committed immediately, the remainder is recalculated and displayed, and the user proceeds to the next payment stage—making the UI simpler and matching how actual transactions happen.

## What Changes

- **Single-stage focus**: Payment modal shows only the current payment method selection and amount input, not a pre-loaded list of multiple methods
- **Sequential commitment**: Each payment stage is submitted individually, committed to the database, and the remainder is recalculated
- **Visual payment chain**: Users see a list of payments they've already committed (e.g., "✓ BRI 1,000,000", "✓ BNI 1,000,000") with current stage clearly indicated
- **EDC reference entry for non-cash**: Non-cash payments (BRI, BNI, etc.) require manual entry of EDC receipt reference (last digits); CASH payments skip this step
- **Modal lock during processing**: While a payment stage is being processed, inputs are disabled and a progress indicator shows
- **Auto-resume on reload**: If a user reloads the browser mid-chain (e.g., after paying with BRI but before BNI), the modal reopens at the correct remainder with the payment chain visible
- **Simplified modal layout**: Removes the left-side cart summary panel; focuses the UI on remainder amount, method selection, and amount input
- **Receipt and gratitude flow**: After final payment, receipt prints in a new tab, then a modal shows "Jangan lupa ucapkan terima kasih!" with change amount displayed

## Capabilities

### New Capabilities
- `pos-multi-stage-payment-flow`: Ability to split a single transaction into multiple sequential payment stages, each committed independently with remainder tracking and visual chain display.
- `pos-payment-stage-persistence`: Ability to persist and recover a multi-stage payment chain if the browser is reloaded mid-transaction, resuming at the correct stage.
- `pos-edc-reference-capture`: Ability to capture and validate EDC receipt reference numbers for non-cash payment methods during the payment stage flow.

### Modified Capabilities
- `pos-checkout-split-posting`: Multi-stage payment flow changes how payments are posted—instead of all payments in one batch request, each stage is a separate API call with remainder recalculation. Spec requirements evolve to include stage sequencing, intermediate commits, and reload recovery.

## Impact

- **Backend APIs**: New endpoint `/pos/sell/checkout/stage-payment` for per-stage payment submission; modified transaction/payment persistence to track stage order and committed amounts
- **Frontend JavaScript**: Complete redesign of checkout modal behavior from "gather all, submit all" to "loop until remainder = 0"; new state machine for stage progression and modal lock/unlock
- **Database**: Transaction model needs to track payment stages (order, amounts, methods, timestamps) and session state for reload recovery
- **UI/UX**: Modal layout simplified; new payment chain UI; loading states during stage processing
- **Error handling**: Distinct error paths for CASH (system errors) vs. non-cash (EDC reference validation errors)

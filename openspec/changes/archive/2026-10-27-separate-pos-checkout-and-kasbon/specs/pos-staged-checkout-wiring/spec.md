## MODIFIED Requirements

### Requirement: Activate staged payment from checkout button
The "Bayar Lunas" and "Kasbon" buttons SHALL run checkout preflight validation before opening the multi-stage sequential payment modal. When cashier clicks the button, a cart-scoped token SHALL be generated (or reused if reload recovery), but the staged payment module SHALL only be opened after preflight success. The client-side preflight error handling MUST preserve structured backend failure payload (`code`, `message`, `details`) so mismatch routing can use machine-readable diagnostics.

#### Scenario: Fresh cart checkout with valid preflight
- **WHEN** user adds items to cart, clicks "Bayar Lunas" or "Kasbon", and preflight returns success
- **THEN** a new UUID token is generated (or existing token reused), staged payment modal opens with remainder = cart grand_total, and user can select first payment method

#### Scenario: Fresh cart checkout with preflight mismatch
- **WHEN** user clicks "Bayar Lunas" or "Kasbon" and preflight reports serial/stock mismatch
- **THEN** staged payment modal MUST NOT open
- **AND** POS shows mismatch dialog with actionable failing line details

#### Scenario: Structured preflight payload is retained in UI error branch
- **WHEN** preflight endpoint responds with non-2xx and body containing `details.unfulfilled_lines` or `details.invalid_lines`
- **THEN** client checkout handler MUST receive `error.details` in structured form
- **AND** mismatch dialog path MUST be selected instead of generic validation alert fallback

#### Scenario: Reload during incomplete payment
- **WHEN** user has committed 1+ payment stages and refreshes the page
- **THEN** the modal auto-opens at the next stage with full payment chain visible and remainder updated

#### Scenario: All payments complete
- **WHEN** remainder becomes 0 (or negative for overpayment) and checkout finalize succeeds
- **THEN** staged modal hides and gratitude modal shows with "Lanjut Jualan" button

## ADDED Requirements

### Requirement: Payment flow identity SHALL be explicit
The selected full-payment or Kasbon flow SHALL persist through staging, confirmation, and recovery. Authorized users SHALL be able to switch via the main actions or inside the modal. Reopening the same flow SHALL preserve progress.

#### Scenario: Reopen or reload same flow
- **WHEN** a cashier closes and reopens the active flow or reloads with a recoverable chain
- **THEN** the selected flow and its payment progress are restored without triggering a switch reset

### Requirement: Switching flows SHALL confirm clearing payment progress
Switching in either direction with entered or staged payment progress SHALL show a styled confirmation describing the payment reset. Cancelling SHALL preserve all state. Confirming SHALL clear payment entries, pending amounts, references, evidence, terms, and checkout approval before opening the destination. Cart, customer, and transaction notes SHALL remain intact.

#### Scenario: Confirm full payment to Kasbon
- **WHEN** a cashier confirms switching from full payment with multiple entered payments to Kasbon
- **THEN** all payment-specific browser and server state is cleared and Kasbon opens fresh
- **AND** no old payment reappears after reload or finalization

#### Scenario: Confirm Kasbon to full payment
- **WHEN** a cashier confirms switching with one or more Kasbon down payments
- **THEN** all down payments, references, evidence, selected term, and checkout approval are cleared before full payment opens

#### Scenario: Cancel switch
- **WHEN** a cashier cancels the styled confirmation
- **THEN** the current flow and all its progress remain unchanged

#### Scenario: Switch without payment progress
- **WHEN** a cashier switches without entered or staged payments
- **THEN** the destination opens without a clearing confirmation and obsolete payment terms and checkout approval are reset

#### Scenario: Reset fails or a payment request is running
- **WHEN** server reset fails or staging/finalization is in progress
- **THEN** the UI prevents entering the destination with stale state and provides an actionable error or waits for the operation to finish

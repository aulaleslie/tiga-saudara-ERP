# pos-payment-stage-persistence Specification

## Purpose
TBD - created by archiving change pos-multi-stage-sequential-payments. Update Purpose after archive.
## Requirements
### Requirement: Session State Preservation of Payment Chain
When a payment stage is committed via the new endpoint, the backend SHALL store the payment chain state (all committed payments so far, current remainder, transaction ID, and stage number) in the user's Laravel session keyed by transaction ID. This state SHALL survive page reload and browser navigation within the same session.

#### Scenario: Session stores first payment commit
- **WHEN** user commits first payment (BRI 1,000,000) in a transaction
- **THEN** backend stores in session: { transactionId, stage: 1, committedPayments: [{method: "BRI", amount: 1000000}], remainder: 1950000 }

#### Scenario: Session accumulates subsequent payments
- **WHEN** user commits second payment (BNI 1,000,000)
- **THEN** session updates to include both payments: committedPayments: [{BRI, 1M}, {BNI, 1M}], remainder: 950000, stage: 2

### Requirement: Automatic Modal Recovery on Page Reload
When a user reloads the page while a payment chain is in-progress (remainder > 0), JavaScript on page load SHALL detect the in-progress transaction from session state and automatically reopen the payment modal at the correct stage, displaying all previously committed payments and the current remainder.

#### Scenario: User reloads after first payment
- **WHEN** user reloads browser after committing BRI payment (remainder: 1,950,000)
- **THEN** page loads, JS detects in-progress transaction, modal automatically opens showing: payment chain "✓ BRI 1,000,000" and remainder "1,950,000" with empty method selector ready for next stage

#### Scenario: User reloads after multiple payments
- **WHEN** user reloads after committing BRI 1,000,000 and BNI 1,000,000 (remainder: 950,000)
- **THEN** modal opens with full chain displayed: "✓ BRI 1,000,000", "✓ BNI 1,000,000", remainder: 950,000

### Requirement: Session Timeout Handling
If a user's session expires during a payment chain (remainder > 0), the system SHALL clear the in-progress state from session, and on next page load, the modal SHALL NOT automatically open. Instead, a warning message SHALL be displayed to the user indicating the transaction was lost, and the user must restart the checkout process.

#### Scenario: Session expires mid-transaction
- **WHEN** session timeout occurs while user has committed 1 payment with remainder > 0
- **THEN** on page reload, session state is cleared, no modal opens automatically, user sees message: "Sesi Anda telah berakhir. Silakan mulai checkout ulang."

#### Scenario: User is on fresh page after session expiry
- **WHEN** user reloads page and session has expired
- **THEN** main POS view loads without modal; previous transaction state is not recovered

### Requirement: Idempotent Stage Payment Submission
Each stage payment request SHALL include a unique idempotency key (e.g., `transaction_id:stage:timestamp:hash`). If the same request is submitted twice, the backend SHALL return the same response without creating duplicate payment records.

#### Scenario: Accidental double-submit of same payment stage
- **WHEN** network lag causes user to click [Proceed] twice on same form
- **THEN** first request is processed, second request with same idempotency key returns cached response; no duplicate payment is created

#### Scenario: Retry after network error uses same idempotency key
- **WHEN** first submission fails with network timeout, user clicks [Proceed] again
- **THEN** second submission uses same idempotency key, backend detects and returns cached response if first succeeded

### Requirement: Reload Recovery Does Not Re-Process Payments
When modal is recovered after page reload, the displayed committed payments SHALL be sourced from session state (what the backend already recorded), NOT by re-querying the API. This prevents accidental re-posting or double-checking.

#### Scenario: Committed payments are shown from session, not re-fetched
- **WHEN** user reloads and modal reopens with 2 committed payments in chain
- **THEN** payment chain is rendered from session state directly; no API call is made to re-fetch payment status

### Requirement: Staged-payment modal dismissal SHALL preserve recoverable state
Ordinary staged-payment modal dismissal controls, including the header × and footer **Batal**, SHALL close the modal without deleting or resetting the in-progress session payment chain. All ordinary dismissal controls SHALL have consistent behavior.

#### Scenario: Header close preserves an in-progress chain
- **WHEN** a cashier clicks the header × while the staged-payment modal has an in-progress payment chain and no request is processing
- **THEN** the modal MUST close
- **AND** the session payment chain MUST remain unchanged
- **AND** reopening or reloading MUST recover the committed payment stages and remainder

#### Scenario: Footer cancel preserves an in-progress chain
- **WHEN** a cashier clicks **Batal** while the staged-payment modal has an in-progress payment chain and no request is processing
- **THEN** the modal MUST close with the same state-preservation behavior as the header ×

#### Scenario: Dismissal is unavailable during processing
- **WHEN** a staged-payment submission or checkout finalization is processing
- **THEN** every ordinary dismissal control MUST be unavailable until processing ends

### Requirement: Payment-chain reset SHALL require an explicit destructive action
The POS SHALL expose payment-chain deletion only through a clearly labelled destructive action that is distinct from ordinary modal dismissal and requires cashier confirmation. The client SHALL update local state only after the server confirms the reset.

#### Scenario: Cashier confirms explicit payment-chain reset
- **WHEN** a cashier invokes the explicit payment-chain reset action and confirms the warning
- **THEN** the POS MUST delete the matching session payment chain
- **AND** clear the matching local staged-payment and debt state
- **AND** close the staged-payment modal

#### Scenario: Cashier declines explicit payment-chain reset
- **WHEN** a cashier invokes the explicit payment-chain reset action but declines confirmation
- **THEN** the POS MUST keep the modal open
- **AND** MUST leave both the session payment chain and local staged-payment state unchanged

#### Scenario: Payment-chain reset fails
- **WHEN** the confirmed payment-chain reset request fails
- **THEN** the POS MUST keep the current modal and local state intact
- **AND** display an actionable reset error

#### Scenario: Reset is unavailable during processing
- **WHEN** a staged-payment submission or checkout finalization is processing
- **THEN** the explicit payment-chain reset action MUST be unavailable until processing ends


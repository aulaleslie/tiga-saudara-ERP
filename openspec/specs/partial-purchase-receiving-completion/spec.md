# Partial Purchase Receiving Completion Specification

## Purpose

When a supplier cannot deliver all ordered items, the system SHALL allow authorized users to complete a purchase with an approved subset of received quantities while permanently removing undelivered lines. This capability enables graceful handling of supplier shortfalls without forcing manual data cleanup or blocking payment settlement on partial receipt.
## Requirements
### Requirement: Authorized users can complete a supplier-shortfall purchase
The system SHALL allow only users with `purchases.receive.complete_shortfall` to complete a purchase in the active setting when it is unarchived, has status `RECEIVED PARTIALLY`, has at least one approved received quantity, has an outstanding ordered quantity, and has no pending receiving notes.

#### Scenario: Authorized user completes an eligible partial purchase
- **WHEN** an authorized user submits a valid shortfall completion for an eligible purchase
- **THEN** the system SHALL complete the purchase atomically
- **AND** the purchase status SHALL become `RECEIVED`

#### Scenario: User without completion permission is denied
- **WHEN** a user without `purchases.receive.complete_shortfall` requests the completion preview or submits completion
- **THEN** the system SHALL deny the request
- **AND** the purchase, details, payments, and receiving records SHALL remain unchanged

#### Scenario: Ineligible purchase cannot be completed
- **WHEN** a user attempts completion for an archived, foreign-setting, non-partial, no-approved-receipt, fully fulfilled, or pending-receipt purchase
- **THEN** the system SHALL reject the operation
- **AND** it SHALL not change the purchase status or financial data

### Requirement: Completion requires a reviewed supplier-shortfall reason
The system SHALL display a purchase-level preview of original ordered quantities, cumulative approved received quantities, retained lines, removed lines, and financial before/after values, and SHALL require a non-empty supplier-shortfall reason before finalization.

#### Scenario: Shortfall preview identifies retained and removed lines
- **WHEN** an authorized user opens completion for an eligible purchase with product A ordered 10/received 5 and product B ordered 10/received 0
- **THEN** the preview SHALL show product A as retained with final quantity 5
- **AND** the preview SHALL show product B as removed

#### Scenario: Missing reason blocks completion
- **WHEN** an authorized user submits completion without a shortfall reason
- **THEN** validation SHALL fail
- **AND** no document, payment, or audit change SHALL be persisted

### Requirement: Completion normalizes the purchase from approved receipt quantities
The system SHALL calculate cumulative receipt quantities using only `APPROVED` received notes. It SHALL retain each purchase-detail row with a positive approved quantity and update its quantity in place to that quantity, and SHALL remove each purchase-detail row with zero approved quantity only when it has no receipt-detail history.

#### Scenario: One product is short-delivered
- **WHEN** a purchase detail ordered quantity is 10 and its cumulative approved receipt quantity is 5 at successful completion
- **THEN** the existing purchase-detail row SHALL remain linked to its receiving history
- **AND** its final ordered quantity SHALL be 5

#### Scenario: One product is never delivered
- **WHEN** a purchase detail ordered quantity is 10 has no approved received quantity and no receipt-detail history at successful completion
- **THEN** the system SHALL remove that purchase-detail row
- **AND** it SHALL retain the line's original and removal result in the completion audit

#### Scenario: Rejected and pending notes do not count as received
- **WHEN** a purchase has rejected receiving notes and no pending notes
- **THEN** rejected note quantities SHALL not contribute to final quantities
- **AND** when a purchase has a pending receiving note completion SHALL be blocked

### Requirement: Post-completion purchase list refreshes without manual reload
The system SHALL refresh the purchase list table immediately after a successful shortfall-completion without requiring a manual page reload.

#### Scenario: Table reflects completion after submission
- **WHEN** a user successfully completes a shortfall purchase and the submission response is received
- **THEN** the purchase table SHALL re-fetch and re-render its results
- **AND** the completed purchase SHALL appear in its new status (`RECEIVED`) and updated totals

### Requirement: Completion feedback displays with modal closed
The system SHALL display a non-intrusive success message after completion, positioned outside the modal so it remains visible after the modal closes, and dismissible by the user.

#### Scenario: Success message displays after modal close
- **WHEN** a user successfully completes a shortfall purchase and the modal closes
- **THEN** a success alert SHALL display prominently in the page area outside the modal
- **AND** the alert SHALL persist until the next modal open or user dismissal
- **AND** the message SHALL read "Penerimaan berhasil diselesaikan."

### Requirement: Completion keeps financial and audit data consistent
The system SHALL recalculate line and header monetary values using the purchase normalization rules, derive paid amount, due amount, and payment status from active payments, and persist an immutable completion audit record in the same database transaction. For every retained PKP purchase-detail row, the system SHALL preserve its persisted tax identity and proportionally recalculate its persisted subtotal, pre-tax subtotal, and product tax amount according to the approved received quantity over the original ordered quantity. The completion preview SHALL use the same final monetary reconstruction as the completion transaction. A non-PKP purchase SHALL continue to persist no tax data after normalization.

#### Scenario: Final document total reflects only accepted goods
- **WHEN** a partial purchase is completed after its lines are normalized
- **THEN** its line values, tax, discount, shipping, total amount, and due amount SHALL be recalculated from the retained final lines
- **AND** the global purchase payment workflow SHALL treat it as an eligible exact-`RECEIVED` purchase when it has a positive live balance

#### Scenario: PKP retained line preserves proportional tax
- **WHEN** a PKP purchase detail with ordered quantity 10, persisted subtotal 11100, persisted product tax amount 1100, and a tax ID has approved received quantity 5
- **THEN** the final retained detail SHALL have quantity 5, the same tax ID, subtotal 5550, and product tax amount 550
- **AND** the completed purchase header tax amount SHALL include 550 for that detail

#### Scenario: PKP preview matches persisted completion amounts
- **WHEN** an authorized user previews and then completes an eligible PKP supplier-shortfall purchase without a source-document change
- **THEN** the preview tax and total amounts SHALL equal the resulting persisted purchase header amounts
- **AND** each retained line's persisted tax amount SHALL equal its previewed proportional result

#### Scenario: Tax-included PKP line retains its original tax composition
- **WHEN** a partially received PKP purchase has a tax-included retained line with persisted subtotal and product tax amount
- **THEN** completion SHALL proportionally retain that stored tax composition for the approved quantity
- **AND** it SHALL NOT reprice the line or resolve tax using a current tax-master value

#### Scenario: Non-PKP completion remains untaxed
- **WHEN** an eligible partially received purchase belongs to a non-PKP setting
- **THEN** completion SHALL persist null line/header tax IDs and zero line/header tax amounts

#### Scenario: Existing payment overage blocks completion
- **WHEN** active purchase payments exceed the normalized document total
- **THEN** the system SHALL reject completion
- **AND** it SHALL preserve all purchase, payment, receipt, and audit data

#### Scenario: Audit records the finalization decision
- **WHEN** completion succeeds
- **THEN** the system SHALL store the purchase and setting, actor, timestamp, required reason, source line quantities, approved receipt totals, final line outcomes, and financial before/after values

### Requirement: Shortfall completion closes future receiving
The system SHALL reject new receiving submissions and approval of late pending receiving notes once a purchase has been completed as a supplier shortfall.

#### Scenario: User attempts new receipt after completion
- **WHEN** a user submits a new receiving for a purchase completed as a supplier shortfall
- **THEN** the system SHALL reject the submission
- **AND** it SHALL not create a received note or change stock

#### Scenario: Concurrent completion and approval are serialized
- **WHEN** a receiving approval and shortfall completion are attempted concurrently for the same purchase
- **THEN** the system SHALL lock and revalidate the purchase lifecycle data
- **AND** at most one operation SHALL commit against the current state

### Requirement: Completion outcome is reflected immediately in the workspace
The system SHALL, upon successful shortfall completion, refresh the purchase listing visible behind the completion modal so the purchase's updated status, quantities, and available actions appear without a manual page reload, and SHALL show a success confirmation using the application's standard non-blocking feedback pattern. The completion modal and all its controls SHALL render using styles supported by the application's loaded CSS framework, including a functional visible close control and dismissible error alerts.

#### Scenario: List refreshes after completion
- **WHEN** an authorized user completes a shortfall from a purchase list and the completion succeeds
- **THEN** the modal SHALL close
- **AND** the visible list SHALL refresh to show the purchase as `RECEIVED` with completion-appropriate actions
- **AND** a success confirmation SHALL be visible without reloading the page

#### Scenario: Modal controls are functional and styled
- **WHEN** an authorized user opens the completion modal
- **THEN** the modal close control SHALL be visible and dismiss the modal
- **AND** any error alert SHALL be dismissible and styled consistently with the application


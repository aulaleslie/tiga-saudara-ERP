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

# Spec Delta

## Purpose

Allow staff to recover abandoned loaded POS transactions as saved drafts so they can be loaded again without editing the database directly.

## ADDED Requirements

### Requirement: Loaded transactions can be manually returned to draft

The system SHALL provide **Kembalikan ke Draf** in the POS transaction list for LOADED transactions to users with transaction-view and transaction-load authority. Recovery SHALL require the transaction to belong to the active business and the POS transaction feature to be enabled. Recovery SHALL NOT require an active POS session.

#### Scenario: Recover a loaded transaction
- **WHEN** an authorized user resets a LOADED transaction in the active business
- **THEN** its status SHALL become DRAFT
- **AND** the system SHALL report success

#### Scenario: Recover without an active POS session
- **WHEN** an otherwise authorized user without an active POS session resets an eligible LOADED transaction
- **THEN** recovery SHALL succeed
- **AND** loading it afterward SHALL still require the normal load prerequisites

#### Scenario: User lacks load authority
- **WHEN** a user without transaction-load authority views the list or submits a recovery request
- **THEN** the recovery control SHALL be hidden for that user
- **AND** a direct recovery request SHALL be rejected without changing the transaction

#### Scenario: Transaction belongs to another business
- **WHEN** a user requests recovery of a transaction outside the active business
- **THEN** recovery SHALL be rejected without changing the transaction

#### Scenario: Transaction feature is disabled
- **WHEN** recovery is requested while POS transactions are disabled for the active business
- **THEN** recovery SHALL be rejected without changing the transaction

### Requirement: Recovery preserves the saved transaction and supports normal loading

Recovery SHALL preserve the transaction identity, number, saved lines, totals, customer, note, ownership, and saved snapshot integrity data. Recovery SHALL change only the lifecycle status and normal update metadata, without posting sales, payments, or inventory movements. The resulting draft SHALL be eligible for the existing Muat workflow subject to its existing validation rules. Recovery SHALL NOT capture unsaved edits or invalidate an old browser cart.

#### Scenario: Reload the recovered draft
- **WHEN** an authorized user recovers a valid saved LOADED transaction and then uses Muat with an empty cart and an active POS session
- **THEN** the same transaction SHALL load with its last saved contents and number
- **AND** its status SHALL become LOADED through the existing workflow

#### Scenario: Old cart contains unsaved edits
- **WHEN** recovery succeeds for a transaction whose abandoned cart contains unsaved edits
- **THEN** the saved draft SHALL retain its last persisted contents
- **AND** recovery SHALL NOT save or clear that abandoned cart

### Requirement: Recovery rejects transactions that are no longer loaded

The system SHALL check current eligibility when processing recovery and reject any transaction whose status is not LOADED. The list SHALL NOT show recovery controls for DRAFT, COMPLETED, or CANCELLED transactions.

#### Scenario: Ineligible status or repeated request
- **WHEN** recovery is requested for a DRAFT, COMPLETED, or CANCELLED transaction, including a second request after successful recovery
- **THEN** the system SHALL return a validation failure
- **AND** the transaction and its saved contents SHALL remain unchanged

### Requirement: Recovery action reports its result in the list

The list SHALL prevent duplicate submission while a recovery request is pending, refresh using current filters after success, and show a visible Indonesian error message after failure.

#### Scenario: Successful recovery with all statuses visible
- **WHEN** recovery succeeds in a list showing all statuses
- **THEN** the refreshed row SHALL show DRAFT with the existing Muat action
- **AND** the recovery action SHALL disappear

#### Scenario: Successful recovery under a loaded-only filter
- **WHEN** recovery succeeds with the LOADED status filter selected
- **THEN** the refresh SHALL preserve that filter
- **AND** the recovered transaction SHALL no longer appear in those filtered results

#### Scenario: Recovery request fails
- **WHEN** a recovery request fails
- **THEN** the list SHALL display the failure and allow further interaction after the request finishes
- **AND** it SHALL NOT report a successful reset

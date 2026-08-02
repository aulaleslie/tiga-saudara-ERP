# privileged-received-purchase-corrections Specification

## Purpose

Enable privileged users to correct monetary values in received purchases while preserving inventory identity, applying automatic or explicit payment adjustments, and optionally replaying historical cost impact on products and sales.
## Requirements
### Requirement: Privileged users can correct received purchase monetary data
The system SHALL allow only Super Admin or a user with `purchases.received.correct` to start and save a correction for a purchase in `RECEIVED` or `RECEIVED PARTIALLY` status within the active setting.

#### Scenario: Authorized user opens a received purchase correction
- **WHEN** an authorized user opens an eligible received purchase in the active setting
- **THEN** the system SHALL present the supported monetary correction action and current purchase values

#### Scenario: Unauthorized user is denied
- **WHEN** a user without the correction authority attempts to open or submit a received-purchase correction
- **THEN** the system SHALL deny the action at the backend and SHALL not change the purchase

### Requirement: Corrections preserve received inventory identity and require audit reason
The system SHALL update permitted existing monetary fields in place and SHALL require a non-empty correction reason, without changing product identity, ordered or received quantity, supplier, purchase date, receipt location, receipt-note links, serial links, or stock movement quantities.

#### Scenario: Correcting a received line price preserves its detail identity
- **WHEN** an authorized user saves a corrected price for an existing received purchase line
- **THEN** the system SHALL retain the existing purchase-detail ID and receiving-note detail links
- **AND** the system SHALL not create a replacement purchase-detail row

#### Scenario: Correction audit captures before and after values
- **WHEN** an authorized user successfully saves a correction
- **THEN** the system SHALL persist an immutable audit record containing actor, timestamp, reason, affected fields, and before/after values

### Requirement: Purchase totals and active-payment summaries remain consistent
The system SHALL recompute corrected document totals and derive paid amount, due amount, and payment status from active purchase payments atomically with the correction.

#### Scenario: One active payment is adjusted automatically
- **WHEN** a corrected purchase has exactly one active purchase payment
- **THEN** the system SHALL set that payment amount to the corrected document total
- **AND** the correction audit SHALL record the payment amount before and after the change

#### Scenario: Multiple active payments require an explicit selected payment
- **WHEN** a corrected purchase has more than one active purchase payment
- **THEN** the system SHALL require the user to select one active payment and review its before/after amount before saving
- **AND** the system SHALL leave non-selected active payments unchanged

#### Scenario: Unsupported overpayment is blocked
- **WHEN** a correction would leave active payment total above the corrected document total or make the selected payment negative
- **THEN** the system SHALL reject the correction and preserve all existing document and payment values

### Requirement: Cost recalculation is a separate intentional action
The system SHALL not modify purchase average prices or sale cost snapshots when a correction is saved. It SHALL provide an authorized explicit recalculation action for the affected products from the earliest affected approved receipt, with a preview of affected purchase-price and sale-snapshot records.

#### Scenario: Saving correction does not change HPP
- **WHEN** an authorized user saves a received-purchase correction
- **THEN** the system SHALL persist the document correction and audit record
- **AND** the system SHALL not change product average purchase price or later sale cost snapshots

#### Scenario: Operator elects downstream HPP replay
- **WHEN** an authorized user confirms a previewed correction cost recalculation with downstream sale replay enabled
- **THEN** the system SHALL replay affected cost history from the earliest impacted approved receipt through later eligible sales
- **AND** the system SHALL record the recalculation actor, scope, result counts, and correction linkage

### Requirement: Correction form follows application UI conventions
The system SHALL present the received-purchase correction form using the application's standard layout, form, and button styling, and SHALL communicate validation results, preview errors, and submission outcomes through the application's standard non-blocking feedback patterns rather than blocking browser dialogs.

#### Scenario: Correction page renders consistently
- **WHEN** a privileged user opens the correction form for a received purchase
- **THEN** the page's cards, inputs, selects, and buttons SHALL render with the application's standard styling as loaded by the application's CSS framework

#### Scenario: Submission feedback is non-blocking
- **WHEN** a correction submission succeeds or fails
- **THEN** the outcome SHALL be shown through the application's standard flash or inline alert pattern
- **AND** no blocking browser dialog SHALL be used

### Requirement: Correction preview requests are debounced
The system SHALL debounce payment-preview recalculation triggered by continuous typing in correction inputs so that a burst of keystrokes results in a bounded number of preview requests, while explicit selection changes refresh the preview promptly.

#### Scenario: Typing in a monetary field
- **WHEN** a privileged user types a multi-digit value into a correction input
- **THEN** the system SHALL not issue a preview request per keystroke
- **AND** a preview request SHALL be issued shortly after typing pauses

#### Scenario: Changing the selected payment
- **WHEN** a privileged user changes the payment selected for correction
- **THEN** the preview SHALL refresh promptly for the newly selected payment


## ADDED Requirements

### Requirement: purchase-receiving-item-notes
The system MUST allow users to attach optional notes to each individual product row during the purchase receiving process. These notes MUST be persisted and displayed alongside the receiving details.

#### Scenario: saving notes during receiving
- **WHEN** a user enters a note for a product row in the purchase receiving form and submits it
- **THEN** the note is saved and associated with that specific receiving item detail

#### Scenario: displaying notes in purchase details
- **WHEN** a user views the details of a purchase and expands the receiving history
- **THEN** the per-item notes are displayed in the product list table

### Requirement: purchase-receiving-form-resilience
The purchase receiving form MUST prevent empty submissions and handle submission states gracefully to avoid intermittent errors.

#### Scenario: preventing empty save
- **WHEN** a user hits the save button on the purchase receiving form without inputting any quantities or serials
- **THEN** the system prevents submission and provides immediate feedback to the user

### Requirement: Approved receiving details retain their inventory transaction provenance
The system SHALL store a durable one-to-one link from an approved receiving detail to the `BUY` inventory transaction created when that detail is approved.

#### Scenario: New receiving approval creates provenance link
- **WHEN** the system approves a receiving detail and creates its `BUY` inventory transaction
- **THEN** the receiving detail and transaction SHALL be linked persistently

#### Scenario: Existing receipt has no durable transaction link
- **WHEN** a legacy approved receiving detail has no persisted transaction link
- **THEN** the system SHALL permit a feature that needs the link to attempt conservative evidence-based resolution
- **AND** the system SHALL refuse a mutation when the result is absent or ambiguous

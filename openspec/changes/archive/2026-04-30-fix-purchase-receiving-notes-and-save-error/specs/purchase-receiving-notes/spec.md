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

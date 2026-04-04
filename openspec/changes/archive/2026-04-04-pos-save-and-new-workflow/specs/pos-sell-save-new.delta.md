# pos-sell-save-new (Delta)

## ADDED Requirements

### Requirement: Success Dialog and TRX Number Display
After a successful "Simpan dan Buka Baru" action, the system SHALL display a confirmation dialog showing the unique POS TRX number generated for the draft transaction.

#### Scenario: Displaying TRX number after save
- **WHEN** the "Simpan dan Buka Baru" button is clicked and the transaction is successfully saved server-side
- **THEN** a success modal MUST appear
- **AND** the modal MUST display the transaction code (e.g., "TRX-20260404-0001")
- **AND** the cart MUST be cleared in the background

### Requirement: Save-and-New Modal Action Buttons
The save-and-new success modal SHALL provide two clear actions for the user to continue their workflow.

#### Scenario: Lanjut (Continue) action
- **WHEN** the "Lanjut" button in the success modal is clicked
- **THEN** the modal MUST close
- **AND** the POS shell MUST be ready for the next customer (cart cleared, search focused)

#### Scenario: Cetak Struk (Print Receipt) action
- **WHEN** the "Cetak Struk" button in the success modal is clicked
- **THEN** the system MUST open a new tab/window for the draft receipt of the specific transaction
- **AND** the modal SHOULD remain open or close based on user preference (closing is default)

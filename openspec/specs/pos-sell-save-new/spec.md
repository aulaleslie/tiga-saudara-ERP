## ADDED Requirements

### Requirement: Synchronized POS Save & Open New Activation
The "Simpan dan Buka Baru" button on the POS sell page SHALL be enabled only when all transaction validation rules are met, matching the behavior of the "Pilih Pembayaran" button, and SHALL only be actionable by users with POS shell access and draft-save permission for handoff flow. The supported `cashier` and `floor staff` bundles SHALL both satisfy this handoff requirement, while payment authority SHALL remain a separate capability.

#### Scenario: Button is disabled on empty cart
- **WHEN** the POS cart is empty
- **THEN** the "Simpan dan Buka Baru" button MUST be disabled

#### Scenario: Button is disabled when prices are invalid
- **WHEN** any item in the cart has an invalid price (e.g., below minimum)
- **THEN** the "Simpan dan Buka Baru" button MUST be disabled

#### Scenario: Button is disabled when serial numbers are missing
- **WHEN** an item requiring serial numbers does not have the required count of serials assigned
- **THEN** the "Simpan dan Buka Baru" button MUST be disabled

#### Scenario: Button is disabled when no customer is selected
- **WHEN** no customer is selected and no default customer is resolved
- **THEN** the "Simpan dan Buka Baru" button MUST be disabled

#### Scenario: Button is enabled when all conditions are met
- **WHEN** there are items in the cart, total > 0, customer is resolved, prices are valid, and all required serials are assigned
- **THEN** the "Simpan dan Buka Baru" button MUST be enabled

#### Scenario: Button is unavailable without shell or save-draft authority
- **WHEN** the logged-in user lacks POS shell access or lacks permission to save POS draft handoff transactions
- **THEN** the "Simpan dan Buka Baru" control MUST be hidden or disabled
- **AND** submission MUST be rejected server-side

#### Scenario: Cashier and floor staff can trigger valid handoff save
- **WHEN** a user in the supported `cashier` or `floor staff` bundle has shell access, save-draft permission, and all validation rules pass
- **THEN** the system MUST allow "Simpan dan Buka Baru" to persist the draft and clear the cart for the next customer

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

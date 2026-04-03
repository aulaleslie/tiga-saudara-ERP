## MODIFIED Requirements

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

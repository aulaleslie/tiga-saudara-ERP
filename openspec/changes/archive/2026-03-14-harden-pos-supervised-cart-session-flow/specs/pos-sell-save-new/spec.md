## MODIFIED Requirements

### Requirement: Synchronized POS Save & Open New Activation
The "Simpan dan Buka Baru" button on the POS sell page SHALL be enabled only when all transaction validation rules are met, matching the behavior of the "Pilih Pembayaran" button, and SHALL only be actionable by users with draft-save permission for handoff flow.

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

#### Scenario: Button is unavailable without save-draft permission
- **WHEN** the logged-in user lacks permission to create POS draft handoff transactions
- **THEN** the "Simpan dan Buka Baru" control MUST be hidden or disabled and submission MUST be rejected server-side

#### Scenario: Floor and cashier roles can trigger valid handoff save
- **WHEN** Floor Staff or Cashier Staff user has save-draft permission and validation rules pass
- **THEN** the system MUST allow "Simpan dan Buka Baru" to persist draft and clear cart for next customer

## MODIFIED Requirements

### Requirement: Synchronized POS Save & Open New Activation
The "Simpan dan Buka Baru" button on the POS sell page SHALL be enabled only when all transaction validation rules are met, matching the behavior of the "Pilih Pembayaran" button. Draft handoff access SHALL be determined by POS shell access plus `pos.transactions.save`, and SHALL NOT require named Floor Staff or Cashier roles. Lack of `pos.checkout.payment` MUST NOT disable draft handoff for an otherwise authorized POS-shell user.

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
- **WHEN** the logged-in user lacks `pos.transactions.save`
- **THEN** the "Simpan dan Buka Baru" control MUST be hidden or disabled
- **AND** submission MUST be rejected server-side

#### Scenario: Authorized POS-shell user can save draft without checkout permission
- **WHEN** the logged-in user has `pos.sell` and `pos.transactions.save`
- **AND** the logged-in user does not have `pos.checkout.payment`
- **AND** transaction validation rules pass
- **THEN** the system MUST allow "Simpan dan Buka Baru" to persist the draft and clear the cart for the next customer

## ADDED Requirements

### Requirement: Bundle parent add flows SHALL collect bundle intent before cart-line targeting
The POS sell flow SHALL require every add action for a bundle-parent product to collect bundle intent before choosing an existing cart row or creating a new row. Bundle intent MUST be one of: selected bundle id, or explicit no-bundle continuation. This requirement applies to serial-tracked and non-serial products.

#### Scenario: Serial scan asks bundle choice before appending to existing row
- **WHEN** the cashier scans a serial number for a product that is a bundle parent and the cart already contains a row for the same parent product with a selected bundle
- **THEN** the POS shell MUST present bundle selection or explicit no-bundle continuation before appending the scanned serial
- **AND** the scanned serial MUST be appended only to the row matching the cashier's chosen bundle intent

#### Scenario: Non-serial scan asks bundle choice before incrementing existing row
- **WHEN** the cashier scans or adds a non-serial product that is a bundle parent and the cart already contains a row for the same parent product with a selected bundle
- **THEN** the POS shell MUST present bundle selection or explicit no-bundle continuation before incrementing quantity
- **AND** quantity MUST increase only on the row matching the cashier's chosen bundle intent

#### Scenario: Different bundle choice creates or targets different row
- **WHEN** the cart contains Product A with Bundle A and the cashier adds Product A again but chooses Bundle B
- **THEN** the POS cart MUST keep Product A with Bundle B separate from Product A with Bundle A

#### Scenario: No-bundle choice creates or targets normal row
- **WHEN** the cart contains Product A with a selected bundle and the cashier adds Product A again but chooses to continue without a bundle
- **THEN** the POS cart MUST create or target a normal Product A row without selected bundle metadata
- **AND** the normal row MUST NOT merge into the selected-bundle row

## ADDED Requirements

### Requirement: Daizu product purchase import ownership
The purchase importer SHALL resolve rows whose product name contains `KEDELE`, `KEDELAI`, or `RAGI` to the `Daizu Kedelai` setting for purchase document ownership.

#### Scenario: Untagged kedelai purchase row creates Daizu purchase
- **WHEN** a purchase CSV row has an empty `Tag` and product name `KEDELE IMPORT`
- **THEN** the created purchase MUST have `setting_id` for `Daizu Kedelai`

#### Scenario: Existing tag does not override Daizu product ownership
- **WHEN** a purchase CSV row has product name containing `RAGI` and a `Tag` mapped to another setting
- **THEN** the created purchase MUST still have `setting_id` for `Daizu Kedelai`

#### Scenario: Product marker does not override Daizu product ownership
- **WHEN** a purchase CSV row has product name containing `KEDELAI` and also uses a generic import marker
- **THEN** the created purchase MUST still have `setting_id` for `Daizu Kedelai`

### Requirement: Daizu product stock ownership alignment
The purchase importer SHALL resolve stock movement ownership for Daizu-matched product rows to the `Daizu Kedelai` setting and its stock location, bypassing historical transaction fallback.

#### Scenario: Daizu purchase row creates Daizu stock movement
- **WHEN** a purchase CSV row has product name containing `KEDELE`
- **THEN** the inventory transaction for that row MUST have `setting_id` for `Daizu Kedelai`
- **AND** the product stock quantity MUST be incremented at a location owned by `Daizu Kedelai`

#### Scenario: Historical purchase owner is ignored for Daizu products
- **WHEN** a product name containing `RAGI` has prior `BUY` transaction history under another setting
- **THEN** the purchase importer MUST use `Daizu Kedelai` as the stock owner for the imported row

#### Scenario: Multi-line invoice resolves stock owner per product row
- **WHEN** a purchase invoice group contains multiple product rows with different product names
- **THEN** each row's stock ownership MUST be resolved from that row's own raw product name

### Requirement: Daizu setup failures are explicit
The purchase importer SHALL mark Daizu-matched rows invalid when the `Daizu Kedelai` setting or a usable Daizu stock location cannot be found.

#### Scenario: Missing Daizu setting invalidates matching rows
- **WHEN** a purchase CSV row has product name containing `KEDELE`
- **AND** the `Daizu Kedelai` setting does not exist
- **THEN** the row MUST be marked invalid
- **AND** the row error message MUST identify the missing Daizu setting

#### Scenario: Missing Daizu location invalidates matching rows
- **WHEN** a purchase CSV row has product name containing `RAGI`
- **AND** the `Daizu Kedelai` setting exists without a usable stock location
- **THEN** the row MUST be marked invalid
- **AND** the row error message MUST identify the missing Daizu location

### Requirement: Purchase import duplicate progress accounting
The purchase importer SHALL count duplicate skipped rows as processed rows without incrementing successful import count.

#### Scenario: Duplicate purchase rows are processed no-ops
- **WHEN** an imported invoice already exists for the resolved setting
- **THEN** matching import rows MUST be marked skipped
- **AND** skipped rows MUST be included in `processed_rows`
- **AND** skipped rows MUST NOT increment `success_count`

## MODIFIED Requirements

### Requirement: Daizu product purchase import ownership
The purchase importer SHALL resolve rows whose product name contains `KEDELE`, `KEDELAI`, or `RAGI` to the `Daizu Kedelai` setting for purchase document ownership before evaluating any other product-name marker.

#### Scenario: Untagged kedelai purchase row creates Daizu purchase
- **WHEN** a purchase CSV row has an empty `Tag` and product name `KEDELE IMPORT`
- **THEN** the created purchase MUST have `setting_id` for `Daizu Kedelai`

#### Scenario: Existing tag does not override Daizu product ownership
- **WHEN** a purchase CSV row has product name containing `RAGI` and a `Tag` mapped to another setting
- **THEN** the created purchase MUST still have `setting_id` for `Daizu Kedelai`
- **AND** the purchase MAY retain the CSV tag as metadata

#### Scenario: Product marker does not override Daizu product ownership
- **WHEN** a purchase CSV row has product name containing `KEDELAI` and also uses a generic import marker
- **THEN** the created purchase MUST still have `setting_id` for `Daizu Kedelai`

### Requirement: Daizu product stock ownership alignment
The purchase importer SHALL resolve stock movement ownership for Daizu-matched product rows to the `Daizu Kedelai` setting and its stock location, bypassing tag, marker, and historical transaction fallback.

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

## ADDED Requirements

### Requirement: Non-Daizu purchase import product-name ownership
The purchase importer SHALL resolve non-Daizu purchase import ownership from the raw product name and SHALL ignore CSV `Tag` for ownership mapping.

#### Scenario: Asterisk purchase row routes to Tiga Nusa despite tag
- **WHEN** a purchase CSV row has product name `* MONITOR SAMPLE` and `Tag` value `perdana`
- **THEN** the created purchase MUST have `setting_id` for `CV TIGA NUSA COMPUTER`
- **AND** the purchase MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: TP suffix purchase row routes to TOP IT despite tag
- **WHEN** a purchase CSV row has product name `MONITOR SAMPLE TP` and `Tag` value `cv tiga nusa`
- **THEN** the created purchase MUST have `setting_id` for `CV TOP IT INTERNUSA`
- **AND** the purchase MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: Unmarked purchase row routes to Perdana despite tag
- **WHEN** a purchase CSV row has product name `MONITOR SAMPLE` and `Tag` value `rahmat`
- **THEN** the created purchase MUST have `setting_id` for `PERDANA`
- **AND** the purchase MUST retain the CSV tag as metadata when tag syncing is available

### Requirement: Non-Daizu purchase import owner alignment
The purchase importer SHALL keep document owner, ProductPrice owner, stock owner, stock location owner, and inventory Transaction owner aligned to the product-name ownership rule for non-Daizu rows.

#### Scenario: Historical purchase owner is ignored for unmarked purchases
- **WHEN** a non-Daizu product has prior `BUY` transaction history under a setting other than `PERDANA`
- **AND** a purchase CSV row imports that product without `*` or ` TP` markers
- **THEN** the created purchase MUST have `setting_id` for `PERDANA`
- **AND** the stock increment, ProductPrice, and inventory Transaction MUST also use `PERDANA`

#### Scenario: Tag differences do not split purchase invoice ownership
- **WHEN** two purchase CSV rows share the same invoice number and resolve to the same product-name owner
- **AND** the rows have different non-empty `Tag` values
- **THEN** the importer MUST group them into the same purchase document for that product-name owner

### Requirement: Purchase import duplicate matching ignores tag
The purchase importer SHALL match duplicate imported purchases by invoice number and product-name-resolved setting, without using CSV `Tag` as an ownership key.

#### Scenario: Duplicate purchase with changed tag is skipped
- **WHEN** an imported purchase invoice already exists under the setting resolved from the product-name rule
- **AND** the same invoice is imported again with a different CSV `Tag`
- **THEN** matching import rows MUST be marked skipped
- **AND** matching import rows MUST reference the existing purchase

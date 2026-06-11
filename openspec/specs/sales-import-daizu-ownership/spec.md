## MODIFIED Requirements

### Requirement: Non-Daizu sales import product-name ownership
The sales importer SHALL resolve non-Daizu sales import ownership from the raw product name and SHALL ignore CSV `Tag` for ownership mapping.

#### Scenario: Asterisk sale row routes to Tiga Nusa despite tag
- **WHEN** a sales CSV row has product name `* MONITOR SAMPLE`
- **AND** the row has `Tag` value `perdana`
- **THEN** the created sale MUST have `setting_id` for `CV TIGA NUSA COMPUTER`
- **AND** the sale MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: TP suffix sale row routes to TOP IT despite tag
- **WHEN** a sales CSV row has product name `MONITOR SAMPLE TP`
- **AND** the row has `Tag` value `cv tiga nusa`
- **THEN** the created sale MUST have `setting_id` for `CV TOP IT INTERNUSA`
- **AND** the sale MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: Unmarked sale row routes to Perdana despite tag
- **WHEN** a sales CSV row has product name `MONITOR SAMPLE`
- **AND** the row has `Tag` value `rahmat`
- **THEN** the created sale MUST have `setting_id` for `PERDANA`
- **AND** the sale MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: Unmapped sale tag does not affect marker fallback
- **WHEN** a sales CSV row has a non-empty `Tag` that is not mapped to a setting
- **AND** the row has product name `MONITOR SAMPLE TP`
- **THEN** the created sale MUST have `setting_id` for `CV TOP IT INTERNUSA`
- **AND** the sale MUST retain the raw CSV tag as metadata when tag syncing is available

#### Scenario: Blank sale tag falls back to product marker
- **WHEN** a sales CSV row has an empty `Tag`
- **AND** the row has product name `* MONITOR SAMPLE`
- **THEN** the created sale MUST have `setting_id` for `CV TIGA NUSA COMPUTER`

### Requirement: Non-Daizu sales import owner alignment
The sales importer SHALL keep document owner, ProductPrice owner, dispatch location owner, duplicate lookup owner, and owner grouping aligned to the product-name ownership rule for non-Daizu rows.

#### Scenario: Historical purchase owner is ignored for unmarked sales
- **WHEN** a non-Daizu product has prior `BUY` transaction history under a setting other than `PERDANA`
- **AND** a sales CSV row imports that product without `*` or ` TP` markers
- **THEN** the created sale MUST have `setting_id` for `PERDANA`
- **AND** the generated dispatch detail location MUST belong to `PERDANA`
- **AND** the importer MUST NOT use historical purchase ownership to choose another owner

#### Scenario: Tag differences do not split sales invoice ownership
- **WHEN** two non-Daizu sales CSV rows share the same invoice number
- **AND** the rows have different non-empty `Tag` values
- **AND** the rows resolve to the same product-name owner
- **THEN** the importer MUST group them into the same sale document for that product-name owner

#### Scenario: Product markers split sales invoice ownership
- **WHEN** three non-Daizu sales CSV rows share the same invoice number and header values
- **AND** one row has a product name beginning with `*`
- **AND** one row has a product name ending with ` TP`
- **AND** one row has no product-name marker
- **THEN** the importer MUST create one sale for `CV TIGA NUSA COMPUTER`
- **AND** the importer MUST create one sale for `CV TOP IT INTERNUSA`
- **AND** the importer MUST create one sale for `PERDANA`

#### Scenario: Marker fallback owner stays aligned
- **WHEN** a non-Daizu sales CSV row has any `Tag` value
- **THEN** the created sale document, ProductPrice, dispatch detail location, duplicate lookup, and owner grouping MUST use the product-name marker fallback owner

### Requirement: Daizu sales duplicate handling
The sales importer SHALL prevent duplicate Daizu product sales by checking both Daizu-owned duplicates and legacy non-Daizu sales for the same imported invoice reference.

#### Scenario: Existing Daizu sale is skipped as duplicate
- **WHEN** a Daizu-matched sales CSV invoice has already been imported under Daizu Kedelai
- **THEN** matching import rows MUST be marked skipped
- **AND** matching import rows MUST reference the existing sale

#### Scenario: Existing non-Daizu Daizu-product sale blocks import
- **WHEN** a Daizu-matched sales CSV invoice matches an existing sale under another setting
- **AND** that existing sale contains a product whose name matches the Daizu product rule
- **THEN** matching import rows MUST be marked invalid
- **AND** the row error message MUST identify the legacy ownership conflict

#### Scenario: Non-Daizu duplicate behavior uses product-name owner
- **WHEN** a non-Daizu sales CSV invoice has already been imported under the setting resolved from the product-name owner rule
- **THEN** the importer MUST continue to apply duplicate skip behavior for that resolved setting
- **AND** CSV `Tag` values MUST NOT redirect the duplicate check to another setting

## ADDED Requirements

### Requirement: Imported sales dispatch without stock mutation
The sales importer SHALL persist imported sales as dispatched documents with dispatch details, but SHALL NOT mutate stock quantities or create inventory transactions for future sales import runs.

#### Scenario: Imported sale creates dispatch paperwork only
- **WHEN** a sales CSV invoice group imports successfully
- **THEN** the created sale MUST have status `DISPATCHED`
- **AND** the importer MUST create a dispatch record for that sale
- **AND** the importer MUST create dispatch detail rows for the imported sale details
- **AND** the importer MUST NOT decrement `product_stocks` quantities
- **AND** the importer MUST NOT decrement `products.product_quantity`
- **AND** the importer MUST NOT create inventory `transactions`

#### Scenario: Historical imported sales are not rewritten
- **WHEN** this change is deployed
- **THEN** existing imported sales, dispatches, stock quantities, and inventory transactions MUST remain unchanged
- **AND** no migration or background job MUST reverse prior sales import stock movements

## MODIFIED Requirements

### Requirement: Daizu product sales import ownership
The sales importer SHALL resolve rows whose product name contains whole-word `KEDELE`, `KEDELAI`, or `RAGI` to the Daizu Kedelai setting for sales document ownership before evaluating any other product-name marker.

#### Scenario: Untagged kedelai sale row creates Daizu sale
- **WHEN** a sales CSV row has an empty `Tag` and product name `KEDELE IMPORT`
- **THEN** the created sale MUST have `setting_id` for Daizu Kedelai

#### Scenario: Existing tag does not override Daizu sale ownership
- **WHEN** a sales CSV row has product name `RAGI` and a `Tag` mapped to another setting
- **THEN** the created sale MUST still have `setting_id` for Daizu Kedelai
- **AND** the sale MAY retain the CSV tag as metadata

#### Scenario: Product marker does not override Daizu sale ownership
- **WHEN** a sales CSV row has product name `* KEDELAI IMPORT TP`
- **THEN** the created sale MUST still have `setting_id` for Daizu Kedelai

#### Scenario: Non-whole-word names do not match Daizu rule
- **WHEN** a sales CSV row has product name `PREKEDELAI SAMPLE` or `RAGING BULL`
- **THEN** the sales importer MUST resolve ownership using the non-Daizu product-name marker rules

### Requirement: Daizu sales stock ownership alignment
The sales importer SHALL resolve stock movement ownership for Daizu-matched product rows to Daizu Kedelai and SHALL bypass marker, tag, and purchase-history fallback for those rows.

#### Scenario: Daizu sale row decrements Daizu stock
- **WHEN** a sales CSV row has product name containing whole-word `KEDELE`
- **THEN** the generated inventory Transaction MUST have `setting_id` for Daizu Kedelai
- **AND** the product stock quantity MUST be decremented at a location owned by Daizu Kedelai

#### Scenario: Historical purchase owner is ignored for Daizu sales
- **WHEN** a product name containing whole-word `RAGI` has prior `BUY` transaction history under another setting
- **THEN** the sales importer MUST use Daizu Kedelai as the stock owner for the imported sale row

#### Scenario: Sale, price, dispatch, stock, and transaction owners stay aligned
- **WHEN** a Daizu-matched sales row is imported successfully
- **THEN** the sale `setting_id` MUST be Daizu Kedelai
- **AND** the ProductPrice `setting_id` for the imported sale price MUST be Daizu Kedelai
- **AND** the dispatch detail location MUST belong to Daizu Kedelai
- **AND** the product stock decrement MUST occur at that Daizu location
- **AND** the inventory Transaction `setting_id` MUST be Daizu Kedelai

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

#### Scenario: Non-Daizu duplicate behavior uses product-name ownership
- **WHEN** a non-Daizu sales CSV invoice has already been imported under the setting resolved from its product-name marker
- **THEN** the importer MUST continue to apply duplicate skip behavior for that resolved setting
- **AND** CSV `Tag` values MUST NOT redirect the duplicate check to another setting

## ADDED Requirements

### Requirement: Non-Daizu sales import product-name ownership
The sales importer SHALL resolve non-Daizu sales import ownership from the raw product name and SHALL ignore CSV `Tag` for ownership mapping.

#### Scenario: Asterisk sale row routes to Tiga Nusa despite tag
- **WHEN** a sales CSV row has product name `* MONITOR SAMPLE` and `Tag` value `perdana`
- **THEN** the created sale MUST have `setting_id` for `CV TIGA NUSA COMPUTER`
- **AND** the sale MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: TP suffix sale row routes to TOP IT despite tag
- **WHEN** a sales CSV row has product name `MONITOR SAMPLE TP` and `Tag` value `cv tiga nusa`
- **THEN** the created sale MUST have `setting_id` for `CV TOP IT INTERNUSA`
- **AND** the sale MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: Unmarked sale row routes to Perdana despite tag
- **WHEN** a sales CSV row has product name `MONITOR SAMPLE` and `Tag` value `rahmat`
- **THEN** the created sale MUST have `setting_id` for `PERDANA`
- **AND** the sale MUST retain the CSV tag as metadata when tag syncing is available

### Requirement: Non-Daizu sales import owner alignment
The sales importer SHALL keep document owner, ProductPrice owner, stock owner, dispatch location owner, and inventory Transaction owner aligned to the product-name ownership rule for non-Daizu rows.

#### Scenario: Historical purchase owner is ignored for unmarked sales
- **WHEN** a non-Daizu product has prior `BUY` transaction history under a setting other than `PERDANA`
- **AND** a sales CSV row imports that product without `*` or ` TP` markers
- **THEN** the created sale MUST have `setting_id` for `PERDANA`
- **AND** the stock decrement and inventory Transaction MUST also use `PERDANA`

#### Scenario: Tag differences do not split sales invoice ownership
- **WHEN** two sales CSV rows share the same invoice number and resolve to the same product-name owner
- **AND** the rows have different non-empty `Tag` values
- **THEN** the importer MUST group them into the same sale document for that product-name owner

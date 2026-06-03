## MODIFIED Requirements

### Requirement: Non-Daizu sales import product-name ownership
The sales importer SHALL resolve non-Daizu sales import ownership from the effective row owner, using mapped CSV `Tag` before product-name marker fallback.

#### Scenario: Asterisk sale row routes to mapped tag owner
- **WHEN** a sales CSV row has product name `* MONITOR SAMPLE`
- **AND** the row has `Tag` value `perdana`
- **THEN** the created sale MUST have `setting_id` for `PERDANA`
- **AND** the sale MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: TP suffix sale row routes to mapped tag owner
- **WHEN** a sales CSV row has product name `MONITOR SAMPLE TP`
- **AND** the row has `Tag` value `cv tiga nusa`
- **THEN** the created sale MUST have `setting_id` for `CV TIGA NUSA COMPUTER`
- **AND** the sale MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: Unmarked sale row routes to mapped tag owner
- **WHEN** a sales CSV row has product name `MONITOR SAMPLE`
- **AND** the row has `Tag` value `rahmat`
- **THEN** the created sale MUST have `setting_id` for `WHITE KNIGHT COMPUTER`
- **AND** the sale MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: Unmapped sale tag falls back to product marker
- **WHEN** a sales CSV row has a non-empty `Tag` that is not mapped to a setting
- **AND** the row has product name `MONITOR SAMPLE TP`
- **THEN** the created sale MUST have `setting_id` for `CV TOP IT INTERNUSA`
- **AND** the sale MUST retain the raw CSV tag as metadata when tag syncing is available

#### Scenario: Blank sale tag falls back to product marker
- **WHEN** a sales CSV row has an empty `Tag`
- **AND** the row has product name `* MONITOR SAMPLE`
- **THEN** the created sale MUST have `setting_id` for `CV TIGA NUSA COMPUTER`

### Requirement: Non-Daizu sales import owner alignment
The sales importer SHALL keep document owner, ProductPrice owner, stock owner, dispatch location owner, and inventory Transaction owner aligned to the effective row owner: mapped CSV `Tag` when available, otherwise product-name marker fallback.

#### Scenario: Historical purchase owner is ignored for unmarked sales
- **WHEN** a non-Daizu product has prior `BUY` transaction history under a setting other than the effective row owner
- **AND** a sales CSV row imports that product with a mapped `Tag`
- **THEN** the created sale MUST have `setting_id` for the mapped tag owner
- **AND** the stock decrement and inventory Transaction MUST also use the mapped tag owner

#### Scenario: Tag differences split sales invoice ownership when mapped owners differ
- **WHEN** two non-Daizu sales CSV rows share the same invoice number
- **AND** the rows have mapped `Tag` values that resolve to different owners
- **THEN** the importer MUST group them into separate sale documents by effective owner

#### Scenario: Tag differences do not split sales invoice ownership when mapped owners match
- **WHEN** two non-Daizu sales CSV rows share the same invoice number
- **AND** the rows have different non-empty tag text that maps to the same owner
- **THEN** the importer MUST group them into the same sale document for that effective owner

#### Scenario: Marker fallback owner stays aligned
- **WHEN** a non-Daizu sales CSV row has a blank or unmapped `Tag`
- **THEN** the created sale document, ProductPrice, dispatch location, stock decrement, and inventory Transaction MUST use the product-name marker fallback owner

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

#### Scenario: Non-Daizu duplicate behavior uses effective owner
- **WHEN** a non-Daizu sales CSV invoice has already been imported under the setting resolved from the effective owner rule
- **THEN** the importer MUST continue to apply duplicate skip behavior for that resolved setting
- **AND** CSV `Tag` values MUST redirect the duplicate check only when they map to a different effective owner

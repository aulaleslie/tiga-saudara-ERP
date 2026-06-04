## MODIFIED Requirements

### Requirement: Non-Daizu purchase import product-name ownership
The purchase importer SHALL resolve non-Daizu purchase import ownership from mapped CSV `Tag` when the tag maps to a known setting, and SHALL fall back to `PERDANA` when the tag is blank or unmapped. Product-name markers (`*`, `TP`, or no marker) SHALL NOT determine non-Daizu purchase ownership.

#### Scenario: Asterisk purchase row routes to mapped tag owner
- **WHEN** a purchase CSV row has product name `* MONITOR SAMPLE`
- **AND** the row has `Tag` value `perdana`
- **THEN** the created purchase MUST have `setting_id` for `PERDANA`
- **AND** the product name used for product creation or lookup MUST be `MONITOR SAMPLE`
- **AND** the purchase MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: TP suffix purchase row routes to mapped tag owner
- **WHEN** a purchase CSV row has product name `MONITOR SAMPLE TP`
- **AND** the row has `Tag` value `cv tiga nusa`
- **THEN** the created purchase MUST have `setting_id` for `CV TIGA NUSA COMPUTER`
- **AND** the product name used for product creation or lookup MUST be `MONITOR SAMPLE`
- **AND** the purchase MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: Unmarked purchase row routes to mapped tag owner
- **WHEN** a purchase CSV row has product name `MONITOR SAMPLE`
- **AND** the row has `Tag` value `rahmat`
- **THEN** the created purchase MUST have `setting_id` for `WHITE KNIGHT COMPUTER`
- **AND** the purchase MUST retain the CSV tag as metadata when tag syncing is available

#### Scenario: Unmapped purchase tag falls back to Perdana
- **WHEN** a purchase CSV row has a non-empty `Tag` that is not mapped to a setting
- **AND** the row has product name `MONITOR SAMPLE TP`
- **THEN** the created purchase MUST have `setting_id` for `PERDANA`
- **AND** the product name used for product creation or lookup MUST be `MONITOR SAMPLE`
- **AND** the purchase MUST retain the raw CSV tag as metadata when tag syncing is available

#### Scenario: Blank purchase tag falls back to Perdana
- **WHEN** a purchase CSV row has an empty `Tag`
- **AND** the row has product name `* MONITOR SAMPLE`
- **THEN** the created purchase MUST have `setting_id` for `PERDANA`
- **AND** the product name used for product creation or lookup MUST be `MONITOR SAMPLE`

### Requirement: Non-Daizu purchase import owner alignment
The purchase importer SHALL keep document owner, duplicate-owner grouping, payment allocation grouping, product creation owner, and purchase price update owner aligned to the effective row owner: mapped CSV `Tag` when available, otherwise `PERDANA`. Product-name marker fallback SHALL NOT be used for non-Daizu owner alignment.

#### Scenario: Historical purchase owner is ignored for unmarked purchases
- **WHEN** a non-Daizu product has prior import or inventory history under a setting other than the effective row owner
- **AND** a purchase CSV row imports that product with a mapped `Tag`
- **THEN** the created purchase MUST have `setting_id` for the mapped tag owner
- **AND** product creation or lookup and purchase price synchronization MUST use the mapped tag owner where an owner setting is required
- **AND** the importer MUST NOT create stock increments or inventory transactions for the row

#### Scenario: Tag differences split purchase invoice ownership when mapped owners differ
- **WHEN** two non-Daizu purchase CSV rows share the same invoice number
- **AND** the rows have mapped `Tag` values that resolve to different owners
- **THEN** the importer MUST group them into separate purchase documents by effective owner

#### Scenario: Tag differences do not split purchase invoice ownership when mapped owners match
- **WHEN** two non-Daizu purchase CSV rows share the same invoice number
- **AND** the rows have different non-empty tag text that maps to the same owner
- **THEN** the importer MUST group them into the same purchase document for that effective owner

#### Scenario: Blank or unmapped tag owner stays aligned to Perdana
- **WHEN** a non-Daizu purchase CSV row has a blank or unmapped `Tag`
- **THEN** the created purchase document, duplicate matching key, product creation owner, and purchase price owner context MUST use `PERDANA`
- **AND** product-name markers MUST NOT alter that owner

### Requirement: Purchase import duplicate matching ignores tag
The purchase importer SHALL match duplicate imported purchases by invoice number and effective owner, where effective owner is Daizu for Daizu products, mapped CSV `Tag` for mapped non-Daizu rows, and `PERDANA` when the tag is blank or unmapped.

#### Scenario: Duplicate purchase with changed mapped tag owner is not skipped under old owner
- **WHEN** an imported purchase invoice already exists under one setting
- **AND** the same invoice is imported again with a mapped CSV `Tag` that resolves to a different setting
- **THEN** matching import rows MUST NOT be skipped as duplicates of the purchase under the old setting
- **AND** duplicate detection MUST use the new effective owner

#### Scenario: Duplicate purchase with changed raw tag but same effective owner is skipped
- **WHEN** an imported purchase invoice already exists under the setting resolved from the effective owner rule
- **AND** the same invoice is imported again with a different raw CSV `Tag` that still resolves to the same effective owner
- **THEN** matching import rows MUST be marked skipped
- **AND** matching import rows MUST reference the existing purchase

### Requirement: Daizu purchase import product-name ownership
The purchase importer SHALL resolve purchase rows whose product names indicate Daizu/Kedelai goods to the Daizu setting before applying tag or default-owner rules.

#### Scenario: Kedelai product routes to Daizu despite tag
- **WHEN** a purchase CSV row has a product name containing `KEDELAI`, `KEDELE`, or `RAGI`
- **AND** the row has a mapped `Tag` for a non-Daizu setting
- **THEN** the created purchase MUST have `setting_id` for the Daizu setting
- **AND** the importer MUST NOT route the row to the tag owner

#### Scenario: Missing Daizu setting fails explicitly
- **WHEN** a purchase CSV row has a product name indicating Daizu/Kedelai goods
- **AND** no Daizu setting exists
- **THEN** the importer MUST mark the invoice group invalid
- **AND** the row error message MUST identify that the Daizu setting is missing

## ADDED Requirements

### Requirement: Daizu product sales import ownership
The sales importer SHALL resolve rows whose product name contains whole-word `KEDELE`, `KEDELAI`, or `RAGI` to the Daizu Kedelai setting for sales document ownership.

#### Scenario: Untagged kedelai sale row creates Daizu sale
- **WHEN** a sales CSV row has an empty `Tag` and product name `KEDELE IMPORT`
- **THEN** the created sale MUST have `setting_id` for Daizu Kedelai

#### Scenario: Existing tag does not override Daizu sale ownership
- **WHEN** a sales CSV row has product name `RAGI` and a `Tag` mapped to another setting
- **THEN** the created sale MUST still have `setting_id` for Daizu Kedelai

#### Scenario: Product marker does not override Daizu sale ownership
- **WHEN** a sales CSV row has product name `* KEDELAI IMPORT TP`
- **THEN** the created sale MUST still have `setting_id` for Daizu Kedelai

#### Scenario: Non-whole-word names do not match Daizu rule
- **WHEN** a sales CSV row has product name `PREKEDELAI SAMPLE` or `RAGING BULL`
- **THEN** the sales importer MUST resolve ownership using the existing Tag, marker, and fallback rules

### Requirement: Daizu sales stock ownership alignment
The sales importer SHALL resolve stock movement ownership for Daizu-matched product rows to Daizu Kedelai and SHALL bypass marker and purchase-history fallback for those rows.

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

### Requirement: Daizu sales warehouse resolution
The sales importer SHALL resolve CSV `Gudang` for Daizu-matched rows within Daizu Kedelai locations and SHALL fail explicitly when a required Daizu location cannot be found.

#### Scenario: Daizu row with Gudang uses matching Daizu location
- **WHEN** a Daizu-matched sales CSV row includes `Gudang` matching a location owned by Daizu Kedelai
- **THEN** the dispatch detail and product stock decrement MUST use that Daizu location

#### Scenario: Blank Gudang uses Daizu default location
- **WHEN** a Daizu-matched sales CSV row has an empty `Gudang`
- **THEN** the dispatch detail and product stock decrement MUST use the default available Daizu Kedelai location

#### Scenario: Gudang cannot fall back to another setting
- **WHEN** a Daizu-matched sales CSV row includes `Gudang` that does not match any Daizu Kedelai location
- **THEN** the row MUST be marked invalid
- **AND** the row error message MUST identify the missing Daizu location

### Requirement: Daizu sales setup failures are explicit
The sales importer SHALL mark Daizu-matched rows invalid when the Daizu Kedelai setting or a usable Daizu stock location cannot be found.

#### Scenario: Missing Daizu setting invalidates matching sales rows
- **WHEN** a sales CSV row has product name containing whole-word `KEDELE`
- **AND** the Daizu Kedelai setting does not exist
- **THEN** the row MUST be marked invalid
- **AND** the row error message MUST identify the missing Daizu setting

#### Scenario: Missing default Daizu location invalidates blank Gudang row
- **WHEN** a sales CSV row has product name containing whole-word `RAGI`
- **AND** the Daizu Kedelai setting exists without a usable location
- **AND** the row has an empty `Gudang`
- **THEN** the row MUST be marked invalid
- **AND** the row error message MUST identify the missing Daizu location

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

#### Scenario: Non-Daizu duplicate behavior remains unchanged
- **WHEN** a non-Daizu sales CSV invoice has already been imported under its resolved setting
- **THEN** the importer MUST continue to apply the existing duplicate skip behavior for that resolved setting

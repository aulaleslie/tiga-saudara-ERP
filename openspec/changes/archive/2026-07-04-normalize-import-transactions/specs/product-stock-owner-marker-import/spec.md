## MODIFIED Requirements

### Requirement: Import audit and row visibility
The system SHALL preserve batch-level and row-level visibility for stock snapshot import processing and SHALL record stock mutation audit data for successful overwrites.

#### Scenario: Successful row audit
- **WHEN** a row is imported successfully
- **THEN** the row SHALL show imported status, raw payload, resolved product reference, resolved owner/location context, previous quantity, and after quantity where supported by the import row schema.

#### Scenario: Row-level stock effect visibility
- **WHEN** an authorized user views a successful stock snapshot import row
- **THEN** the system SHALL show the clean product name, resolved owner, target location, imported total quantity, previous quantity, after quantity, tax/non-tax bucket effect, and stock transaction reference where supported by the schema.

#### Scenario: Stock transaction recorded
- **WHEN** the system overwrites stock for a row after import transaction normalization has been run
- **THEN** the system SHALL create a stock transaction or equivalent audit record that captures the product, owner setting, target location, latest normalized ledger quantity as previous quantity, snapshot total quantity as after quantity, user, and import reason.

#### Scenario: Stock transaction adjustment quantity
- **WHEN** the latest normalized ledger quantity differs from the stock snapshot total quantity
- **THEN** the stock transaction quantity SHALL equal the stock snapshot total quantity minus the latest normalized ledger quantity.

#### Scenario: Stock transaction with no prior ledger
- **WHEN** the system overwrites stock for a product/location that has no prior normalized transaction ledger
- **THEN** the stock transaction previous quantity SHALL be `0`, after quantity SHALL be the stock snapshot total quantity, and quantity SHALL equal the stock snapshot total quantity.

#### Scenario: Failed row audit
- **WHEN** a row cannot be processed due to invalid data, missing owner setting, missing location, or product conflict
- **THEN** the row SHALL show error status and an actionable error message without blocking unrelated valid rows in the same batch.

#### Scenario: Missing owner setting visibility
- **WHEN** a row marker resolves to an owner name that is not configured
- **THEN** the row SHALL fail without product stock mutation and SHALL show which owner mapping could not be resolved.

#### Scenario: Missing owner location visibility
- **WHEN** a row resolves to an owner setting without a configured location
- **THEN** the row SHALL fail without product stock mutation and SHALL show that the owner has no target location configured.

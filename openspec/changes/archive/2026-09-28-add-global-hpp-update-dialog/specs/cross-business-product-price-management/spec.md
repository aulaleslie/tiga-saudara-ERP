# Spec Delta

## ADDED Requirements

### Requirement: Authorized users can update global HPP through an isolated dialog
The cross-business product price-management page SHALL present an `Ubah HPP` action beside `Ubah` to users authorized for cross-business price management. The action SHALL open a dedicated Bahasa Indonesia dialog without entering the page's general edit mode, and average purchase price SHALL remain read-only in the per-business table.

#### Scenario: Authorized user opens the HPP dialog
- **WHEN** an authorized user activates `Ubah HPP`
- **THEN** the system SHALL open a dialog showing the currently loaded average purchase price and one Rupiah input for the replacement value
- **AND** the general cross-business price inputs SHALL remain read-only

#### Scenario: HPP interaction is isolated from general edit mode
- **WHEN** the page is in general `Ubah` mode
- **THEN** the `Ubah HPP` action SHALL not be available for interaction
- **AND** unsaved general price edits SHALL NOT be submitted through the HPP operation

#### Scenario: Unauthorized user requests the HPP update operation
- **WHEN** a user without cross-business price-management permission requests the global HPP update operation directly
- **THEN** the system SHALL deny access
- **AND** no product price SHALL change

### Requirement: The HPP dialog explains the global and temporal effects
The HPP dialog SHALL warn in Bahasa Indonesia that saving replaces average purchase price for every business, affects HPP resolution for future sales, does not rewrite HPP snapshots already stored on historical sales, and can be recalculated by a later approved purchase receipt. If loaded business values differ, the dialog SHALL additionally disclose that saving will normalize those values.

#### Scenario: Uniform current values are shown
- **WHEN** all existing business price rows have the same average purchase price
- **THEN** the dialog SHALL show that value as the current global HPP
- **AND** it SHALL show the standard side-effect warning

#### Scenario: Existing values differ by business
- **WHEN** the loaded average purchase price is not identical across all business rows
- **THEN** the dialog SHALL show an additional discrepancy warning
- **AND** it SHALL state that saving replaces the differing values with one global value

### Requirement: Global HPP save is validated, atomic, and concurrency-safe
The dedicated operation SHALL accept one average purchase price greater than zero for a stock-managed product, synchronize it to every current business in one transaction, preserve unrelated price and tax fields, and reject stale state or duplicate pending submission. A successful operation SHALL redirect to the same cross-business product price-management page with refreshed values and a Bahasa Indonesia success message.

#### Scenario: Valid global HPP is saved
- **WHEN** an authorized user submits a valid positive HPP against current loaded state
- **THEN** every current business price row for the product SHALL contain the submitted average purchase price
- **AND** unrelated prices and tax assignments SHALL remain unchanged
- **AND** the system SHALL redirect to the same product price-management page showing the synchronized value
- **AND** the system SHALL show `Harga Beli Rata-rata berhasil diperbarui untuk seluruh bisnis.`

#### Scenario: Divergent values are normalized
- **WHEN** current businesses contain different average purchase prices and the user submits a valid replacement
- **THEN** the system SHALL replace every business value with the submitted global value in one transaction

#### Scenario: Invalid global HPP is rejected
- **WHEN** the submitted value is absent, nonnumeric, zero, negative, or exceeds supported currency precision
- **THEN** the system SHALL provide validation feedback
- **AND** no business price row SHALL change

#### Scenario: Product is not stock managed
- **WHEN** the selected product is not stock managed
- **THEN** the page SHALL not offer an actionable global HPP update
- **AND** a direct update request SHALL be rejected without changing prices

#### Scenario: Loaded HPP state became stale
- **WHEN** any current business average purchase price, business membership, or relevant row presence changes after the dialog state was loaded
- **THEN** the system SHALL reject the complete HPP update
- **AND** no business price row SHALL be partially updated
- **AND** the user SHALL be instructed to reload the page

#### Scenario: User submits repeatedly while save is pending
- **WHEN** the user activates `Simpan HPP` more than once while the first request is pending
- **THEN** the interface SHALL disable further submission and display a saving state
- **AND** persistence SHALL remain equivalent to one valid submission

#### Scenario: Persistence fails after updates begin
- **WHEN** any price-row update or audit persistence fails
- **THEN** all average purchase price changes from the operation SHALL roll back


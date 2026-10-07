# Spec Delta

## ADDED Requirements

### Requirement: Stock Transfers list expanded search
The system SHALL allow users to search the Stock Transfers list by partial or full transfer document number, current name or primary barcode of a product associated with the transfer, and serial number associated with the transfer at any persisted stage. Search SHALL retain the list's existing transfer visibility rules and SHALL return each matching transfer once.

#### Scenario: Search by current product name
- **WHEN** a user searches with part or all of the current name of a product recorded on a transfer
- **THEN** the list includes that transfer even if the product name changed after the transfer was created

#### Scenario: Search by primary barcode
- **WHEN** a user searches with part or all of the current primary barcode of a product recorded on a transfer
- **THEN** the list includes that transfer

#### Scenario: Conversion barcode is excluded
- **WHEN** a search value matches only a product unit-conversion barcode
- **THEN** that barcode does not cause a transfer to appear

#### Scenario: Search by serial at any stage
- **WHEN** a user searches with part or all of a serial number persisted with a transfer's request, allocation, or movement, including a superseded or cancelled stage
- **THEN** the list includes the associated transfer

#### Scenario: Preserve list scope and behavior
- **WHEN** a search term matches several lines or serial records on one transfer, including one outside the user's visible-transfer scope
- **THEN** each visible matching transfer appears once, hidden transfers remain absent, and existing sorting, pagination, and row actions remain available

#### Scenario: Preserve existing header search
- **WHEN** a user searches with part or all of a transfer document number
- **THEN** matching visible transfers remain discoverable

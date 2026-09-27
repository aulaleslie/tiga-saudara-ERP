# Spec Delta

## ADDED Requirements

### Requirement: Low stock alert threshold SHALL remain globally editable

The system SHALL allow an authorized user to edit a stock-managed product's low-stock alert threshold from the normal product edit page regardless of whether the product already has stock. The threshold SHALL be stored once as shared product data and SHALL apply across all businesses and locations that use the product.

#### Scenario: Edit threshold for product with existing stock
- **WHEN** an authorized user opens the normal edit page for a stock-managed product that has existing stock
- **THEN** the low-stock alert threshold field SHALL remain enabled
- **AND** the inventory-structural fields protected by existing-stock rules SHALL remain locked

#### Scenario: Save global threshold from any active business
- **WHEN** an authorized user submits a valid low-stock alert threshold while any business is active
- **THEN** the system SHALL persist that value on the shared product
- **AND** subsequent product views from every business SHALL observe the same threshold
- **AND** the system SHALL NOT create or update a business-specific threshold copy

#### Scenario: Stock management is disabled
- **WHEN** stock management is disabled for a product
- **THEN** the low-stock alert threshold field SHALL be disabled

#### Scenario: Invalid threshold is submitted
- **WHEN** a user submits a negative or non-integer low-stock alert threshold
- **THEN** the system SHALL reject the update with validation feedback
- **AND** the persisted threshold SHALL remain unchanged

#### Scenario: Product prices retain business scope
- **WHEN** a user updates the global low-stock alert threshold and setting-scoped prices in the same product edit submission
- **THEN** the threshold change SHALL apply to the shared product
- **AND** the price changes SHALL apply only to the active business


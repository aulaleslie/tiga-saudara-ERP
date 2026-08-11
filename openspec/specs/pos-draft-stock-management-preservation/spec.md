# pos-draft-stock-management-preservation Specification

## Purpose
This specification defines how POS drafts persist and restore the stock-management classification of cart lines so that non-stock lines keep non-stock behavior and stock-managed lines keep inventory validation after a draft is loaded.
## Requirements
### Requirement: POS drafts SHALL preserve line stock-management behavior
The system SHALL persist the normalized stock-management classification of every POS draft line and SHALL restore that classification when the draft is loaded into a POS cart.

#### Scenario: Loaded non-stock service retains non-stock behavior
- **WHEN** a cashier saves a draft containing a product with `stock_managed = false` and later loads that draft
- **THEN** the restored cart line MUST have `stock_managed = false`
- **AND** the line MUST remain excluded from parent stock allocation and inventory shortage validation

#### Scenario: Loaded stock-managed product retains stock validation behavior
- **WHEN** a cashier saves a draft containing a product with `stock_managed = true` and later loads that draft
- **THEN** the restored cart line MUST have `stock_managed = true`
- **AND** the line MUST remain subject to existing stock allocation and shortage validation

### Requirement: Historical POS drafts SHALL hydrate stock-management behavior safely
When a persisted POS draft line has no stock-management metadata, the system SHALL resolve its classification from the current associated product record during cart hydration. If the product cannot be resolved, the system MUST retain conservative stock-managed validation behavior.

#### Scenario: Legacy service draft is loaded
- **WHEN** a legacy draft line has no stored stock-management metadata and its current product has `stock_managed = false`
- **THEN** the restored cart line MUST be classified as non-stock-managed
- **AND** checkout preflight MUST NOT report that line as an inventory shortage solely because no product stock exists

#### Scenario: Unresolvable legacy product remains conservatively validated
- **WHEN** a legacy draft line has no stored stock-management metadata and its associated product cannot be resolved
- **THEN** the restored line MUST retain stock-managed validation behavior

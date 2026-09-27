# Spec Delta

## MODIFIED Requirements

### Requirement: POS drafts SHALL preserve line stock-management behavior
The system SHALL persist the normalized stock-management classification of every POS draft line and SHALL restore that classification when the draft is loaded into a POS cart. The system SHALL also restore each line's available-quantity value consistently with its stock-management classification: non-stock-managed lines MUST be restored with no available-quantity value (not zero), and stock-managed lines MUST be restored with a computed available-quantity value.

#### Scenario: Loaded non-stock service retains non-stock behavior
- **WHEN** a cashier saves a draft containing a product with `stock_managed = false` and later loads that draft
- **THEN** the restored cart line MUST have `stock_managed = false`
- **AND** the line MUST remain excluded from parent stock allocation and inventory shortage validation

#### Scenario: Loaded stock-managed product retains stock validation behavior
- **WHEN** a cashier saves a draft containing a product with `stock_managed = true` and later loads that draft
- **THEN** the restored cart line MUST have `stock_managed = true`
- **AND** the line MUST remain subject to existing stock allocation and shortage validation

#### Scenario: Loaded non-stock service line remains quantity-editable
- **WHEN** a cashier saves a draft containing a product with `stock_managed = false` and later loads that draft
- **THEN** the restored cart line's available-quantity value MUST be absent (not zero)
- **AND** a subsequent quantity change on that line MUST NOT be rejected for exceeding available stock

## ADDED Requirements

### Requirement: Sales quick-add flow SHALL allow sequential additions without manual refresh
The sales-specific quick-add flow SHALL ensure that all sales-related inputs (Sale Price, Sale Tax, Tier Prices) are completely refreshed and ready for a new entry immediately after a successful cart insertion, without requiring a manual page reload or manual clearing of fields.

#### Scenario: Sales-specific fields refresh after each quick-add
- **WHEN** a user successfully adds a product to the sales cart via the quick-add modal
- **THEN** the modal SHALL remain ready for another addition
- **AND** the `sale_price` and `sale_tax_id` SHALL be reset to defaults
- **AND** any tier pricing configured in the modal SHALL be cleared.

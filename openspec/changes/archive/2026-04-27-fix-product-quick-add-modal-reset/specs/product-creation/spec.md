## ADDED Requirements

### Requirement: Product creation via quick-add MUST clear setting-scoped pricing
When a product is created using a quick-add flow, all persistent pricing metadata for the active setting (last purchase price, sale price, etc.) MUST be cleared from the modal view so that subsequent quick-add operations do not inherit pricing from the previously created item.

#### Scenario: Sale price is cleared after quick-add creation
- **WHEN** a user creates a product with a specific `sale_price` via quick-add
- **THEN** after the product is saved and the modal is ready for the next entry
- **AND** the `sale_price` input SHALL show 0 or be empty
- **AND** the visual RP formatting SHALL NOT show the previous price value.

## MODIFIED Requirements

### Requirement: Product creation via quick-add MUST clear setting-scoped pricing

When a product is created using a quick-add flow, all persistent pricing metadata for the active setting (last purchase price, sale price, etc.) MUST be cleared from the modal view so that subsequent quick-add operations do not inherit pricing from the previously created item.

#### Scenario: Sale price is cleared after quick-add creation
- **WHEN** a user creates a product with a specific `sale_price` via quick-add
- **THEN** after the product is saved and the modal is ready for the next entry
- **AND** the `sale_price` input SHALL show 0 or be empty
- **AND** the visual RP formatting SHALL NOT show the previous price value.

## ADDED Requirements

### Requirement: Product price visibility in DataTable SHALL use registered permission

The permission gate controlling price column visibility in the product DataTable SHALL use the centralized permission `products.view_prices` registered in `app/Config/Permissions.php`, replacing the unregistered `view_access_table_product` gate.

#### Scenario: Permission is registered in centralized config
- **WHEN** the permission seeder runs
- **THEN** the permission `products.view_prices` SHALL exist in the `permissions` table
- **AND** it SHALL be assigned to the Admin role automatically

#### Scenario: Old orphan permission is no longer referenced
- **WHEN** the product DataTable checks whether to show price columns
- **THEN** it SHALL use `Gate::allows('products.view_prices')`
- **AND** it SHALL NOT reference `view_access_table_product`

## ADDED Requirements

### Requirement: Bundle composition SHALL contain distinct first-level component rows
Each product bundle SHALL contain at least one component row, and a component product SHALL appear at most once within that bundle. Administrators SHALL represent repeated component demand through the row quantity rather than duplicate rows.

#### Scenario: Distinct components are accepted
- **WHEN** an administrator creates or updates a bundle with one row for each component product and every quantity is at least one
- **THEN** the system SHALL persist the submitted composition

#### Scenario: Duplicate component is rejected during creation
- **WHEN** an administrator submits the same component product in more than one row while creating a bundle
- **THEN** the system SHALL reject the definition without creating any bundle copies

#### Scenario: Duplicate component is rejected during update
- **WHEN** an administrator submits the same component product in more than one row while updating a bundle
- **THEN** the system SHALL reject the update and preserve the previously persisted composition

#### Scenario: Database prevents duplicate component identity
- **WHEN** a write attempts to persist a second row with the same `bundle_id` and `product_id`
- **THEN** the database SHALL reject the duplicate identity

### Requirement: Bundle composition SHALL expand exactly one level
A bundle SHALL expand only its own direct component rows. A parent product MAY be one of its own components, and a component product MAY have bundle definitions of its own; the system SHALL NOT recursively fetch or expand bundle definitions belonging to a component.

#### Scenario: Parent product is its own component
- **WHEN** Product A has a bundle containing Product A with component quantity one and one unit of the bundled parent is sold
- **THEN** the persisted composition SHALL retain both the parent demand and one direct component demand for Product A
- **AND** inventory processing SHALL deduct two units of Product A when both demands are stock-managed and fulfilled

#### Scenario: Component has its own bundle
- **WHEN** Bundle A directly contains Product B and Product B has one or more bundle definitions
- **THEN** selecting Bundle A SHALL include Product B only as the direct component configured in Bundle A
- **AND** the system SHALL NOT fetch or expand Product B's bundle definitions

### Requirement: Bundle administration SHALL maintain an independent enabled state
Every product bundle copy SHALL have a non-null boolean `is_active` value. New copies SHALL default to enabled, and an authorized administrator SHALL be able to enable or disable the copy belonging to the active setting without changing copies in other settings.

#### Scenario: New copies default to enabled
- **WHEN** an administrator creates a bundle
- **THEN** every setting copy created by that operation SHALL have `is_active = true`

#### Scenario: Disable one setting copy
- **WHEN** an authorized administrator disables a bundle copy while operating in Setting A
- **THEN** the Setting A copy SHALL have `is_active = false`
- **AND** copies in every other setting SHALL retain their previous enabled state

#### Scenario: Re-enable one setting copy
- **WHEN** an authorized administrator enables a disabled bundle copy in the active setting
- **THEN** only that setting copy SHALL have its enabled state updated

### Requirement: Nested bundle routes SHALL enforce product ownership
Editing, updating, or deleting a product bundle through a nested product route SHALL require both that the bundle belongs to the route product and that the bundle belongs to the active setting.

#### Scenario: Update bundle through its owning product
- **WHEN** an authorized administrator updates a bundle through the route for its parent product and active setting
- **THEN** the system SHALL apply the update

#### Scenario: Update bundle through a different product
- **WHEN** an administrator attempts to update a bundle through a route whose product is not the bundle's `parent_product_id`
- **THEN** the system SHALL return a not-found response
- **AND** the bundle header and component rows SHALL remain unchanged

#### Scenario: Update bundle from another setting
- **WHEN** an administrator attempts to update a bundle whose `setting_id` differs from the active setting
- **THEN** the system SHALL return a not-found response
- **AND** the bundle header and component rows SHALL remain unchanged

### Requirement: Referenced products SHALL be protected from deletion
The system SHALL prevent deletion of a product while it is referenced by any product bundle as either its parent or a direct component. Bundle definitions SHALL be removed before the referenced product can be deleted.

#### Scenario: Delete a bundle parent product
- **WHEN** an administrator attempts to delete a product that owns at least one bundle definition
- **THEN** the system SHALL reject the deletion
- **AND** the product, bundle headers, and component rows SHALL remain unchanged

#### Scenario: Delete a bundle component product
- **WHEN** an administrator attempts to delete a product referenced by at least one bundle component row
- **THEN** the system SHALL reject the deletion
- **AND** the product and every referencing bundle composition SHALL remain unchanged

#### Scenario: Delete product after references are removed
- **WHEN** all bundle headers and component rows referencing a product have been removed and an authorized administrator deletes that product
- **THEN** bundle-reference protection SHALL NOT prevent the deletion


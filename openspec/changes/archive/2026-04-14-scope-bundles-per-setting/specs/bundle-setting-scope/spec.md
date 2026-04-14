## ADDED Requirements

### Requirement: Bundle belongs to a setting
Every product bundle record SHALL have a `setting_id` foreign key referencing the `settings` table. This column SHALL NOT be nullable.

#### Scenario: Bundle created with setting_id
- **WHEN** a user creates a new bundle for a product
- **THEN** the bundle record SHALL be stored with `setting_id` equal to the user's active `session('setting_id')`

#### Scenario: Database constraint
- **WHEN** a bundle record exists in `product_bundles`
- **THEN** its `setting_id` MUST reference a valid row in the `settings` table

### Requirement: Bundle CRUD scoped to active setting
All bundle list, create, edit, and delete operations on the product detail page SHALL be scoped to the user's active `session('setting_id')`.

#### Scenario: Listing bundles on product detail page
- **WHEN** a user views the product detail page (`/products/{id}`)
- **THEN** only bundles matching the user's active `setting_id` SHALL be displayed

#### Scenario: Creating a bundle
- **WHEN** a user creates a bundle via `/products/{id}/bundles/create`
- **THEN** the bundle SHALL be stored with `setting_id` from `session('setting_id')`
- **AND** the user SHALL NOT need to manually select a setting

#### Scenario: Editing a bundle from another setting
- **WHEN** a user attempts to edit a bundle whose `setting_id` does not match the user's active setting
- **THEN** the system SHALL return a 404 response

#### Scenario: Deleting a bundle from another setting
- **WHEN** a user attempts to delete a bundle whose `setting_id` does not match the user's active setting
- **THEN** the system SHALL return a 404 response

### Requirement: Bundle resolver requires setting context
The `ProductBundleResolver` SHALL require a `settingId` parameter when resolving bundles for a product.

#### Scenario: Resolving bundles for a single product
- **WHEN** `ProductBundleResolver::forProduct($productId, $settingId)` is called
- **THEN** only bundles where `parent_product_id = $productId AND setting_id = $settingId` SHALL be returned

#### Scenario: Batch resolving bundles for multiple products
- **WHEN** `ProductBundleResolver::forProducts($productIds, $settingId)` is called
- **THEN** only bundles matching the given `settingId` SHALL be returned for each product

#### Scenario: Sellability check scoped by setting
- **WHEN** `ProductBundleResolver::isSellable($productId, $settingId)` is called
- **THEN** the check SHALL only consider bundles belonging to the given `settingId`

### Requirement: Existing bundles duplicated to all settings
During migration, every existing bundle (and its items) SHALL be duplicated to all available settings.

#### Scenario: Migration with one existing bundle and six settings
- **WHEN** the migration runs with 1 existing bundle and 6 settings
- **THEN** 6 copies of the bundle SHALL exist (one per setting), each with identical items
- **AND** every copy SHALL have the correct `setting_id` set

#### Scenario: Migration with no existing bundles
- **WHEN** the migration runs with zero existing bundles
- **THEN** no new records SHALL be created
- **AND** the `setting_id` column SHALL still be added as NOT NULL

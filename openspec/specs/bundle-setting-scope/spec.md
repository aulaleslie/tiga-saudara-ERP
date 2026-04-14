## Requirements

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

### Requirement: POS Bundle Listing Scoped by Setting
The POS sell screen's bundle selection dialog SHALL only display bundles that belong to the current `session('setting_id')`. Accessing the bundle list for a product via the POS API SHALL enforce this scoping.

#### Scenario: Opening bundle dialog in POS
- **WHEN** a user opens the bundle selection dialog for a product in the POS sell screen
- **THEN** only bundles whose `setting_id` matches the user's active `setting_id` SHALL be fetched and displayed
- **AND** bundles belonging to other settings SHALL BE excluded from the response

### Requirement: POS Cart Bundle Validation
Adding a bundle to the POS cart SHALL verify that the bundle belongs to the active `session('setting_id')`.

#### Scenario: Adding a bundle to cart
- **WHEN** a user adds a bundle to the POS cart
- **THEN** the system SHALL verify that `bundle.setting_id` matches the current `session('setting_id')`
- **AND** if it does not match, the system SHALL return a 422 error

### Requirement: POS Bundle Visibility in Search
A product SHALL only be flagged as having bundles (`is_bundle_parent`) in the search results if it has at least one bundle configured for the active `session('setting_id')`.

#### Scenario: Searching for products in POS
- **WHEN** a user searches for products in the POS sell screen
- **THEN** the `is_bundle_parent` flag SHALL ONLY be true if the product has a bundle record where `setting_id = session('setting_id')`

### Requirement: Scan Resolver Bundle Status Scoping
The POS scan resolver SHALL only identify a product as a bundle parent if bundle configurations exist for that product within the active `session('setting_id')`.

#### Scenario: Resolving a scan for a product with bundles in another setting
- **WHEN** a user scans a barcode for a product that has bundles in setting A but not in the active setting B
- **THEN** the `is_bundle_parent` flag in the resolution result SHALL be false

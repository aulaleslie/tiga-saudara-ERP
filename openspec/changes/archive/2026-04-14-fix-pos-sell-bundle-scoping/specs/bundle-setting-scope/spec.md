## ADDED Requirements

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

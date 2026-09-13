## ADDED Requirements

### Requirement: Page displays grouped bundle sale prices by business
The cross-business product price-management page SHALL display a `Harga Paket` matrix for the routed product with every current business setting as a row and every distinct non-null `product_bundles.replica_group_uuid` belonging to that product as a column. Each existing bundle copy SHALL expose its own `bundle_sale_price`, and the group column SHALL use persisted replica lineage rather than name, composition, price, or other mutable attributes.

#### Scenario: Product has replicated bundles
- **WHEN** the page loads a product with two distinct replica groups represented across multiple businesses
- **THEN** the bundle section MUST display two group columns
- **AND** each existing business/group cell MUST display that bundle copy's independently stored sale price

#### Scenario: Product has no grouped bundles
- **WHEN** the routed product has no bundle with a non-null replica-group identity
- **THEN** the bundle section MUST show an empty-state message
- **AND** the base and conversion sections MUST remain available

#### Scenario: Unrelated bundle is excluded
- **WHEN** another product has a bundle with the same name, price, or composition as a routed product's bundle
- **THEN** that other product's bundle MUST NOT appear in the routed product's matrix

### Requirement: Missing bundle copies remain unavailable
A business/group intersection without an existing `product_bundles` row SHALL display `Paket tidak tersedia`, remain read-only in view and edit modes, and be omitted from submitted bundle-price data. The system SHALL NOT represent absence as an existing zero-priced bundle and SHALL NOT create a bundle copy through cross-business price management.

#### Scenario: Business was created after a bundle group
- **WHEN** a current business has no bundle copy for a displayed replica group
- **THEN** its matrix cell MUST display `Paket tidak tersedia`
- **AND** the cell MUST NOT become editable after the user activates `Ubah`

#### Scenario: Existing zero price differs from an absent copy
- **WHEN** one business has an existing zero-priced bundle copy and another business has no copy in the same group
- **THEN** the existing copy MUST display an editable zero price in edit mode
- **AND** the absent copy MUST remain visibly unavailable and read-only

#### Scenario: Save preserves missing membership
- **WHEN** the user saves prices while one or more displayed group/business combinations are unavailable
- **THEN** the system MUST NOT create bundle rows for those combinations

### Requirement: Bundle price fields follow page editing and status behavior
Existing bundle sale-price cells SHALL initially be read-only, become editable through `Ubah`, accept non-negative values with at most two decimal places, restore their loaded values through `Batal`, and preserve submitted values after validation failure. Existing inactive bundles SHALL remain visible with inactive status guidance and SHALL remain price-manageable.

#### Scenario: User enters bundle price edit mode
- **WHEN** an authorized user activates `Ubah`
- **THEN** every existing bundle-price cell MUST become editable
- **AND** missing bundle-copy cells MUST remain read-only

#### Scenario: User cancels bundle changes
- **WHEN** the user changes one or more bundle prices and activates `Batal`
- **THEN** every existing bundle-price cell MUST return to its originally loaded value
- **AND** no bundle price MUST be persisted

#### Scenario: Inactive bundle remains manageable
- **WHEN** a replica group contains an inactive existing bundle copy
- **THEN** the page MUST identify the inactive state
- **AND** the existing copy's sale price MUST remain editable by an authorized user

#### Scenario: Invalid bundle price
- **WHEN** a submitted existing bundle price is absent, nonnumeric, negative, or has more than two decimal places
- **THEN** the entire save MUST be rejected without modifying any base, conversion, or bundle price

### Requirement: Bundle price payload is lineage-scoped and complete
The system SHALL validate every submitted bundle cell against trusted loaded-state evidence and current database state. Each submitted bundle ID MUST belong to the routed product and submitted setting and MUST retain its loaded non-null replica-group membership. The submitted cells MUST exactly represent the existing bundle cells in the loaded matrix, without duplicate, missing, foreign, or unavailable identities, and client input MUST NOT choose propagation targets.

#### Scenario: Valid existing cells are submitted
- **WHEN** the payload contains exactly the existing bundle cells for the routed product and their identities match current persisted lineage
- **THEN** the payload MUST be eligible for price validation and persistence

#### Scenario: Foreign or moved bundle is submitted
- **WHEN** a submitted bundle belongs to another product, another setting, or a different replica group than trusted loaded-state evidence
- **THEN** the system MUST reject the entire save
- **AND** no price MUST be modified

#### Scenario: Missing or duplicate existing cell
- **WHEN** the payload omits an expected existing bundle cell or repeats a bundle or business/group identity
- **THEN** the system MUST reject the entire save

#### Scenario: Client submits an unavailable cell
- **WHEN** a client fabricates bundle-price input for a group/business combination that has no bundle row
- **THEN** the system MUST reject the entire save
- **AND** MUST NOT create the missing bundle copy

### Requirement: Combined save rejects stale bundle state
The system SHALL reject the complete cross-business price save when a displayed bundle's price, version, product ownership, setting membership, replica-group membership, presence, or the current business list changed after page load. Validation and persistence SHALL be coordinated with row locking so verified state remains valid through commit.

#### Scenario: Bundle price changed after load
- **WHEN** another request changes an existing displayed bundle price after the page loads
- **THEN** saving the stale page MUST reject all base, conversion, and bundle changes
- **AND** the user MUST be instructed to reload

#### Scenario: Bundle membership changed after load
- **WHEN** a displayed bundle is added, deleted, or moved between lineage or setting membership after page load
- **THEN** the stale save MUST be rejected without partially saving another section

#### Scenario: Business was added after load
- **WHEN** the current setting list differs from the trusted loaded-state evidence at save time
- **THEN** the complete save MUST be rejected

### Requirement: Bundle updates preserve bundle definition and produce price-feed events
Cross-business price management SHALL update only `bundle_sale_price` on submitted existing bundle rows and SHALL preserve bundle names, descriptions, composition, informational component prices, active dates, enabled state, product ownership, setting ownership, and replica-group identity. Each changed bundle price SHALL produce the existing bundle-price update feed data for its affected setting within the same transaction and operation grouping; unchanged bundle cells SHALL NOT produce a qualifying price-change event.

#### Scenario: One business bundle price changes
- **WHEN** an authorized user changes one existing bundle copy's sale price and saves
- **THEN** only that submitted price value MUST change
- **AND** the bundle definition and lineage fields MUST remain unchanged
- **AND** a bundle-price update feed event MUST identify the affected setting and before/after values

#### Scenario: Bundle price is unchanged
- **WHEN** an existing bundle cell is submitted with a value numerically equal to its loaded value
- **THEN** the bundle price MUST remain stable
- **AND** that cell MUST NOT create a bundle-price change event

## MODIFIED Requirements

### Requirement: Combined price updates are atomic and preserve metadata
The system SHALL save base, conversion, and existing grouped bundle prices in one transaction, retaining existing base-price protections. Updating a conversion price SHALL preserve its sales-enabled and purchase-enabled flags and shared definition. Updating a bundle price SHALL preserve every non-price definition and lineage field. Payload identities SHALL match the current product's complete business/conversion matrix and complete existing bundle-cell matrix with no duplicates, omissions, unavailable cells, or foreign IDs.

#### Scenario: Independent business prices
- **WHEN** a user changes one business's conversion or bundle price and leaves the others unchanged
- **THEN** only that submitted price cell's numeric value SHALL change
- **AND** existing flags, units, factors, bundle definitions, bundle lineage, average purchase prices, and tax assignments SHALL remain unchanged

#### Scenario: Any save fails
- **WHEN** validation, identity verification, conflict detection, persistence, or qualifying feed recording fails for any section
- **THEN** no base, conversion, or bundle price SHALL be partially saved

#### Scenario: Forged or incomplete matrix
- **WHEN** a payload contains a duplicate, missing, unavailable, or foreign business, conversion, or bundle identity
- **THEN** the system SHALL reject the entire save


## ADDED Requirements

### Requirement: Normal Sales SHALL preserve bundle row identity
Normal Sales SHALL persist each selected cart row as its own commercial parent context and SHALL keep its captured bundle components associated with that parent without merging rows solely because product identifiers overlap.

#### Scenario: Same parent product uses different bundles
- **WHEN** two normal Sales cart rows use the same parent product with different bundle identifiers
- **THEN** the system SHALL persist distinct parent Sale detail rows
- **AND** each component row SHALL remain linked to the parent row for its selected bundle

#### Scenario: Same product is bundled and ordinary
- **WHEN** the same product appears once as a bundled parent and once as an ordinary Sales row
- **THEN** the system SHALL persist distinct parent Sale detail rows
- **AND** only the bundled parent SHALL own the captured component rows

#### Scenario: Component product is shared by multiple bundle rows
- **WHEN** the same component product occurs under two selected bundle parent rows
- **THEN** the system SHALL persist separate component records for the two parent contexts
- **AND** removing or updating one parent context SHALL NOT mutate the other parent context

### Requirement: Normal Sales SHALL persist expanded component quantity exactly once
For every linked bundle component, Normal Sales SHALL persist component quantity equal to the captured parent base-unit quantity multiplied by the captured quantity per bundle and SHALL NOT expand an already-expanded component quantity a second time.

#### Scenario: Parent quantity changes repeatedly before creation
- **WHEN** a bundled parent quantity is increased, decreased, and increased again before the Sale is created
- **THEN** each captured component quantity SHALL be recalculated from quantity per bundle and the final parent quantity
- **AND** the persisted component quantity SHALL equal the final parent quantity multiplied by quantity per bundle

#### Scenario: Hydrated draft quantity changes repeatedly
- **WHEN** an editable draft is hydrated from persisted parent and component quantities and its parent quantity changes repeatedly
- **THEN** the system SHALL retain the reconstructed quantity per bundle
- **AND** the updated component quantity SHALL be expanded exactly once from the final parent quantity

### Requirement: Normal Sales bundle persistence SHALL maintain parent and Sale linkage
Every linked normal Sales bundle component SHALL reference its owning parent Sale detail and SHALL carry the same Sale identifier as that parent detail.

#### Scenario: New Sale persists linked components
- **WHEN** a new normal Sale containing one or more bundled parent rows is persisted
- **THEN** every component `sale_detail_id` SHALL identify its owning parent detail
- **AND** every component `sale_id` SHALL equal the owning parent detail's `sale_id`

#### Scenario: Editable Sale replaces draft rows
- **WHEN** an editable normal Sale is updated through the full draft update path
- **THEN** the replacement component rows SHALL reference the replacement parent detail created for their cart row
- **AND** no component row from the replaced draft composition SHALL remain attached to the Sale

### Requirement: Normal Sales bundle persistence SHALL be atomic
Normal Sales SHALL persist the Sale header, parent detail rows, and linked bundle component rows in one database transaction.

#### Scenario: Component persistence fails during creation
- **WHEN** component persistence fails after creation of the Sale header or a parent detail has begun
- **THEN** the system SHALL roll back the Sale header, parent details, and component rows created by that operation

#### Scenario: Component persistence fails during editable draft update
- **WHEN** component persistence fails after an editable Sale's existing rows have begun replacement
- **THEN** the system SHALL preserve the previously committed Sale header, parent details, and component rows
- **AND** no partial replacement composition SHALL remain

### Requirement: Editable drafts SHALL preserve captured bundle composition
Normal Sales draft hydration and acknowledged updates SHALL use the persisted bundle composition snapshot and SHALL NOT silently replace it with the current live bundle definition.

#### Scenario: Live definition changes after draft creation
- **WHEN** component identity, quantity, or informational allocation in the live definition changes after a draft was persisted
- **THEN** edit hydration SHALL retain the persisted component identity, quantity per bundle, and informational allocation
- **AND** an acknowledged update SHALL persist that captured composition unless the user explicitly reselects the bundle

#### Scenario: One of multiple bundle rows is removed
- **WHEN** a user removes one bundled cart row while another bundled row remains
- **THEN** only the removed row and its captured component context SHALL be absent from the persisted Sale
- **AND** the remaining row's component identity and quantity SHALL be unchanged

### Requirement: Normal Sales SHALL defer component stock enforcement to dispatch
Normal Sales creation and editable-draft update SHALL NOT reject a bundle solely because current component stock is insufficient and SHALL NOT mutate inventory; dispatch SHALL validate stock-managed component availability for the selected owner location before accepting fulfillment.

#### Scenario: Sale creation has insufficient component stock
- **WHEN** a normal Sale is created with captured component demand greater than current component stock
- **THEN** the Sale and its component demand SHALL be persisted
- **AND** no inventory movement SHALL occur during Sale creation

#### Scenario: Editable draft increases component demand beyond stock
- **WHEN** an editable draft update increases captured component demand beyond current component stock
- **THEN** the updated demand SHALL be persisted without inventory movement

#### Scenario: Dispatch attempts unavailable component quantity
- **WHEN** dispatch requests a stock-managed component quantity greater than available stock at the selected location
- **THEN** dispatch validation SHALL reject the request before inventory movement

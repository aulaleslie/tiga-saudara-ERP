## ADDED Requirements

### Requirement: Bundle-Detail Modal for Component Serial Entry
When a cashier clicks a bundle cart line that contains at least one serial-required component, the POS system SHALL open a bundle-detail modal listing the bundle's components, with a serial-entry target for each component that is `serial_number_required`.

#### Scenario: Opening bundle detail from a bundle cart line
- **WHEN** the cashier clicks a bundle row in the POS cart that has one or more serial-required components
- **THEN** a bundle-detail modal MUST appear showing each component of that bundle line
- **AND** each serial-required component MUST display its required quantity and currently assigned serial count.

#### Scenario: Non-serial-required components display without input
- **WHEN** the bundle-detail modal is open for a bundle line containing components that are not `serial_number_required`
- **THEN** those components MUST be listed for context
- **AND** they MUST NOT present a serial-entry input.

### Requirement: Scanner-Compatible Per-Component Serial Capture
The bundle-detail modal SHALL reuse the existing continuous-scan input behavior (Enter/scanner submit with dedup) targeted at the currently active component, and SHALL auto-advance to the next incomplete serial-required component once the active component's assigned-serial count reaches its required quantity.

#### Scenario: Scanning a serial appends it to the active component
- **WHEN** the cashier scans a serial number (Enter-terminated) while a specific component row is active in the bundle-detail modal
- **THEN** the system MUST call the serial assignment endpoint for that component
- **AND** upon success the input MUST clear and remain focused for the next scan
- **AND** the modal MUST remain open.

#### Scenario: Auto-advance after component serial completion
- **WHEN** a scan causes the active component's assigned-serial count to reach its required quantity
- **AND** another serial-required component in the same bundle line still has an incomplete serial count
- **THEN** the system MUST automatically move the active scan target to that next incomplete component
- **AND** MUST NOT require the cashier to manually reselect the component.

#### Scenario: Removing a serial from a component
- **WHEN** the cashier removes a previously assigned serial from a component row in the bundle-detail modal
- **THEN** the system MUST call the serial removal endpoint for that specific component and serial
- **AND** the component's assigned-serial count MUST decrement to reflect the removal.

### Requirement: Draft Persistence of Partial Component Serial Entry
Partially entered bundle-component serial assignments SHALL persist when the POS cart is saved as a draft, and SHALL be restored when the draft is reopened, without requiring a new database table.

#### Scenario: Saving a draft with partial component serials
- **WHEN** a bundle cart line has some but not all required component serials assigned
- **AND** the cashier saves the cart as a draft
- **THEN** each component's currently assigned serials MUST be persisted within the existing bundle cart-line draft payload.

#### Scenario: Reopening a draft restores component serial state
- **WHEN** a cashier reopens a draft that was saved with partial bundle-component serial assignments
- **THEN** the bundle-detail modal MUST show each component's previously assigned serials
- **AND** checkout MUST remain blocked for any component still below its required quantity.

### Requirement: Checkout Blocked Until Bundle Line Fully Serialized
Checkout SHALL be blocked for a bundle cart line while the parent (if serial-required) or any serial-required component has fewer assigned serials than its required quantity.

#### Scenario: Checkout blocked with incomplete component serials
- **WHEN** a bundle cart line has a serial-required component with assigned-serial count less than its required quantity
- **THEN** checkout preflight and finalize MUST treat that bundle line as unfulfilled
- **AND** checkout MUST NOT proceed until all serial-required components and the parent (if applicable) are fully serialized.

#### Scenario: Checkout proceeds when parent and all components are fully serialized
- **WHEN** a bundle cart line's parent (if serial-required) and every serial-required component each have assigned-serial count equal to their required quantity
- **THEN** checkout preflight and finalize MUST treat that bundle line as fulfilled for serial purposes.

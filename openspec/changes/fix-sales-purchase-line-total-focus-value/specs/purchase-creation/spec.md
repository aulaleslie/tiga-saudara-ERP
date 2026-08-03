## ADDED Requirements

### Requirement: Purchase Total Baris editor reflects the current purchase line total
The Purchase create and edit cart SHALL initialize the editable `Total Baris` control for each standard purchase row from that row's current canonical final line total. Cart re-rendering or document hydration SHALL NOT reuse a stale or truncated editor value.

#### Scenario: New purchase row opens with its full total
- **WHEN** a user selects a product into a new Purchase with a final row total of `46500`
- **AND** the user opens that row's `Total Baris` editor
- **THEN** the editor SHALL display `46500`

#### Scenario: Existing purchase row opens with its persisted-derived total
- **WHEN** a user opens an existing Purchase for editing and a hydrated row has a current final line total of `46500`
- **AND** the user opens that row's `Total Baris` editor
- **THEN** the editor SHALL display `46500`

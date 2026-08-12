## MODIFIED Requirements

### Requirement: Search composition preserves list behavior
The system SHALL preserve existing list behavior, including accessible row action menus, when expanded search is used.

#### Scenario: Search preserves active filters
- **WHEN** a user searches a supported document list while status filters, payment filters, supplier filters, archive visibility, or summary-card filters are active on that list
- **THEN** the search results are constrained by the active filters

#### Scenario: Search preserves sorting and pagination
- **WHEN** a user searches a supported document list and changes sorting or pagination
- **THEN** the list applies the expanded search together with the selected sort and page state

#### Scenario: Matching multiple detail rows does not duplicate documents
- **WHEN** a search term matches more than one detail row for the same document
- **THEN** the list displays that document once

#### Scenario: Missing snapshot fields are safe
- **WHEN** a legacy document detail has an empty product snapshot field
- **THEN** expanded search remains renderable and does not error

#### Scenario: Search preserves available row actions
- **WHEN** a user searches the Purchase, Sales, Global Purchase Payment, or Global Sales Payment list
- **THEN** each displayed result retains a usable three-dot menu containing its authorized actions

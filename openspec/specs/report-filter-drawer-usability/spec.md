## ADDED Requirements

### Requirement: Filter drawer actions remain reachable
The report filter drawer SHALL keep its footer action controls visible and clickable regardless of how much content the drawer body holds. The drawer body SHALL be the scrolling region; the header and footer SHALL NOT shrink to accommodate body content.

#### Scenario: Footer stays visible with many selected values
- **WHEN** a user selects enough filter values that the drawer body content exceeds the drawer height
- **THEN** the footer SHALL remain fully visible within the viewport
- **AND** the Filter and Reset controls SHALL remain clickable

#### Scenario: Body scrolls instead of pushing the footer away
- **WHEN** the drawer body content exceeds the space available between header and footer
- **THEN** the body SHALL scroll vertically
- **AND** the header and footer SHALL retain their natural height

#### Scenario: Drawer remains usable at reduced viewport height
- **WHEN** the drawer is displayed in a short viewport
- **THEN** the footer action controls SHALL remain visible and clickable

### Requirement: Selected filter values are bounded in the drawer
The drawer SHALL prevent the selected-value display from dominating the filter panel. The selected-value area SHALL be constrained in height and scroll independently, and beyond a defined threshold the individual entries SHALL be replaced by a summary of how many values are selected.

#### Scenario: Selected value area scrolls independently
- **WHEN** the number of selected values exceeds the height allotted to the selected-value area
- **THEN** that area SHALL scroll within its own bounds
- **AND** the remaining filter controls SHALL stay reachable in the drawer body

#### Scenario: Large selections collapse to a summary
- **WHEN** the number of selected values exceeds the defined display threshold
- **THEN** the drawer SHALL display a count of selected values instead of one entry per value
- **AND** the user SHALL be able to expand the summary or clear the selection

#### Scenario: Small selections are shown individually
- **WHEN** the number of selected values is at or below the display threshold
- **THEN** each selected value SHALL be shown as an individually removable entry

### Requirement: All matching search results can be selected at once
The product filter SHALL provide an action that selects every product matching the current search term, including matches not present in the displayed option list. The action SHALL re-evaluate the search against the full data set rather than operating only on the visible options.

#### Scenario: Selection includes matches beyond the displayed options
- **WHEN** a search term matches more products than the option list displays
- **AND** the user invokes the select-all-matching action
- **THEN** all matching products SHALL be added to the selection, not only the displayed ones
- **AND** the applied report SHALL reflect every selected product

#### Scenario: Bulk selection merges with existing selections
- **WHEN** the user has already selected one or more products
- **AND** invokes the select-all-matching action for a new search term
- **THEN** the previously selected products SHALL remain selected
- **AND** no product SHALL appear more than once in the selection

#### Scenario: Oversized result sets are capped with notice
- **WHEN** the number of matching products exceeds the defined selection ceiling
- **THEN** the system SHALL select products up to that ceiling
- **AND** SHALL inform the user that the selection was truncated
- **AND** the message SHALL state the ceiling applied and the total number of matches

#### Scenario: Bulk selection requires a qualifying search term
- **WHEN** the current search term is shorter than the minimum search length
- **THEN** the select-all-matching action SHALL NOT select any products

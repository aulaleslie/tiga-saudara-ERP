## ADDED Requirements

### Requirement: Search results retain usable document actions
The system SHALL display a usable three-dot action menu for every authorized result row after the Purchase, Sales, Global Purchase Payment, or Global Sales Payment list refreshes.

#### Scenario: Purchase action menu after a search
- **WHEN** a user searches the Purchase list and matching rows are displayed
- **THEN** clicking a matching row’s three-dot control displays that row’s authorized action options

#### Scenario: Sales action menu after a search
- **WHEN** a user searches the Sales list and matching rows are displayed
- **THEN** clicking a matching row’s three-dot control displays that row’s authorized action options

#### Scenario: Global purchase payment action menu after a search
- **WHEN** a user searches the Global Purchase Payment list and matching rows are displayed
- **THEN** clicking a matching row’s three-dot control displays the permitted payment actions for that purchase

#### Scenario: Global sales payment action menu after a search
- **WHEN** a user searches the Global Sales Payment list and matching rows are displayed
- **THEN** clicking a matching row’s three-dot control displays the permitted payment actions for that sale

### Requirement: Action menus remain correct across result refreshes
The system SHALL associate an action menu with its currently displayed document after search clearing, sorting, filtering, pagination, or a subsequent search refreshes the list.

#### Scenario: Follow-up refresh replaces the displayed result
- **WHEN** a user searches for one document and then changes or clears the search so a different result set is rendered
- **THEN** opening a displayed row’s action menu shows actions for that displayed document and not a prior result

### Requirement: Existing action authorization is preserved
The system SHALL preserve existing permission and document-status rules when recovering action menus after a list refresh.

#### Scenario: User opens a refreshed result without a restricted permission
- **WHEN** a user lacks permission for an action and searches one of the affected lists
- **THEN** the refreshed result action menu does not display that restricted action

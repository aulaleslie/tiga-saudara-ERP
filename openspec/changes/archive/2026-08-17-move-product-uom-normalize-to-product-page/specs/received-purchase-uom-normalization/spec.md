## Purpose

Allow operators to correct a product's base unit of measure (UOM) from a product's detail page without requiring a specific purchase document context.

## Requirements

## ADDED Requirements

### Requirement: Candidate purchase/receipt rows default to fully selected
Once the operator has entered a target Unit and a positive factor, the system SHALL automatically load and pre-select every eligible candidate purchase/receipt row for the product in the active setting, while keeping each row's selection individually visible and togglable before preview.

#### Scenario: All eligible rows are preselected after factor entry
- **WHEN** an authorized user sets a valid target Unit and a positive factor for an eligible product
- **THEN** the system SHALL load that product's candidate purchase/receipt rows in the active setting
- **AND** it SHALL preselect all of them without requiring a manual "select all" action

#### Scenario: Operator can still deselect an individual row before preview
- **WHEN** candidate rows have been preselected
- **THEN** the operator SHALL be able to uncheck any individual row before requesting a preview
- **AND** the preview and execution SHALL reflect only the rows still selected at the time of submission


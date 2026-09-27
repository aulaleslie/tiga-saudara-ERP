# expense-form-tax-selection Specification

## Purpose
TBD - created by archiving change fix-expense-form-tax-selection. Update Purpose after archive.
## Requirements
### Requirement: Expense form renders from detail-row tax state
The Expense Livewire form SHALL render create and edit workflows without reading an undeclared top-level tax property, and SHALL derive any selected tax identifiers from the expense detail rows.

#### Scenario: New expense form renders
- **WHEN** an authorized user opens Expense Create with an initial detail row that has no selected tax
- **THEN** the Expense Livewire form renders without a missing-property exception

#### Scenario: Multiple detail tax selections are represented
- **WHEN** an expense form contains detail rows with different selected tax identifiers
- **THEN** the form derives the retained tax option set from all selected detail-row tax identifiers

### Requirement: Expense tax options respect master-data lifecycle
The Expense form SHALL offer active taxes for new selection and SHALL additionally display an inactive tax only when that tax is already selected by a current expense detail row.

#### Scenario: Create form excludes inactive taxes
- **WHEN** a user opens Expense Create and no detail row already references an inactive tax
- **THEN** inactive taxes are excluded from the available tax options

#### Scenario: Edit form retains an inactive selected tax
- **WHEN** a user edits an expense whose detail row already references a tax that has become inactive
- **THEN** the referenced inactive tax remains present and selected for that row while other inactive taxes remain unavailable

### Requirement: Expense service enforces write-boundary tax lifecycle validation
The Expense service SHALL validate selected taxes inside the persistence transaction with row locks, rejecting inactive taxes on new expenses or new detail selections while permitting unchanged persisted inactive tax references.

#### Scenario: New expense rejects inactive tax
- **WHEN** an expense creation request includes an inactive tax ID
- **THEN** the expense service throws a validation exception and rejects persistence

#### Scenario: Edit expense rejects new inactive tax assignment
- **WHEN** an expense update request assigns an inactive tax to a new detail row or replaces an existing tax with a different inactive tax
- **THEN** the expense service throws a validation exception and rejects persistence

#### Scenario: Edit expense permits unchanged persisted inactive tax
- **WHEN** an expense update request retains an unchanged inactive tax on the same persisted detail row
- **THEN** the expense service accepts the update and persists the detail row


## ADDED Requirements

### Requirement: Sales detail rendering SHALL support linked and standalone bundle component sections
Sales detail/document rendering SHALL preserve standard sale detail rows and SHALL support displaying bundle components that are linked to a parent row and bundle components that are standalone.

#### Scenario: Linked bundle components render under their parent sale line
- **WHEN** a sale detail has associated bundle rows with non-null `sale_detail_id`
- **THEN** the Sales detail view SHALL render those bundle components under the parent sale line

#### Scenario: Standalone bundle components render without parent sale line
- **WHEN** a sale has bundle rows with null `sale_detail_id`
- **THEN** the Sales detail view SHALL render those rows in a standalone bundle component section
- **AND** rendering SHALL NOT require synthesizing a fake parent sale line

### Requirement: Sales document-line preservation SHALL remain unchanged for standard linked persistence
Standard Sales create/update behavior in this phase SHALL continue preserving cart rows as `sale_details` lines and persisting linked bundle rows for those parent lines.

#### Scenario: Standard Sales create remains linked behavior
- **WHEN** a standard Sales create operation persists rows from the Sales cart
- **THEN** each parent sale detail SHALL persist as a `sale_details` row
- **AND** bundle rows created in that operation SHALL remain linked through non-null `sale_detail_id`

#### Scenario: Standard Sales update remains linked behavior
- **WHEN** a standard Sales update operation replaces and persists current cart rows
- **THEN** each persisted parent sale detail SHALL persist as a `sale_details` row
- **AND** bundle rows created in that operation SHALL remain linked through non-null `sale_detail_id`

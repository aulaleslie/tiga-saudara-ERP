## ADDED Requirements

### Requirement: Warehouse stock quantity is business-scoped
The report SHALL follow the shared report business scope. Quantities SHALL be computed only from active locations of the selected businesses, and products SHALL be included regardless of their owning business.

#### Scenario: Unselected business excluded
- **WHEN** A is selected and B is not
- **THEN** no quantity from B's warehouses SHALL appear in any row or total

### Requirement: Warehouse stock quantity multi-business matrix
With several businesses selected, the report SHALL show one row per product with a `Total stok` column and one column group per business showing that business's total. Each business group SHALL expand to one column per selected warehouse of that business. Sorting by quantity SHALL use the Total. Exports SHALL include every business column, with warehouse columns included in full.

#### Scenario: Expand a business
- **WHEN** A and B are selected and the user expands A
- **THEN** A's group SHALL show one column per selected warehouse of A plus A's total

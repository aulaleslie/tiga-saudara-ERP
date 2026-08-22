## ADDED Requirements

### Requirement: Warehouse valuation distinguishes consignment locations from owned inventory totals
The Warehouse Stock Valuation report and exports SHALL visibly identify consignment-classified locations, show their physical quantity and operational average where useful, and exclude their supplier-owned stock value from the company-owned grand total.

#### Scenario: Consignment warehouse is displayed
- **WHEN** an authorized user includes a consignment location in warehouse filters
- **THEN** the warehouse group SHALL be labelled as consignment
- **AND** its physical quantity SHALL remain visible
- **AND** its value SHALL be identified as consignment custody rather than company-owned inventory

#### Scenario: Company-owned grand total is calculated
- **WHEN** selected warehouses include standard and consignment locations
- **THEN** the company-owned grand total SHALL sum only standard-location owned stock value
- **AND** any consignment value total SHALL be presented separately

#### Scenario: Existing standard warehouses are unchanged
- **WHEN** selected warehouses are all standard locations
- **THEN** existing quantity, average cost, value, filtering, pagination, CSV, and XLSX behavior SHALL remain unchanged

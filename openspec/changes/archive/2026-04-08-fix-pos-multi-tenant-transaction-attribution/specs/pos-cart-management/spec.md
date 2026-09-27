## ADDED Requirements

### Requirement: Unified Transaction Record
The POS transaction finalization must persist exactly one `PosTransaction` record per checkout, regardless of how many settings provide stock for the line items.

#### Scenario: Cross-Tenant Sale Unification
- **WHEN** A checkout contains items from Setting A and Setting B.
- **THEN** Only one `PosTransaction` record is created, owned by the active session's Setting.
- **THEN** The transaction contains all lines from both settings.

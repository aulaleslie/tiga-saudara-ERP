## ADDED Requirements

### Requirement: POS return line metadata column is available
The system SHALL provide a nullable JSON `line_meta` column on `pos_return_lines` so POS return draft line metadata can be persisted in environments where draft-resolution columns already exist without this metadata column.

#### Scenario: Repair migration adds missing metadata column
- **WHEN** the repair migration runs against a database where `pos_return_lines` exists and does not have `line_meta`
- **THEN** the system creates nullable JSON column `pos_return_lines.line_meta`

#### Scenario: Repair migration tolerates existing metadata column
- **WHEN** the repair migration runs against a database where `pos_return_lines.line_meta` already exists
- **THEN** the migration completes without attempting to add a duplicate column

### Requirement: Bundled draft metadata persists during POS return submit
The system SHALL persist bundle trace metadata for actionable bundled POS return draft lines without failing due to a missing `pos_return_lines.line_meta` column.

#### Scenario: Bundled actionable POS return draft saves metadata
- **WHEN** a user submits a POS return draft containing an actionable bundled line that produces `bundle_trace` metadata
- **THEN** the draft POS return is saved and the corresponding POS return line stores `bundle_trace` under `line_meta`

#### Scenario: POS return draft behavior remains unchanged
- **WHEN** a user submits a POS return draft after the schema repair
- **THEN** the system preserves the existing POS return draft resolution, stock mutation, Sales Return lifecycle, and payment settlement behavior

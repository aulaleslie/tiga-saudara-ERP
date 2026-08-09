## MODIFIED Requirements

### Requirement: Split planning SHALL allocate non-stock bundle component revenue to the first configured POS source
When a selected POS bundle contains a non-stock-managed component, split planning SHALL allocate that component's revenue to the first enabled configured POS sales-location source, using the existing sales-location configuration ordering. The source owner setting and source location SHALL be the setting and location of that first configured entry. The planner SHALL not filter candidate sources by PKP status and SHALL not fall back to the terminal setting when an enabled configured source exists.

#### Scenario: Stockless component uses the first configured POS source
- **WHEN** POS split planning allocates revenue for a selected bundled component with `stock_managed = false`
- **AND** the enabled POS sales-location configuration contains ordered sources
- **THEN** the component allocation revenue SHALL be assigned to the first configured source's setting and location

#### Scenario: First configured POS source is PKP
- **WHEN** the first enabled configured POS sales-location source belongs to a PKP business
- **THEN** a non-stock bundle component SHALL still use that source as owner
- **AND** the existing source-owner tax policy SHALL determine the split tax bucket

#### Scenario: No enabled POS source prevents non-stock checkout posting
- **WHEN** a POS checkout contains non-stock content and no enabled configured POS sales-location source exists
- **THEN** checkout preflight or finalization SHALL fail with an actionable source-configuration validation error

### Requirement: Checkout split groups SHALL preserve distinct stock and non-stock ownership within a bundle
The system SHALL derive POS checkout split groups by `source_setting_id + source_location_id + tax_bucket`. For selected bundled POS lines, non-stock parent or component revenue SHALL follow the first configured POS source, while stock-managed parent or component revenue, inventory ownership, and deduction SHALL follow the existing stock allocation result.

#### Scenario: Service parent and RAM component have different owners
- **WHEN** a non-stock bundle parent resolves to the first configured POS source and its stock-managed RAM component allocates from another source
- **THEN** split posting SHALL create owner groups for the respective source ownerships
- **AND** the parent/service financial amount and RAM component financial amount SHALL each appear once only
- **AND** RAM inventory deduction SHALL occur only at its allocation source

#### Scenario: Service parent and RAM component share an owner
- **WHEN** the configured non-stock source and the stock allocation source resolve to the same setting, location, and tax bucket
- **THEN** split posting SHALL combine their amounts in one owner group
- **AND** only the RAM component SHALL cause inventory effects

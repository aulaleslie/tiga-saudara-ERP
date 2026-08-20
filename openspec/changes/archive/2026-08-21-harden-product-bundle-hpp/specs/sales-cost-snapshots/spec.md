## MODIFIED Requirements

### Requirement: Sale details persist product cost snapshots
The system SHALL persist immutable product sales cost snapshots on Sale detail rows and fulfilled bundle-component rows so historical profit/loss reports do not depend on mutable product average purchase prices. Live cost resolution SHALL use the physical stock owner's positive average purchase price followed by deterministic same-PKP then opposite-PKP average-cost fallback.

#### Scenario: Stock-managed sale detail snapshots owner-aware average cost
- **WHEN** a live standard Sale or POS Sale posts a stock-managed product line that the Sale owner physically fulfills
- **THEN** the Sale detail SHALL store the resolved owner-aware average purchase price as `cost_unit_snapshot`
- **AND** it SHALL store `cost_total_snapshot` as fulfilled line quantity multiplied by `cost_unit_snapshot`
- **AND** it SHALL record snapshot source setting, PKP classification, source label, and snapshot time

#### Scenario: Fulfilled bundle component snapshots physical cost
- **WHEN** a live standard Sale or POS Sale posts a stock-managed bundle component
- **THEN** its persisted Sale bundle item SHALL store the resolved owner-aware unit and total cost snapshots
- **AND** its informational revenue-allocation price SHALL NOT affect those snapshots

#### Scenario: Non-stock-managed sale content receives zero cost
- **WHEN** a live standard Sale or POS Sale posts a non-stock-managed parent or component
- **THEN** that row SHALL store zero cost snapshots
- **AND** it SHALL record a snapshot source indicating verified non-stock-managed zero cost

#### Scenario: Missing average price receives explicit zero warning
- **WHEN** a stock-managed Sale parent or component has no positive average purchase price across the deterministic owner and PKP-aware fallback chain
- **THEN** the system SHALL store zero cost for that row
- **AND** its snapshot source SHALL identify missing average cost
- **AND** completion SHALL expose a missing-HPP warning

#### Scenario: Parent not fulfilled by split group receives no physical cost
- **WHEN** a POS owner group persists a logical parent Sale detail only to carry component revenue and identity but does not fulfill parent stock
- **THEN** that detail SHALL store zero parent cost with a distinct not-fulfilled source
- **AND** it MUST NOT be classified as ordinary non-stock content or missing average cost

## ADDED Requirements

### Requirement: Bundle cost backfill preserves exact-once identity
Historical cost replay SHALL fill eligible bundle-component cost snapshots using their persisted product, fulfilled quantity, Sale owner, and effective Sale date while preserving authoritative imported parent snapshots and preventing parent/component double-counting.

#### Scenario: Bundle component snapshot is absent
- **WHEN** bundle-aware backfill evaluates a historical fulfilled stock-managed component without a component cost snapshot
- **THEN** it SHALL calculate and persist the component snapshot using applicable historical owner-bucket rules
- **AND** it SHALL report the component update separately from Sale-detail updates

#### Scenario: Authoritative imported parent HPP exists
- **WHEN** a bundled Sale parent has an authoritative `HPP_SNAPSHOT_IMPORT` snapshot
- **THEN** bundle component backfill SHALL NOT overwrite or aggregate into that parent snapshot
- **AND** force semantics for component rows SHALL remain independently auditable


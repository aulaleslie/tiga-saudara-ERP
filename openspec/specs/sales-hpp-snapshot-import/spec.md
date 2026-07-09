# sales-hpp-snapshot-import Specification

## Purpose
TBD - created by archiving change import-sales-hpp-snapshot. Update Purpose after archive.
## Requirements
### Requirement: Authoritative HPP import can overwrite imported sale snapshots
The system SHALL allow an explicit sales HPP snapshot import to overwrite sale detail cost snapshots for matched imported sales, while preserving existing live-sale snapshot and historical backfill behavior.

#### Scenario: HPP import source supersedes prior snapshot
- **WHEN** a sales HPP snapshot import matches an existing imported sale detail
- **THEN** the import SHALL overwrite `cost_unit_snapshot`, `cost_total_snapshot`, `cost_snapshot_source`, and `cost_snapshot_at` for that sale detail
- **AND** the resulting HPP used by reports SHALL come from the imported HPP snapshot values.

#### Scenario: Backfill behavior remains unchanged
- **WHEN** the historical backfill command runs without force mode
- **THEN** it SHALL continue to preserve existing cost snapshots according to the existing backfill rules
- **AND** it SHALL NOT change the authoritative overwrite behavior of the explicit sales HPP snapshot import.


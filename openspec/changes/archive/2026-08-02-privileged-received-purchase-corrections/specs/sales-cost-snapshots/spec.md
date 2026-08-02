## ADDED Requirements

### Requirement: Authorized purchase correction can explicitly replay downstream sale costs
The system SHALL support a scoped, correction-linked replay of sale detail cost snapshots for products affected by a privileged received-purchase correction. The replay SHALL begin at the earliest affected approved receipt effective date, follow existing bucket and deterministic event-ordering rules, and require an explicit operator confirmation separate from saving the correction.

#### Scenario: Replay recalculates later eligible sale snapshots
- **WHEN** an authorized operator confirms downstream replay for a corrected received purchase
- **THEN** the system SHALL recompute later eligible sale detail cost snapshots for each affected product using corrected purchase costs
- **AND** the system SHALL record correction linkage and replay metadata for every rewritten snapshot or replay result

#### Scenario: Authoritative imported HPP remains protected
- **WHEN** a later sale detail has an authoritative imported-HPP snapshot source
- **THEN** the correction-triggered replay SHALL not overwrite that snapshot
- **AND** the replay result SHALL report the skipped authoritative snapshot

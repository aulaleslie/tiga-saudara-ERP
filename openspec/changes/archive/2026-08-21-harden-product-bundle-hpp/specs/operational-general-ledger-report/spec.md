## ADDED Requirements

### Requirement: Operational movements use shared net Sale HPP
Operational opening balances, period movement events, general ledger, trial balance, profit/loss-derived earnings, and balance-sheet earnings SHALL use one shared net Sale HPP calculation that includes parent and bundle-component physical cost snapshots and effective return reversals exactly once.

#### Scenario: Bundle component HPP enters operational cost
- **WHEN** an eligible dispatched Sale includes fulfilled bundle components with persisted HPP
- **THEN** operational cost SHALL be debited and inventory SHALL be credited for parent plus component HPP
- **AND** component revenue allocation SHALL not create additional operating revenue

#### Scenario: Effective return reverses operational cost
- **WHEN** an eligible physical return has effective immutable parent or component HPP reversal
- **THEN** operational cost and inventory movements SHALL reflect that reversal once in the applicable reporting period

#### Scenario: Reports share the same HPP result
- **WHEN** profit/loss, movement events, general ledger, trial balance, and balance-sheet earnings evaluate the same setting scope and period
- **THEN** each SHALL use the same net bundle HPP aggregate within currency rounding tolerance

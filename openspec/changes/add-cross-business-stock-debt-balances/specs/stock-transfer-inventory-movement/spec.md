# Spec Delta

## ADDED Requirements

### Requirement: Version 3 receipt atomically records cross-business debt
A successful workflow version `3` whole-document receipt SHALL record the debt effect of every immutable cross-business receipt allocation in the same locked, idempotent transaction as destination inventory, serial custody, inventory transactions, history, and transfer completion. Same-business allocations SHALL complete without a debt effect.

#### Scenario: Multi-route receipt contains same- and cross-business allocations
- **WHEN** one version `3` receipt confirms immutable allocations across multiple routes and businesses
- **THEN** all destination inventory effects and all applicable cross-business debt effects commit together exactly once while same-business allocations create no debt effect

#### Scenario: Debt effect persistence fails after inventory processing begins
- **WHEN** recording any required cross-business debt effect fails after an earlier allocation has been processed
- **THEN** the entire receipt rolls back without partial inventory, serial, transaction, history, debt, or completion effects

#### Scenario: Receipt replay is idempotent across inventory and debt
- **WHEN** the same completed receipt action is replayed
- **THEN** neither its inventory movements nor its debt effects are duplicated


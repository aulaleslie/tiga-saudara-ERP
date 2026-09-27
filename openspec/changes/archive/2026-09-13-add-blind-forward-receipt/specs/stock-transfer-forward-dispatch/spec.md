## MODIFIED Requirements

### Requirement: Forward-dispatch activation waits for matching route capability
The system SHALL permit production workflow version `2` forward dispatch only for same-business and cross-business non-PKP-to-non-PKP routes after the matching forward-receipt capability is available, and SHALL keep PKP-involved routes on workflow version `1` until Delivery 7 provides route-policy snapshots, destination reclassification, and return obligations.

#### Scenario: Dispatch eligible version 2 route
- **WHEN** an authorized origin user dispatches a workflow version `2` same-business or non-PKP-to-non-PKP transfer
- **THEN** the forward-dispatch lifecycle is available because its matching destination receipt can complete the transfer

#### Scenario: PKP-involved route remains legacy
- **WHEN** either the origin or destination business is PKP
- **THEN** the transfer remains workflow version `1` and follows established legacy behavior

#### Scenario: Crafted ineligible version 2 dispatch
- **WHEN** a user directly invokes version `2` forward dispatch for a PKP-involved route
- **THEN** the system rejects the request without creating a movement or changing inventory


## ADDED Requirements

### Requirement: Transfer serial selection SHALL use canonical condition-aware availability
Stock-transfer scanner, autocomplete, draft validation, approval, dispatch, and return-dispatch boundaries SHALL exclude missing and otherwise unavailable serials. Normal transfer mode SHALL accept only sellable serials; broken transfer mode SHALL accept only available-broken serials. Each authoritative mutation boundary SHALL revalidate current serial state rather than trusting saved UI metadata.

#### Scenario: Normal transfer scan accepts a sellable serial
- **WHEN** the operator scans an active-compatible, good, undispatched, non-returning serial at the selected origin
- **THEN** the transfer selects that serial once in normal mode

#### Scenario: Normal transfer scan rejects active broken serial
- **WHEN** the operator scans an `ACTIVE` serial with `is_broken=true` while using normal mode
- **THEN** the transfer does not select it and explains in Bahasa Indonesia that it belongs to broken stock

#### Scenario: Broken transfer scan accepts available broken serial
- **WHEN** the operator explicitly uses broken mode and scans an available-broken serial at the selected origin
- **THEN** the transfer selects it once and derives its authoritative tax and location provenance

#### Scenario: Missing serial is rejected in every transfer mode
- **WHEN** the operator scans or submits a serial with `status=MISSING`
- **THEN** normal and broken transfer modes reject it as unavailable
- **AND** no transfer quantity or serial selection changes

#### Scenario: Serial becomes unavailable before dispatch
- **WHEN** a selected serial becomes missing, sold, dispatched elsewhere, or enters a return process before transfer dispatch
- **THEN** authoritative dispatch validation rejects the transfer effect atomically

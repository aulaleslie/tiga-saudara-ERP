# pos-expected-cash-visibility Specification

## Purpose
Control disclosure of expected POS drawer cash independently from authority to operate sessions, count cash, and approve pickups.

## Requirements

### Requirement: Expected cash visibility is explicitly assignable
The unified permission manager SHALL expose `pos.sessions.viewExpectedCash` as `Lihat Ekspektasi Kas` in POS. Ordinary roles and supported non-owner bundles SHALL receive no automatic grant. Existing Admin synchronization and Super Admin bypass SHALL remain effective.

#### Scenario: Explicit assignment and revocation
- **WHEN** an administrator assigns or revokes this permission through unified role management
- **THEN** subsequent requests SHALL reflect the user's effective permission
- **AND** session ownership, session-view, report access, or supervisor approval alone SHALL NOT substitute for it

#### Scenario: Default provisioning
- **WHEN** permissions are synchronized
- **THEN** the permission SHALL be available for assignment and Admin SHALL receive it through existing all-permission synchronization
- **AND** manager, cashier, and floor-staff bundles and existing ordinary roles SHALL NOT automatically receive it
- **AND** Super Admin SHALL retain its existing bypass

### Requirement: POS disclosures obey expected cash visibility
POS SHALL withhold expected balances, variance, and reconciliation data that reveals those balances from users without the visibility permission. Enforcement SHALL apply to HTML, JSON, browser attributes and scripts, timelines and event metadata, lists, details, closing, finalization, reports, reconciliation, and action responses. Authorized viewers SHALL retain signed balance visibility within existing session and business access boundaries.

#### Scenario: Unauthorized direct and derived disclosure
- **WHEN** a permitted session owner or supervisor without visibility permission accesses POS session or cash information
- **THEN** responses SHALL omit expected cash, cached balances, after-pickup balances, numeric variance, and revealing reconciliation components and cash-event metadata
- **AND** the browser SHALL NOT receive these values in hidden elements, attributes, scripts, error text, or success messages

#### Scenario: Visibility does not broaden session access
- **WHEN** a user holds visibility permission but lacks access to a session or its business
- **THEN** existing authorization SHALL still deny access

#### Scenario: Authorized negative balance
- **WHEN** an authorized viewer accesses an accessible session with expected cash of -50000
- **THEN** POS SHALL display and return the signed balance without clamping it to zero

### Requirement: Cash operations remain available without balance visibility
Lack of expected-cash visibility SHALL NOT itself prevent otherwise permitted pickup, cash counting, closing, or finalization. The server SHALL retain authoritative ledger and variance calculations and existing approval requirements while withholding protected amounts from the requester. A supervisor OTP SHALL NOT grant the requester's browser balance visibility.

#### Scenario: Blind count and finalization
- **WHEN** an otherwise authorized user without balance visibility submits counted cash
- **THEN** the server SHALL evaluate existing variance approval rules and allow the existing approval flow when required
- **AND** the response SHALL disclose only the necessary approval outcome without numeric variance or expected cash

#### Scenario: Pickup approval does not reveal cash
- **WHEN** a user without balance visibility completes an otherwise valid OTP-approved pickup
- **THEN** pickup SHALL succeed without returning or displaying expected cash before or after pickup

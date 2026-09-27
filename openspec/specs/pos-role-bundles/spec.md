# pos-role-bundles Specification

## Purpose
TBD - created by archiving change redesign-pos-role-permission-model. Update Purpose after archive.
## Requirements
### Requirement: POS SHALL define four supported operational role bundles
The POS authorization model SHALL define four supported operational role bundles for admin assignment and documentation: `owner`, `manager`, `cashier`, and `floor staff`. `owner` SHALL map to the existing Super Admin authority model and SHALL continue to bypass standard permission guards. The other three bundles SHALL be represented through explicit permission combinations rather than runtime role-name inference.

#### Scenario: Owner bundle maps to Super Admin authority
- **WHEN** a user is designated as POS `owner`
- **THEN** the system MUST represent that authority through the existing Super Admin bypass model
- **AND** the system MUST NOT create a second parallel owner-only authorization path

#### Scenario: Non-owner bundles are permission-driven
- **WHEN** a user is designated as `manager`, `cashier`, or `floor staff`
- **THEN** the system MUST determine POS behavior from explicit permissions assigned to that bundle
- **AND** the system MUST NOT infer POS authority from role-name text matching

### Requirement: POS bundles SHALL preserve the cashier versus floor-staff handoff boundary
The system SHALL treat checkout authority as the operational boundary between `cashier` and `floor staff`. `cashier` and `manager` bundles SHALL be able to enter payment flow and complete checkout, but cashier checkout authority SHALL require an active POS session with a terminal assignment while manager checkout authority SHALL NOT. `floor staff` SHALL be able to enter the POS shell, prepare a transaction, save a draft, and load a draft for continuation, but SHALL NOT be allowed to begin or complete payment flow.

#### Scenario: Floor staff prepares handoff without payment authority
- **WHEN** a user is assigned the `floor staff` POS bundle
- **THEN** the user MUST be able to access the POS shell, manage cart preparation required for handoff, save a draft transaction, and load a draft transaction
- **AND** the user MUST NOT be allowed to access staged payment or checkout finalization
- **AND** the user MUST NOT be required or allowed to depend on terminal assignment in order to perform handoff work

#### Scenario: Cashier completes prepared transaction
- **WHEN** a user is assigned the `cashier` POS bundle
- **THEN** the user MUST be able to perform the same shell and draft-handoff actions as floor staff
- **AND** the user MUST also be allowed to enter payment flow and complete checkout when the active session has a terminal assigned

#### Scenario: Cashier cannot check out from terminal-less session
- **WHEN** a user in the supported `cashier` bundle has checkout authority but the active POS session has no terminal assigned
- **THEN** the system MUST keep payment actions unavailable
- **AND** the system MUST reject staged payment and checkout finalization entry points

#### Scenario: Manager can check out without terminal assignment
- **WHEN** a user in the supported `manager` bundle has checkout authority and the active POS session has no terminal assigned
- **THEN** the system MUST still allow payment flow and checkout finalization

### Requirement: POS bundles SHALL define default oversight boundaries
The system SHALL treat `manager` as the default POS oversight bundle below owner. By default, manager SHALL be allowed to access POS sessions, transaction oversight, approval queue, reports, reconciliation, terminal administration, and administrative session controls. Cashier and floor staff SHALL NOT receive those oversight capabilities unless explicitly granted as exceptions.

#### Scenario: Manager receives oversight defaults
- **WHEN** a user is assigned the `manager` POS bundle
- **THEN** the system MUST grant access to POS oversight screens and actions defined for manager
- **AND** those capabilities MUST be represented through explicit permissions rather than bypass behavior

#### Scenario: Cashier does not inherit oversight by default
- **WHEN** a user is assigned the `cashier` POS bundle with no extra exception permissions
- **THEN** the user MUST NOT automatically receive approval queue, reconciliation, report, or terminal administration access

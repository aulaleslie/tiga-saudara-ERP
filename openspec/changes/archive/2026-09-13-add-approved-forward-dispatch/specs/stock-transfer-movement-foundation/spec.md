## MODIFIED Requirements

### Requirement: Transfers support a staged versioned movement foundation
The system SHALL store a workflow version on every stock transfer, SHALL preserve existing and newly approved production transfers under legacy workflow version `1` while version `2` activation is disabled, and SHALL permit only the gated forward-dispatch capability to exercise version `2` movement effects in focused non-production verification until forward receipt is available.

#### Scenario: Existing transfer remains compatible
- **WHEN** the approved-forward-dispatch change is deployed for an existing transfer in any lifecycle state
- **THEN** its workflow version, status, and current lifecycle behavior remain unchanged

#### Scenario: Newly created production transfer remains legacy before coordinated cutover
- **WHEN** a production transfer is created or approved while version `2` activation is disabled
- **THEN** it remains workflow version `1` and follows the existing header lifecycle

#### Scenario: Gated version 2 forward-dispatch approval
- **WHEN** the version `2` forward-dispatch path is exercised through an authorized focused-test or non-production activation boundary
- **THEN** only the defined forward-dispatch approval effects occur atomically and no destination receipt, tax reclassification, or return movement effect occurs

### Requirement: Transit custody activates only for approved version 2 forward dispatch
The system SHALL activate serialized transit custody only as part of successful workflow version `2` forward-dispatch approval, SHALL leave live serial location at its last confirmed origin, and MUST NOT change custody for draft, pending, rejected, cancelled, failed, or legacy movements.

#### Scenario: Approve version 2 serialized forward dispatch
- **WHEN** an eligible serialized forward dispatch completes approval atomically
- **THEN** its movement serials become exclusively in transit while live serial locations remain unchanged

#### Scenario: Movement does not approve
- **WHEN** preparation, submission, comparison, stock validation, serial validation, or approval fails
- **THEN** transit custody remains inactive and existing serial availability is unchanged

#### Scenario: Legacy serial dispatch
- **WHEN** a workflow version `1` transfer dispatches through the existing path
- **THEN** its established serial behavior remains unchanged by the movement-custody capability

### Requirement: Movement foundation exposes only gated forward dispatch
The system SHALL expose movement creation, mutation, submission, review, rejection, correction, and approval only for forward dispatch through version-aware production routes guarded by an activation boundary, action permissions, operational ownership, and stock-visibility projections; other movement types MUST remain without production-facing surfaces in this change.

#### Scenario: Authorized version 2 dispatch preparation
- **WHEN** activation is enabled in a permitted environment and an eligible origin user invokes forward-dispatch preparation
- **THEN** the gated movement surface is available using the user's blind or privileged projection

#### Scenario: Receipt or return movement route attempted
- **WHEN** any user attempts to access a forward-receipt, return-dispatch, or return-receipt production movement surface in this change
- **THEN** no such surface is available and no movement or inventory effect occurs

#### Scenario: Activation remains disabled
- **WHEN** a user holds every movement permission but production version `2` activation is disabled
- **THEN** no new operational movement action is available and version `1` behavior remains unchanged

## ADDED Requirements

### Requirement: Confirmed zero is a complete movement observation
Movement lines SHALL store count confirmation independently from quantity, and a submitted forward-dispatch attempt SHALL treat a confirmed zero expected-product line as complete while treating an unconfirmed line as incomplete.

#### Scenario: Persist confirmed zero
- **WHEN** an operator explicitly confirms zero for an approved product
- **THEN** its movement line retains zero quantity with confirmed state for immutable submission and comparison

#### Scenario: Unconfirmed zero remains incomplete
- **WHEN** an approved product has zero quantity without explicit confirmation
- **THEN** the forward-dispatch attempt cannot be submitted


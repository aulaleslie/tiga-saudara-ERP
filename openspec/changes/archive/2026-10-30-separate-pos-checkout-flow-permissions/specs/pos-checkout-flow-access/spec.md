## ADDED Requirements

### Requirement: Checkout flows SHALL have independent visibility and authority
Lunas SHALL require `pos.checkout.payment`. Kasbon SHALL require `pos.checkout.debt` or `pos.checkout.debt.request`. UI and server authorization SHALL use the same flow capabilities, retaining established Super Admin behavior.

#### Scenario: Lunas-only user
- **WHEN** a user has payment permission and neither debt permission
- **THEN** only Bayar Lunas SHALL be visible
- **AND** Kasbon requests SHALL be denied

#### Scenario: Direct Kasbon-only user
- **WHEN** a user has direct debt permission and lacks payment permission
- **THEN** only Kasbon SHALL be visible and usable without approval
- **AND** Lunas requests SHALL be denied

#### Scenario: Approval-required Kasbon-only user
- **WHEN** a user has request permission and lacks payment and direct debt permissions
- **THEN** only “Kasbon · Perlu Persetujuan” SHALL be visible
- **AND** they SHALL be able to prepare the flow and stage down payments before approval
- **AND** completion SHALL require supervisor approval

#### Scenario: Both debt permissions
- **WHEN** a user has both debt permissions
- **THEN** the button SHALL read “Kasbon” and direct authority SHALL take precedence

#### Scenario: No checkout permissions
- **WHEN** a user has none of the three checkout permissions
- **THEN** both checkout buttons SHALL be hidden and payment flow access SHALL be denied

### Requirement: Shared payment infrastructure SHALL support either accessible flow
Shared payment infrastructure SHALL accept any of the three checkout permissions while preserving existing session, terminal, manager exception, cart, customer, and payment validation. Flow-specific mutation and completion SHALL enforce the effective flow permission. Lunas SHALL have no supervisor approval alternative.

#### Scenario: Kasbon-only user uses shared infrastructure
- **WHEN** a Kasbon-authorized user satisfies existing operational requirements
- **THEN** payment initialization, method lookup, staging, and relevant shared payment operations SHALL NOT require Lunas permission

#### Scenario: Effective checkout flow is resolved from staged state
- **WHEN** finalization merges request input with a staged payment chain
- **THEN** the effective resulting flow SHALL be authorized before posting
- **AND** omitted, legacy, or contradictory flow indicators SHALL NOT bypass flow authorization

#### Scenario: Direct unauthorized flow attempt
- **WHEN** a user invokes a flow mutation or finalization endpoint without access to that flow
- **THEN** the server SHALL deny it regardless of client visibility or approval-token possession

### Requirement: Switching SHALL require access to both flows
The payment flow switch SHALL be visible and usable only when the user has payment permission and either debt permission. Server checks SHALL enforce destination flow access.

#### Scenario: Both flows accessible
- **WHEN** a user has Lunas and direct or approval-required Kasbon access
- **THEN** switching SHALL be available with existing dirty-state confirmation behavior

#### Scenario: Only one flow accessible
- **WHEN** only one flow is accessible
- **THEN** the switch SHALL be hidden and attempts to switch to the other flow SHALL be denied

### Requirement: Lost flow access SHALL preserve staged data
When a staged chain's flow is no longer accessible, the system SHALL block unauthorized continuation and completion, explain the missing permission, and preserve staged payment data for authorized resolution. It SHALL NOT silently change the flow or delete staged payments.

#### Scenario: Permission changes before resume
- **WHEN** a user resumes a chain after losing access to its flow
- **THEN** completion SHALL be blocked with an explanation
- **AND** staged data SHALL remain intact without automatic flow conversion

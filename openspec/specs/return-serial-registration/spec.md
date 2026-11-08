# return-serial-registration Specification

## Purpose

Enable previously unregistered physical serials to enter inventory through source-linked returns of historically untracked goods, preserving existing lookup, original entitlement, and workflow-specific custody.

## Requirements

### Requirement: Unknown serial eligibility follows historical fulfillment
Sales Return and POS Return SHALL permit unknown serial entry only for a selected source fulfillment without recorded serials whose product currently requires serial tracking. Original serialized sources MUST retain serial matching. Server-side evidence MUST determine eligibility; return-time registrations MUST NOT rewrite historical tracking classification.

#### Scenario: Product tracking enabled after sale
- **WHEN** staff select an eligible original fulfillment with no recorded serials and the product currently requires tracking
- **THEN** unknown serial entry is available against its remaining quantity
- **AND** both return workflows support this behavior

#### Scenario: Original fulfillment recorded a different serial
- **WHEN** a source recorded serial A and staff attempt to return unknown serial B
- **THEN** the unknown-serial path is rejected without effects

#### Scenario: Original serialized evidence is inconsistent
- **WHEN** persisted evidence identifies serialized fulfillment but its serial identity cannot be resolved
- **THEN** the system blocks the unknown-serial path for review instead of interpreting missing data as untracked fulfillment

#### Scenario: Client changes eligibility
- **WHEN** a direct request claims that a historically serialized source is untracked
- **THEN** authoritative source checks reject the attempt

#### Scenario: Product remains non-serial
- **WHEN** the product currently has tracking disabled
- **THEN** its existing quantity-only return flow remains unchanged

### Requirement: Existing serial lookup remains authoritative
The system SHALL retain existing serial lookup and selection alongside unknown serial entry. A registered serial MUST pass existing source, product, ownership, status, and claim checks. The unknown-serial path MUST NOT reconcile or relink an existing serial that fails those checks.

#### Scenario: Registered serial selected
- **WHEN** staff enter a registered serial for the selected product
- **THEN** the existing lookup and validation flow handles it

#### Scenario: Existing serial lacks source lineage
- **WHEN** a registered serial does not pass the existing source-lineage checks for an old untracked sale
- **THEN** the return blocks that selection rather than treating it as an unknown serial

### Requirement: Pending serials remain outside inventory
Unknown serials SHALL be entered after selecting the original return line and persisted as proposed values before receiving. Draft save, edit, submission, and preview MUST NOT create serial inventory or physical movements. Staff MUST be able to correct proposed values before receiving within existing edit permissions and lifecycle rules.

#### Scenario: Draft saved and reopened
- **WHEN** staff save and reopen a draft containing an unknown serial
- **THEN** its proposed text and source association are retained without an inventory serial record

#### Scenario: Approval preview opened
- **WHEN** a POS return with proposed serials is previewed
- **THEN** registration and physical effects are shown as intentions without database mutation

### Requirement: Serial count and quantity entitlement are conserved
When current tracking is enabled, every unit received through the extension MUST have one distinct normalized serial. Partial returns SHALL be allowed within the original remaining quantity. Original quantity entitlement and later serial occurrences MUST be accounted together so registration cannot create additional return entitlement.

#### Scenario: Partial receipt with complete serial count
- **WHEN** two of three eligible units are returned with two distinct valid proposed serials
- **THEN** receiving can process two units and the third remains eligible subject to existing claim rules

#### Scenario: Missing or duplicate serial
- **WHEN** the serial count differs from received quantity or normalized serials repeat
- **THEN** receiving is rejected without partial registration or movement

#### Scenario: Competing quantity claims
- **WHEN** concurrent returns claim more units than the source's remaining entitlement
- **THEN** no committed claims exceed that entitlement even when different unknown serial texts are entered

### Requirement: Registration commits atomically with physical receiving
Physical receiving SHALL create each new serial and its return/source attribution with actor and time in the same transaction as receiving effects. Product-scoped normalized uniqueness MUST be enforced. Failure or retry MUST NOT leave duplicate serials, custody, stock, money, or history. Where POS approval executes receiving, registration SHALL be part of that atomic execution.

#### Scenario: Successful registration
- **WHEN** receiving commits for an eligible unknown serial
- **THEN** its serial record and return-time registration evidence commit with the correct product, owner, location, and tax identity
- **AND** the original sale's recorded serial lists remain unchanged

#### Scenario: Another process registers the serial first
- **WHEN** a proposed serial is registered before this receiving transaction
- **THEN** receiving stops for review, retains the proposed value, and requires existing lookup validation
- **AND** it does not silently attach the new existing record or commit other effects

#### Scenario: Receiving is retried
- **WHEN** an already committed receiving action is retried
- **THEN** its existing registration evidence is reused without duplicate effects or a false collision

#### Scenario: Later receiving effect fails
- **WHEN** an effect fails after serial creation within receiving
- **THEN** all effects of that action roll back, including newly created serials

### Requirement: Registered serials retain workflow disposition
New serials SHALL follow existing return disposition and access rules. Standard receiving MUST place them in unavailable held custody until authorized release or original-goods dispatch. POS receiving MUST preserve its good/bad disposition rules. Registration alone MUST NOT grant sellable availability or bypass replacement eligibility.

#### Scenario: Standard return received
- **WHEN** standard receiving registers an unknown serial
- **THEN** the serial is held and excluded from sellable inventory until the existing custody workflow permits release
- **AND** settlement coverage includes that received serial

#### Scenario: POS bad-condition return received
- **WHEN** POS receiving registers a returned serial assigned bad disposition
- **THEN** the existing bad-stock rules apply and the serial does not become an eligible good replacement automatically

#### Scenario: Newly registered original repaired or replaced
- **WHEN** an authorized repair, original-goods dispatch, or replacement executes after registration
- **THEN** existing custody and replacement stock rules apply to the registered original and any outgoing replacement

### Requirement: Bundle components retain their own identity
Unknown serial registration SHALL support eligible bundle components in both workflows. Each proposed serial MUST belong to the selected component's product and original fulfillment allocation, including split-owner POS bundles. Parent quantities and unrelated component serials MUST NOT substitute for component entitlement.

#### Scenario: Split-owner component registered
- **WHEN** an eligible untracked component now requires tracking and its parent belongs to another owner
- **THEN** registration uses the component's source owner, location, tax identity, and remaining component quantity

#### Scenario: Serialized component supplied an unknown serial
- **WHEN** a bundle component originally recorded a serial but its parent did not
- **THEN** that component retains original serial matching and rejects an unknown substitute

### Requirement: Subsequent returns follow registered customer-held occurrences
After repair or replacement returns goods to the customer, subsequent returns SHALL resolve the registered customer-held occurrence through existing lookup and lineage rules. Registration MUST NOT consume entitlement twice or expose the same unit as both a new quantity claim and an independent serial claim. Refunds MUST consume remaining entitlement once.

#### Scenario: Registered serial returns after repair
- **WHEN** a serial registered by an earlier return is dispatched back to the customer and returned again
- **THEN** its return uses its recorded customer-held occurrence and the existing source reference
- **AND** it does not require another unknown-serial registration

#### Scenario: Replacement followed by refund
- **WHEN** a newly registered original is replaced and the customer later returns the replacement for refund
- **THEN** lookup resolves the replacement occurrence and the refund consumes the original unit's remaining entitlement once

#### Scenario: Later return of another original unit
- **WHEN** one unit of an originally untracked multi-unit source has a registered return serial and another original unit is returned
- **THEN** the second unit remains eligible for unknown serial entry within its remaining original quantity

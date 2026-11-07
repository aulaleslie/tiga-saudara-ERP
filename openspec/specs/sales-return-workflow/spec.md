# sales-return-workflow Specification

## Purpose
Defines standard Sales Return ownership, permitted resolutions, and enforceable lifecycle transitions while preserving the separate POS Return execution contract.

## Requirements

### Requirement: Returns use the originating sales workflow
Sales originating from POS SHALL use POS Return. Standard creation MUST reject POS-origin source sales. Linked POS Sales Returns MUST remain readable with existing permissions but MUST NOT be mutated through standard return, settlement, inspection, or dispatch actions, including direct requests.

#### Scenario: POS sale selected in standard creation
- **WHEN** a user selects or directly submits a POS-origin sale for standard return
- **THEN** creation is blocked with guidance to POS Return and no records or effects are created
- **AND** both legacy direct checkout links and split checkout-sale links are recognized

#### Scenario: Linked record accessed outside POS orchestration
- **WHEN** a user attempts a standard mutation on a linked POS Sales Return, even with both sets of permissions
- **THEN** the mutation is blocked and the user is directed to the POS Return
- **AND** the POS execution state and linked evidence remain unchanged

#### Scenario: Established POS approval
- **WHEN** a ready POS approval executes
- **THEN** existing atomic receiving, financial correction, replacement or note-only execution, rounding, owner-bucket handling, and completion continue without standard inspection or per-item settlement requirements

### Requirement: Standard settlements offer three resolutions
New standard settlements SHALL offer repair/replacement, cash return, and cannot process. Historical credit and invoice-adjustment labels MUST remain readable but MUST NOT be selectable or submitted for new settlement. Cannot process MUST require a reason and approved dispatch of the original goods before completion.

#### Scenario: Settlement pages render
- **WHEN** an authorized user opens a standard settlement or its detail display
- **THEN** the three supported choices and existing historical labels render without an application error

#### Scenario: Legacy method submitted directly
- **WHEN** a new settlement submits a legacy or unknown method directly
- **THEN** validation rejects it without financial or lifecycle effects

#### Scenario: Goods cannot be processed
- **WHEN** cannot process is selected
- **THEN** a reason and approval are required and the original goods must be dispatched before that item becomes final

### Requirement: Standard mutations enforce persisted lifecycle and permissions
Every standard mutation SHALL validate business access, action permissions, persisted state, source lineage, and available quantity or serial occurrence on the server. Settlement MUST require approved physical receiving. Submitted or final items MUST NOT be overwritten by draft submission. Bulk and individual dispatch MUST enforce equivalent prerequisites.

#### Scenario: Direct submission before receiving
- **WHEN** a settlement is submitted directly for an unreceived return
- **THEN** the server rejects it without creating settlement effects

#### Scenario: Approved item resubmitted
- **WHEN** a caller resubmits or resets an approved or dispatched settlement
- **THEN** the server preserves its final state and rejects the edit

#### Scenario: Unapproved item included in bulk dispatch
- **WHEN** a bulk dispatch contains an item not approved and awaiting dispatch
- **THEN** the entire request fails without partial transitions

#### Scenario: Competing return claims
- **WHEN** concurrent standard returns claim the same remaining quantity or dispatched serial occurrence
- **THEN** no committed claims exceed the available entitlement

### Requirement: Completion covers every received detail and serial
A standard return SHALL complete only when every received detail quantity and every received serialized occurrence has exactly one final settlement covering it. Missing, draft, submitted, rejected, and awaiting-dispatch settlements MUST prevent completion. Financial finality and physical custody MUST remain separately visible.

#### Scenario: One of two details has no settlement
- **WHEN** the only existing settlement becomes final but another received detail has no settlement
- **THEN** the return remains awaiting settlement

#### Scenario: One serial remains unresolved
- **WHEN** all but one received serial have final settlements
- **THEN** the return remains incomplete until the last occurrence is resolved

#### Scenario: Cash return completed with held goods
- **WHEN** all required cash-return settlements are final while returned goods remain held
- **THEN** the return may complete and the outstanding custody remains visible and unavailable for sale

### Requirement: Lifecycle effects are atomic and repeat safe
Receiving, settlement approval, inspection release, dispatch, and completion SHALL revalidate locked persisted state and commit their related evidence and effects atomically. Repeated or competing requests MUST NOT duplicate custody, stock movements, refunds, debt adjustments, serial history, or archival.

#### Scenario: Approval retried or raced
- **WHEN** two requests approve the same settlement or a completed action is retried
- **THEN** at most one set of effects commits

#### Scenario: A later mutation fails
- **WHEN** an action fails after starting its stock, money, or lifecycle updates
- **THEN** all updates belonging to that action roll back together

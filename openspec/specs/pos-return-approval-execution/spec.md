# POS Return Approval Execution Specification

## Purpose

The POS Return approval workflow provides an authorized user interface to execute final approval of pending POS Returns from a preview page, applying all transactional effects to Sales, dispatch, inventory, payments, serials, and related documents. This specification defines the approval execution requirements for complete bundle cash refunds, independent bundle parent/component replacements, serial-tracked physical effects, and auditable note-only replacement completion, including atomicity and idempotency guarantees.
## Requirements
### Requirement: Final Approval Executes Ready Preview Atomically
The system SHALL allow an authorized approver to execute final POS Return approval from the approval preview page only when the latest preview has zero blockers and zero warnings. Execution MUST use the persisted POS Return line intent and a freshly rebuilt approval preview plan. Execution MUST run in one database transaction and MUST rollback all POS Return, Sales Return, Sale, dispatch, stock, serial, payment, and audit mutations when any step fails.

#### Scenario: Ready preview executes successfully
- **WHEN** an authorized user confirms final approval for a pending POS Return whose latest preview has no blockers and no warnings
- **THEN** the system persists the execution plan, applies all required return effects, marks linked Sales Returns completed, and marks the POS Return completed

#### Scenario: Warning blocks final approval
- **WHEN** an authorized user attempts final approval for a pending POS Return whose latest preview has one or more warnings
- **THEN** the system blocks final approval with the warning details
- **AND** no lifecycle, stock, serial, dispatch, payment, Sale, or Sales Return mutation occurs

#### Scenario: Execution rollback on failure
- **WHEN** final approval encounters an error while applying any stock, serial, dispatch, payment, Sale, or Sales Return effect
- **THEN** the system rolls back the entire final approval transaction
- **AND** the POS Return remains pending approval

### Requirement: Approval Persists Sales Return Execution Targets
The system SHALL create or validate owner/sale/location/tax-aligned linked Sales Return headers and Sale Return Details from the ready approval preview plan before applying execution effects. If linked Sales Returns are absent but the preview plan is complete, their absence MUST be treated as informational and execution MUST create the required links. If existing linked Sales Returns conflict with the latest plan, final approval MUST be blocked.

#### Scenario: Missing linked Sales Returns are created
- **WHEN** final approval runs for a pending POS Return with no linked Sales Returns and a complete derivable preview plan
- **THEN** the system creates linked Sales Return headers and details matching the preview groups and detail rows
- **AND** execution continues using those linked records

#### Scenario: Existing linked Sales Returns are inconsistent
- **WHEN** final approval finds linked Sales Return records that do not match the latest preview plan
- **THEN** the system blocks final approval with the mismatch details
- **AND** no mutation occurs

### Requirement: Cash Return Corrects Source Sale
For every cash-return POS Return line, the system SHALL receive the returned stock and serials, reduce the original customer-facing Sale detail quantity and monetary fields, reduce the active dispatched quantity, create stock mutation transaction rows for every affected stock-managed product, adjust Sale payments through active/invalidated payment state, create Sale Return Payment refund evidence, and complete the linked Sales Return. If all customer-facing Sale detail quantity and active dispatched quantity reach zero, the system SHALL mark the source Sale as returned and archive it with an audit note referencing the POS Return or Sales Return.

#### Scenario: Partial cash return modifies sale and dispatch
- **WHEN** final approval executes a cash return for part of a Sale line
- **THEN** the source Sale detail quantity and amount are reduced by the returned quantity and cash-return amount
- **AND** the source dispatch detail active dispatched quantity is reduced by the returned quantity
- **AND** stock and mutation transaction records reflect the received return

#### Scenario: Full cash return archives sale
- **WHEN** final approval executes cash returns that reduce all customer-facing Sale detail quantity and active dispatched quantity to zero
- **THEN** the source Sale is marked returned and archived
- **AND** the Sale totals, paid amount, due amount, and payment status are set to zero-settled values

#### Scenario: Cash return serial remains visible as returned
- **WHEN** final approval executes a cash return for a serial-tracked item
- **THEN** the returned serial is received back to the original source location as active stock
- **AND** the Sale document keeps the returned serial visible as returned lineage

### Requirement: Product Replacement Preserves Source Sale Commercials
For every product-replacement POS Return line, the system SHALL receive the returned stock and serials, keep the original Sale detail quantity and monetary fields unchanged, keep the original dispatch row visible, create an approved replacement dispatch on the same source Sale from the original source owner/location, reduce replacement stock, record outbound mutation transaction rows, complete the linked Sales Return, and preserve serial lineage between the returned serial and replacement serial when serial-tracked.

#### Scenario: Serial replacement dispatches replacement serial
- **WHEN** final approval executes a product replacement for a serial-tracked item
- **THEN** the original returned serial is received back to the source location
- **AND** an approved replacement dispatch is created on the source Sale for the replacement serial
- **AND** the original Sale quantity and monetary fields remain unchanged

#### Scenario: Non serial replacement dispatches same quantity
- **WHEN** final approval executes a product replacement for a non-serial item
- **THEN** the returned quantity is received back to the original source location
- **AND** the same SKU and same quantity are dispatched from the original source location
- **AND** stock mutation transaction rows are recorded for both receiving and replacement dispatch

### Requirement: Bundle Parent Return Uses Resolution-Sensitive Component Execution
The system SHALL allow POS bundle returns only through the parent bundle line. Component-only returns MUST be blocked. Cash-returning a parent bundle quantity MUST automatically include proportional parent and component reversals that mirror the original POS sale/dispatch movement, including split-owner component Sales. Product-replacing a parent bundle quantity MUST receive and dispatch only the parent product replacement; bundle components MUST remain informational context and MUST NOT create replacement movement. Missing or ambiguous component mapping MUST block cash-return execution, but MUST NOT block product-replacement execution when the parent replacement target is complete.

#### Scenario: Partial bundle cash return includes components
- **WHEN** final approval executes a cash return for part of a bundle parent quantity
- **THEN** the source Sale parent line quantity and amount are reduced proportionally
- **AND** stock is received for both the parent bundle product and every mapped component product
- **AND** mutation transaction rows are recorded for each affected stock-managed product

#### Scenario: Bundle replacement executes parent replacement only
- **WHEN** final approval executes a product replacement for a bundle parent
- **THEN** the returned parent product is received back to the original parent source location
- **AND** replacement dispatch reduces only the parent replacement stock from the original parent source location
- **AND** bundle component rows remain informational context without Sale Return Detail, dispatch, stock, Sale, or payment mutations
- **AND** the source Sale parent line money and quantity remain unchanged

#### Scenario: Component only return is blocked
- **WHEN** a POS Return attempts to execute a bundle component without its parent bundle return line
- **THEN** final approval is blocked
- **AND** no mutation occurs

### Requirement: Replacement Execution SHALL Remain Atomic And Idempotent
Serial and note-only replacement execution SHALL participate in the existing final-approval transaction and idempotency guards. A retry MUST NOT duplicate notes, serial histories, receiving, dispatch, inventory, Sales Return, or HPP effects, and a failure MUST leave no partial execution.

#### Scenario: Serial component replacement retry
- **WHEN** an approved serial component replacement is retried after successful completion
- **THEN** no duplicate serial history, stock movement, dispatch, inventory transaction, Sales Return detail, or HPP effect SHALL be created

#### Scenario: Mixed execution failure rolls back
- **WHEN** one approval contains executable bundle refund, serial replacement, or note-only replacement lines and a later line fails
- **THEN** every mutation from that approval attempt SHALL roll back
- **AND** the POS Return SHALL remain in its pre-execution state


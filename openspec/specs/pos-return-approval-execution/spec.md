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
For every product-replacement POS Return line, the system SHALL receive the returned stock and serials into the original owner's source location, keep the original Sale detail quantity and monetary fields unchanged for same-owner replacement, reduce replacement stock from the selected replacement owner's location, record outbound mutation transaction rows, complete the linked Sales Return, and preserve serial lineage between the returned serial and replacement serial when serial-tracked. A returned item SHALL enter `quantity_tax` when its original location owner has `is_pkp = true` and SHALL enter `quantity_non_tax` when that owner has `is_pkp = false`. A replacement item SHALL leave `quantity_tax` when its replacement location owner has `is_pkp = true` and SHALL leave `quantity_non_tax` when that owner has `is_pkp = false`. Each movement SHALL keep aggregate stock consistent with all condition/tax buckets. Ownership and bucket selection SHALL derive from the relevant location owner and SHALL NOT derive from POS configuration or deprecated product-level setting ownership.

#### Scenario: Serial replacement dispatches replacement serial
- **WHEN** final approval executes a serialized replacement whose original and replacement locations have the same owner
- **THEN** the returned serial is received into that owner's bucket according to the owner's `is_pkp` value
- **AND** the replacement serial is dispatched from the same owner bucket
- **AND** aggregate stock, bucket totals, global product quantity, and sellable serial count remain consistent
- **AND** the original Sale quantity and monetary fields remain unchanged

#### Scenario: Non serial replacement dispatches same quantity
- **WHEN** final approval executes a stock-managed non-serial product replacement
- **THEN** the returned quantity is received into the original owner bucket
- **AND** the same SKU and same quantity are dispatched from the replacement owner bucket
- **AND** stock mutation transaction rows record the actual owner, location, bucket, and before/after balances

#### Scenario: PKP original owner and non-PKP replacement owner
- **WHEN** the original location owner has `is_pkp = true` and the replacement location owner has `is_pkp = false`
- **THEN** returned stock increments the original owner's `quantity_tax`
- **AND** replacement dispatch decrements the replacement owner's `quantity_non_tax`
- **AND** no POS terminal configuration or product-level setting changes either bucket decision

#### Scenario: Non-PKP original owner and PKP replacement owner
- **WHEN** the original location owner has `is_pkp = false` and the replacement location owner has `is_pkp = true`
- **THEN** returned stock increments the original owner's `quantity_non_tax`
- **AND** replacement dispatch decrements the replacement owner's `quantity_tax`

#### Scenario: Replacement owner bucket is insufficient
- **WHEN** aggregate replacement stock appears sufficient but the replacement owner's required PKP or non-PKP bucket has less than the replacement quantity
- **THEN** final approval is blocked and its transaction rolls back without partial stock, serial, dispatch, Sale Return, or ledger effects

### Requirement: Bundle Parent Return Uses Resolution-Sensitive Component Execution
The system SHALL execute whole-bundle or explicit parent-only cash-return scope from persisted intent. Whole bundle MUST reverse proportional parent and original component quantities across owners; unresolved or ambiguous component mapping MUST block execution. Parent only MUST reverse the parent at its allocated value and retain component quantities and charges. Individual-component cash returns MUST remain blocked. Existing product-replacement behavior SHALL remain unchanged.

#### Scenario: Partial bundle cash return includes components
- **WHEN** final approval executes a whole-bundle cash return for part of a bundle parent quantity
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

#### Scenario: Parent-only return retains component obligations
- **WHEN** final approval executes explicitly selected parent-only cash return
- **THEN** only the parent stock, dispatch, commercial quantity, allocated value, and related settlement are corrected
- **AND** component stock, dispatch quantities, allocated charges, and cost evidence remain unchanged
- **AND** a sale with remaining component obligations is not marked fully returned or zero-settled

#### Scenario: Non-serial split-owner bundle has no direct component relation
- **WHEN** whole-bundle cash return selects a non-serial parent whose components belong to another owner's zero-quantity carrier sale detail
- **THEN** execution includes those original components using persisted checkout lineage
- **AND** every affected owner's quantities, stock buckets, commercial totals, debt, and cost evidence are corrected exactly once

### Requirement: Bundle scope execution reconciles original financial allocations
Cash-return execution SHALL use original captured parent/component allocations and applicable discounts, tax, and settled amounts. Whole-bundle reductions MUST reconcile to the selected original bundle value without duplicate component refunds. Parent-only reductions MUST exclude retained component allocations. Execution MUST remain atomic and idempotent across owner sales.

#### Scenario: Fully unpaid bundle return
- **WHEN** whole-bundle execution reverses the reported unpaid camera and microSD
- **THEN** the Rp630,000 and Rp260,000 source obligations become zero
- **AND** no cash payout is created

#### Scenario: Paid or partly paid bundle return
- **WHEN** a bundle has active cash or credit settlement
- **THEN** only the actual refundable settlement is refunded under existing settlement rules
- **AND** remaining reduction clears debt without counting component allocations twice

#### Scenario: Failure or retry
- **WHEN** one owner reversal fails or a successful execution is retried
- **THEN** failure leaves no partial reversal and retry creates no duplicate stock, payment, credit, cost, or audit effects

#### Scenario: Ambiguous original instance
- **WHEN** repeated products or bundle instances cannot be uniquely mapped from persisted source evidence
- **THEN** execution is blocked with an actionable error instead of choosing the first product match

### Requirement: Replacement Execution SHALL Remain Atomic And Idempotent
Serial and note-only replacement execution SHALL participate in the existing final-approval transaction and idempotency guards. A retry MUST NOT duplicate notes, serial histories, receiving, dispatch, inventory, Sales Return, or HPP effects, and a failure MUST leave no partial execution.

#### Scenario: Serial component replacement retry
- **WHEN** an approved serial component replacement is retried after successful completion
- **THEN** no duplicate serial history, stock movement, dispatch, inventory transaction, Sales Return detail, or HPP effect SHALL be created

#### Scenario: Mixed execution failure rolls back
- **WHEN** one approval contains executable bundle refund, serial replacement, or note-only replacement lines and a later line fails
- **THEN** every mutation from that approval attempt SHALL roll back
- **AND** the POS Return SHALL remain in its pre-execution state

### Requirement: Cash returns use cumulative rounded commercial valuation
Future POS cash-return execution SHALL value each commercial line from its persisted returnable charged amount and original quantity. It SHALL round cumulative returned value with the frozen increment using half-up rounding, cap it at the original returnable amount, and subtract previously committed return value. It SHALL NOT independently round internal owner or bundle fragments.

#### Scenario: First partial marker return
- **WHEN** 1 of 120 markers charged at Rp790000 is cash-returned with increment Rp100
- **THEN** the return amount SHALL be Rp6600 and the remaining commercial value SHALL be Rp783400

#### Scenario: Multiple requests equal one cumulative request
- **WHEN** two markers from that source line are returned through two successive requests
- **THEN** cumulative valuation SHALL be Rp13200 and the second request SHALL be Rp6600
- **AND** the cumulative amount SHALL equal a single request returning two markers

#### Scenario: Final return consumes residual
- **WHEN** all remaining eligible quantity of a commercial line is cash-returned
- **THEN** the return SHALL consume its exact remaining returnable amount, even if outside the increment
- **AND** total committed returns SHALL equal the original returnable amount without a negative residual

#### Scenario: Zero increment retains currency precision
- **WHEN** the frozen increment is zero
- **THEN** cumulative valuation SHALL use two-decimal currency precision without increment rounding

### Requirement: First committed cash return freezes rounding configuration
The system SHALL freeze the POS transaction business's rounding increment at the first successful cash-return execution for each source sale under this policy. Subsequent partial cash returns for that sale SHALL reuse it. Drafts and previews SHALL NOT reserve the setting; approval SHALL reject stale preview amounts when the effective configuration or cumulative return state changes.

#### Scenario: Configuration changes after a completed partial return
- **WHEN** a source sale first cash-returns using Rp100 and the business later changes its increment to Rp500
- **THEN** later partial cash returns for that sale SHALL continue using Rp100

#### Scenario: Configuration changes before first execution
- **WHEN** a first-return preview used Rp100 but the setting becomes Rp500 before execution
- **THEN** execution SHALL require a fresh preview and SHALL NOT commit stale monetary amounts

#### Scenario: Failed approval does not freeze configuration
- **WHEN** first-return execution fails or rolls back
- **THEN** no rounding context or cumulative allocation SHALL be committed

### Requirement: Source sale correction preserves remaining monetary authority
Cash returns SHALL subtract the committed return amount from the stored commercial value. Unaffected detail totals SHALL remain exact. Sale headers SHALL aggregate authoritative remaining values without reconstructing them from unit prices or rounding the remaining balance again. Tax, discount, owner and bundle allocations SHALL reconcile to those values without duplicate deductions.

#### Scenario: Return of another item leaves markers unchanged
- **WHEN** another product is returned from a sale containing 120 markers with persisted subtotal Rp790000 and unit price Rp6583.33
- **THEN** the marker subtotal SHALL remain Rp790000
- **AND** header recalculation SHALL NOT substitute Rp789999.60

#### Scenario: Rounded return is subtracted once
- **WHEN** a line worth Rp790000 receives a committed Rp6600 return
- **THEN** its remaining value SHALL be Rp783400 without a second increment-rounding pass

### Requirement: Monetary return evidence agrees across approval and settlement
The system SHALL use the same committed rounded commercial amount for preview, approval, linked return totals, source-sale reduction, and aggregate debt reduction plus cash refund. Retry and concurrent requests SHALL NOT allocate that value twice. Replacement-only returns SHALL retain their existing commercial behavior.

#### Scenario: Paid and unpaid portions share one correction amount
- **WHEN** a committed rounded return has both debt-reduction and refundable portions
- **THEN** their sum SHALL equal the committed return amount and all linked monetary evidence SHALL reconcile to it

#### Scenario: Concurrent or repeated approval
- **WHEN** concurrent requests target the same remaining quantity or a completed approval is retried
- **THEN** execution SHALL serialize and revalidate eligibility and cumulative amounts
- **AND** no duplicate refund or sale reduction SHALL occur

### Requirement: Return rounding applies prospectively without historical repair
The new policy SHALL apply to future cash-return executions, including executions against older sales. Deployment, reads and previews SHALL NOT rewrite historical completed returns or balances. The dedicated explicit POS return balance repair SHALL be permitted to correct proven sale header/balance drift, including for transaction 9218, while preserving completed returns and authoritative rows. Transaction 9218 SHALL NOT be used for mutating verification.

#### Scenario: Deployment preserves transaction 9218
- **WHEN** the change is deployed or transaction 9218 is read
- **THEN** its existing sale balances, completed returns, payments and checkout snapshots SHALL remain unchanged

#### Scenario: Older sale has historical returns
- **WHEN** an older sale receives a new cash return after deployment
- **THEN** historical committed quantity and amounts SHALL count against remaining eligibility and valuation
- **AND** historical evidence SHALL remain unchanged while the new allocation is bounded by the stored residual

#### Scenario: Explicit corrective repair
- **WHEN** the dedicated balance repair explicitly selects a completed transaction and proves historical header drift
- **THEN** it SHALL correct only the proven header/balance drift with audit evidence
- **AND** completed returns, payments and original checkout snapshots SHALL remain unchanged

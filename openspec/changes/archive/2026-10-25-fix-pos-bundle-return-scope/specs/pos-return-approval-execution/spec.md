# Spec Delta

## MODIFIED Requirements

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

## ADDED Requirements

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

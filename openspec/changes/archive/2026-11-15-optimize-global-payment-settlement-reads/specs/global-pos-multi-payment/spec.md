# Spec Delta

## ADDED Requirements

### Requirement: Global POS financial reads avoid full ordinary model hydration
Global POS summaries and financial filters SHALL calculate ordinary, unambiguous split-checkout settlement values without loading every candidate's full model and payment-history graph into application memory. Amounts, statuses, dates and payment eligibility SHALL remain equivalent to the existing projection, with detailed ordinary models loaded only for visible table rows.

#### Scenario: Ordinary split checkouts are aggregated
- **WHEN** qualifying ordinary checkouts include multiple owner Sales with active payments, credits or owner transfers
- **THEN** register filtering and summary values retain per-Sale rounding, clamping and recent-payment semantics
- **AND** ledger joins do not multiply amounts or transaction counts
- **AND** non-visible ordinary transactions do not require full settlement-history model hydration

#### Scenario: No financial filter is selected
- **WHEN** the table uses only business, customer, transaction-date, cashier, terminal, search or sorting constraints
- **THEN** it retains page-only detailed projection work
- **AND** it does not calculate the entire candidate set solely to display one page

### Requirement: Conservative POS optimization preserves exceptional records
Global POS read optimization SHALL retain existing resolver diagnostics and financial behavior for records that cannot be proven safe for ordinary aggregation. Such records SHALL remain subject to existing listing, filtering and payment-eligibility rules rather than being silently dropped, deduplicated, repaired or treated as valid.

#### Scenario: Mapping safety is uncertain
- **WHEN** a candidate has missing or zero Sale IDs, duplicate Sale IDs or split keys, a customer mismatch, an uncertain key comparison, or an inline mapping
- **THEN** its existing resolver determines financial values and diagnostics
- **AND** uncertainty does not result in an ordinary valid classification

#### Scenario: Completed returns require replacement discovery
- **WHEN** active completed returns are associated through checkout or transaction identity
- **THEN** replacement Sales and invalid mappings retain existing inclusion and diagnostic behavior
- **AND** reversed returns retain their existing exclusion
- **AND** archived returned zero-value Sales retain the existing collection exception

### Requirement: Global POS pagination combines all financial results exactly
Global POS financial filters SHALL apply before pagination to the complete eligible candidate set, including ordinary and exceptional transactions. Counts, pages, stable ordering and summaries SHALL retain their existing scope, with each transaction represented once.

#### Scenario: A page crosses calculation paths
- **WHEN** payment status, card selection or effective due-date filters match both ordinary and exceptional transactions
- **THEN** the page contains the same ordered transaction IDs and total as the existing projection
- **AND** page boundaries do not drop, duplicate or postpone exceptional matches

#### Scenario: Search and structured constraints compose
- **WHEN** search, immutable customer scope, businesses, date boundaries, cashier or terminal constraints are supplied
- **THEN** every result satisfies the existing intersection of constraints
- **AND** existing POS snapshot, linked-Sale, barcode and serial search semantics are retained
- **AND** summary scope is not broadened to follow table-only filters

### Requirement: Global POS staged reads use one consistent financial snapshot
Each global POS list or summary read operation SHALL use mutually consistent mapping and financial data across its stages. Later operations SHALL read current committed state, and payment submission SHALL retain fresh locked validation independently of any optimized read result.

#### Scenario: A mapping or payment changes during a read
- **WHEN** another transaction changes a mapping, return or payment between read stages
- **THEN** the current operation does not mix incompatible mapping and balance snapshots
- **AND** a later read observes the committed change

#### Scenario: Optimized reads do not authorize posting
- **WHEN** payment submission follows an optimized list or summary result
- **THEN** existing submission validation rechecks current locked eligibility and amounts
- **AND** the read operation has not modified ledgers, receipts or checkout history

## Purpose

Provide an explicit, auditable repair of proven historical POS return header drift while preserving authoritative remaining rows and completed return evidence.

## ADDED Requirements

### Requirement: Repair requires explicit scope and defaults to preview
The command SHALL require a business ID and one or more explicit transaction IDs. It SHALL default to read-only preview and require apply mode and a valid actor for mutation. It SHALL report per-transaction row evidence, return evidence, header and balance differences, expected receipt totals, and repairable, unchanged, or blocked classification.

#### Scenario: Preview selected transaction
- **WHEN** an operator previews transaction 9218 for its owning business
- **THEN** no monetary or audit records SHALL change
- **AND** output SHALL show the Rp0.40 shortage and proposed Rp30,105,000 total and Rp29,730,000 balance after Rp375,000 paid

#### Scenario: Invalid selection
- **WHEN** transaction IDs or business scope are missing, mismatched, or invalid
- **THEN** the command SHALL refuse the invalid selection without mutation

### Requirement: Repairs preserve authoritative rows and historical returns
The command SHALL repair only header and balance drift proven by captured original charges, authoritative remaining rows, completed non-reversed cash returns, and unchanged payments. It SHALL preserve row values, quantities, unit prices, tax evidence, return amounts, payment records, stock, serial history, and checkout snapshots. It SHALL block ambiguous evidence instead of repricing or independently rounding rows or owner fragments.

#### Scenario: Fully returned row remains zero
- **WHEN** a synthetic fixture reproduces the 10 returned Brother bottles split into 8 and 2 across owners
- **THEN** both remaining sale rows SHALL remain zero and the completed Rp1,200,000 return SHALL remain unchanged

#### Scenario: Proven blended-unit-price header drift
- **WHEN** a remaining marker row stores Rp790,000 for 120 units at displayed Rp6,583.33 and the header matches reconstruction as Rp789,999.60
- **THEN** the command SHALL preserve Rp790,000 and correct the proven header shortage

#### Scenario: Legitimate adjustment or ambiguous history
- **WHEN** a difference could reflect a valid discount, shipping, manual monetary edit, missing source evidence, partial-return inconsistency, or overlapping active return
- **THEN** the command SHALL report a blocking reason and SHALL NOT force totals to a rounding increment

### Requirement: Apply is atomic audited and repeatable
Apply SHALL lock and revalidate the selected transaction's complete monetary evidence before writing. It SHALL update only proven header/balance fields, preserve paid amounts, and record actor, reason, source identifiers, evidence fingerprint, and before/after values atomically. Failure SHALL roll back that transaction. A rerun SHALL make no additional monetary correction.

#### Scenario: Evidence changes or execution fails
- **WHEN** evidence changes between planning and locked application or an update/audit operation fails
- **THEN** no partial correction SHALL persist for the transaction

#### Scenario: Repeat application
- **WHEN** an already repaired transaction is selected again
- **THEN** it SHALL be reported unchanged without another corrective audit entry

### Requirement: Repaired data reconciles through current receipt projection
The repair SHALL verify that the current receipt rows, total, retained payment, and outstanding balance reconcile to the proposed sale state while preserving completed return history. It SHALL NOT hide discrepancies through display-only rounding. Legitimate decimal prices and authoritative decimal amounts SHALL retain their precision.

#### Scenario: Synthetic transaction 9218 reproduction
- **WHEN** a fixture with original Rp31,305,000 charges, a completed Rp1,200,000 return, Rp375,000 paid, and a Rp0.40 header shortage is repaired
- **THEN** the current receipt SHALL show remaining rows totaling Rp30,105,000 and Rp29,730,000 outstanding
- **AND** every remaining commercial row total in this fixture SHALL be a multiple of Rp100
- **AND** the fully returned ink SHALL be omitted from current items and preserved in return history

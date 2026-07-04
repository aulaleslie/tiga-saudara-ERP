# import-transaction-normalization Specification

## Purpose
TBD - created by archiving change normalize-import-transactions. Update Purpose after archive.
## Requirements
### Requirement: Initialization command rebuilds imported transaction ledger
The system SHALL provide an initialization-only console command that rebuilds imported purchase and sales transaction history by truncating `transactions` and recreating normalized `BUY` and `SELL` rows from imported business documents.

#### Scenario: Destructive execution requires explicit initialization write flags
- **WHEN** an operator runs the normalization command without both initialization and write confirmation flags
- **THEN** the system SHALL NOT truncate `transactions` and SHALL report the command as dry-run or blocked destructive execution.

#### Scenario: Initialization execution truncates transactions
- **WHEN** an operator runs the normalization command with initialization and write confirmation flags
- **THEN** the system SHALL truncate existing `transactions` before creating normalized import transaction rows.

#### Scenario: Command reports normalization summary
- **WHEN** the normalization command finishes
- **THEN** the system SHALL report whether transactions were truncated, how many `BUY` rows were created, how many `SELL` rows were created, and how many source rows or documents were skipped or errored.

### Requirement: Purchase imports normalize to BUY transactions
The system SHALL create one normalized `BUY` transaction for each eligible imported purchase detail without updating `product_stocks`.

#### Scenario: Imported purchase detail creates BUY movement
- **WHEN** a received imported purchase contains a purchase detail with quantity greater than zero
- **THEN** normalization SHALL create a `BUY` transaction for the detail with positive quantity, product id, purchase setting id, setting location id, and a reference to the purchase document.

#### Scenario: Purchase normalization does not mutate product stocks
- **WHEN** normalization creates `BUY` transactions
- **THEN** the system SHALL NOT create, update, increment, or decrement any `product_stocks` row.

#### Scenario: Non-import purchase excluded
- **WHEN** a purchase does not have an imported supplier purchase number
- **THEN** normalization SHALL NOT create a transaction from that purchase.

### Requirement: Sales imports normalize to SELL transactions
The system SHALL create one normalized `SELL` transaction for each eligible imported sale detail without updating `product_stocks`.

#### Scenario: Imported sale detail creates SELL movement
- **WHEN** a dispatched imported sale contains a sale detail with quantity greater than zero
- **THEN** normalization SHALL create a `SELL` transaction for the detail with negative quantity, product id, sale setting id or resolved dispatch owner setting id, target location id, and a reference to the sale document.

#### Scenario: Sales normalization does not mutate product stocks
- **WHEN** normalization creates `SELL` transactions
- **THEN** the system SHALL NOT create, update, increment, or decrement any `product_stocks` row.

#### Scenario: Non-import sale excluded
- **WHEN** a sale does not have an imported sales reference number
- **THEN** normalization SHALL NOT create a transaction from that sale.

### Requirement: Normalization calculates deterministic historical balances
The system SHALL calculate `previous_quantity`, `after_quantity`, `previous_quantity_at_location`, `after_quantity_at_location`, and `current_quantity` from a deterministic running ledger per product, setting, and location.

#### Scenario: Purchase then sale balance
- **WHEN** normalization processes a `BUY` quantity of `10` followed by a `SELL` quantity of `-3` for the same product, setting, and location
- **THEN** the `BUY` transaction SHALL have previous quantity `0` and after quantity `10`, and the `SELL` transaction SHALL have previous quantity `10` and after quantity `7`.

#### Scenario: Same date ordering is deterministic
- **WHEN** imported purchase and sale movements share the same document date
- **THEN** normalization SHALL order them by deterministic source priority, document id, and detail id so repeated runs produce the same transaction sequence and balances.

#### Scenario: Decimal quantities are preserved
- **WHEN** an imported purchase or sale detail uses a fractional quantity
- **THEN** normalization SHALL preserve the decimal quantity in the generated transaction and balance fields.

### Requirement: Imports remain runtime stock neutral
The system SHALL keep sales and purchase import runtime processing stock-neutral; imported documents SHALL NOT create transaction rows until the initialization normalization command is run.

#### Scenario: Purchase import runtime creates no transactions
- **WHEN** purchase import processing creates purchase documents and details
- **THEN** it SHALL NOT create `transactions` rows and SHALL NOT update `product_stocks`.

#### Scenario: Sales import runtime creates no transactions
- **WHEN** sales import processing creates sale documents, sale details, and dispatch details
- **THEN** it SHALL NOT create `transactions` rows and SHALL NOT update `product_stocks`.


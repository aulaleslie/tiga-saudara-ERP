## ADDED Requirements

### Requirement: POS Transaction List Client Bootstrap MUST Execute Reliably
The POS transaction list page SHALL reliably execute its client bootstrap logic across supported application layouts so that initial data loading is triggered automatically after page render.

#### Scenario: List bootstrap runs on initial page load
- **WHEN** an authorized user opens `/pos/transactions`
- **THEN** the transaction list client script MUST execute and trigger a data request without requiring manual script injection workarounds

#### Scenario: Script stack mismatch no longer blocks list loading
- **WHEN** the page is rendered through the standard application layout
- **THEN** the transaction list MUST not remain permanently in the default `Memuat data...` placeholder due to dropped page scripts

### Requirement: Transaction List MUST Resolve Into Deterministic UI States
The transaction list UI SHALL always resolve each load attempt into one explicit state: populated rows, empty state, or error state.

#### Scenario: Successful load with data
- **WHEN** the data endpoint returns one or more transactions
- **THEN** the table body MUST render transaction rows and remove the default loading placeholder

#### Scenario: Successful load with no data
- **WHEN** the data endpoint returns an empty result set
- **THEN** the table body MUST render a clear empty state message (`Tidak ada transaksi.`)

#### Scenario: Failed load
- **WHEN** the data endpoint request fails
- **THEN** the table body MUST render a visible failure message and status note indicating load failure

### Requirement: Manual Refresh MUST Retry With Active Filters
The `Muat Data` action SHALL retry list loading using currently selected filter inputs.

#### Scenario: User retries after previous failure
- **WHEN** the user clicks `Muat Data` after a failed request
- **THEN** the system MUST send a new data request and update the table based on the latest filter values

## ADDED Requirements

### Requirement: Transfer replay uses the corresponding movement timestamp
The report SHALL date outgoing TRF stock movements by dispatch time and incoming TRF movements by receipt time. If the corresponding timestamp or referenced transfer is unavailable, it SHALL use the inventory transaction creation time directly, without substituting the opposite movement timestamp. These rules SHALL apply consistently to report output and exports.

#### Scenario: Transfer crosses a reporting boundary
- **WHEN** a transfer is dispatched before a reporting boundary and received after it
- **THEN** outgoing stock is allocated to the dispatch date and incoming stock to the receipt date
- **AND** period or as-of balances reflect those separate dates

#### Scenario: Missing dispatch time
- **WHEN** an outgoing TRF transaction has no dispatch timestamp but has a receipt timestamp
- **THEN** the report uses the transaction creation time rather than the receipt time

#### Scenario: Missing receipt time
- **WHEN** an incoming TRF transaction has no receipt timestamp but has a dispatch timestamp
- **THEN** the report uses the transaction creation time rather than the dispatch time

#### Scenario: Missing transfer metadata
- **WHEN** a TRF transaction has no available referenced transfer
- **THEN** the report uses the transaction creation time

#### Scenario: Transfers load against the established schema
- **WHEN** report data or an export includes persisted transfers on the repository's migrated database schema
- **THEN** the report completes without an unknown transfer-date column error

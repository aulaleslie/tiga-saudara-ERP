## MODIFIED Requirements

### Requirement: Transfer mutations SHALL rely only on authoritative server data
Stock-transfer scan, selection, quantity update, save, submit, approval, dispatch, receipt, and return mutation boundaries SHALL reload and validate applicable location, product, conversion, stock, condition, and serial data on the server. Client-provided stock snapshots, allocation fields, maximums, tax provenance, serial state, or other protected metadata MUST NOT be trusted, including when submitted by a privileged client. When a blind serialized row contains only permitted serial identity, omitted protected provenance and stock fields MUST be treated as absent rather than as non-tax, good-condition, unavailable, or zero-stock values; the mutation boundary MUST rehydrate the current serial tuple and derive allocation from authoritative records.

#### Scenario: Blind draft saves without stock snapshot
- **WHEN** a blind editor saves valid operator intent whose Livewire state contains no stock or allocation fields
- **THEN** the system derives the authoritative allocation and persists the valid draft without requiring protected fields from the client

#### Scenario: Blind serialized draft retains authoritative taxed allocation
- **WHEN** a blind editor saves a serialized row containing only requested quantity and distinct serial identities whose current authoritative records are taxed and eligible at the origin
- **THEN** the system validates the identities, derives the taxed allocation from current server records, and persists the row without interpreting omitted provenance as non-tax or omitted stock as zero

#### Scenario: Blind serialized count does not match intent
- **WHEN** a blind serialized row's requested base quantity differs from its distinct selected serial-ID count
- **THEN** the system rejects the row with neutral corrective feedback before persistence without exposing serial provenance or stock quantities

#### Scenario: Client injects protected metadata
- **WHEN** a crafted request supplies modified stock, allocation, tax, condition-provenance, or serial-availability fields
- **THEN** the system ignores or rejects those fields, reloads authoritative data, and never persists an effect based on the injected values

#### Scenario: Stock changes after form hydration
- **WHEN** stock or serial availability changes after a blind or privileged form was loaded
- **THEN** the next authoritative mutation uses current server state and succeeds or fails atomically without trusting the earlier presentation

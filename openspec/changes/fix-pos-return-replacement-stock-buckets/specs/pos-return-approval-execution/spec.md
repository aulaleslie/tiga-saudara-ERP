# Spec Delta

## MODIFIED Requirements

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

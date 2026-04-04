## ADDED Requirements

### Requirement: POS serial search excludes serials in PENDING dispatches
The POS serial search (autocomplete) SHALL NOT return serial numbers that are referenced in any dispatch with status PENDING.

#### Scenario: Serial in PENDING dispatch excluded from search results
- **WHEN** serial `TNC202604040001` is referenced in a `DispatchDetail.serial_numbers` JSON field where the parent `Dispatch.status` is `PENDING`
- **AND** a POS operator searches for serials for the same product
- **THEN** serial `TNC202604040001` MUST NOT appear in the search results

#### Scenario: Serial in APPROVED dispatch already excluded by existing checks
- **WHEN** serial `TNC202604040001` has `dispatch_detail_id` set (from an approved dispatch)
- **AND** a POS operator searches for serials
- **THEN** serial `TNC202604040001` MUST NOT appear in the search results (existing behavior preserved)

#### Scenario: Serial in REJECTED dispatch is available
- **WHEN** serial `TNC202604040001` was in a dispatch that has been REJECTED
- **AND** the serial's `status` is still `ACTIVE` and `dispatch_detail_id` is NULL
- **THEN** serial `TNC202604040001` MUST appear in the search results

### Requirement: POS serial append rejects serials in PENDING dispatches
The POS cart serial append operation SHALL reject a serial number that is referenced in any dispatch with status PENDING, with a clear error message.

#### Scenario: Append serial that is in a PENDING dispatch
- **WHEN** a POS operator attempts to append serial `TNC202604040001` to a cart line
- **AND** serial `TNC202604040001` is referenced in a `DispatchDetail.serial_numbers` JSON field where the parent `Dispatch.status` is `PENDING`
- **THEN** the system MUST reject the append with a `DomainException`
- **AND** the error message MUST indicate the serial is in a pending dispatch (e.g., "Serial number TNC202604040001 sedang dalam proses pengiriman.")

#### Scenario: Append serial not in any pending dispatch succeeds
- **WHEN** a POS operator appends serial `TNC202604040002` to a cart line
- **AND** serial `TNC202604040002` is not in any PENDING dispatch
- **AND** serial status is `ACTIVE` and `dispatch_detail_id` is NULL
- **THEN** the append MUST succeed as normal

### Requirement: POS scan resolve rejects serials in PENDING dispatches
When a serial-tracked product is resolved via barcode scan and the scanned serial is in a PENDING dispatch, the scan resolver MUST NOT auto-add it to the cart.

#### Scenario: Scan resolve for serial in PENDING dispatch
- **WHEN** a POS operator scans barcode that resolves to serial `TNC202604040001`
- **AND** serial `TNC202604040001` is in a PENDING dispatch
- **THEN** the system MUST report the serial as unavailable
- **AND** MUST NOT add the product to the cart with that serial

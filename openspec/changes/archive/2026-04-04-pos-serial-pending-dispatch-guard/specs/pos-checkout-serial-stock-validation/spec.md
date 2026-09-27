## ADDED Requirements

### Requirement: Finalize pre-check rejects assigned serials that entered PENDING dispatch
When a serial was assigned to a POS cart line but subsequently entered a PENDING dispatch before checkout finalization, the finalize pre-check SHALL reject that serial and fail the checkout.

#### Scenario: Assigned serial enters PENDING dispatch before finalize
- **WHEN** serial `TNC202604040001` was assigned to a POS cart line while it was available
- **AND** between assignment and checkout finalization, a dispatch referencing `TNC202604040001` is created with status `PENDING`
- **THEN** finalize pre-check MUST mark that line as unfulfilled
- **AND** checkout MUST fail with `STOCK_UNAVAILABLE`
- **AND** the failure metadata MUST indicate the serial is in a pending dispatch

#### Scenario: Assigned serial with no pending dispatch passes pre-check
- **WHEN** serial `TNC202604040001` is assigned to a POS cart line
- **AND** no PENDING dispatch references that serial
- **AND** the serial's status is `ACTIVE` and `dispatch_detail_id` is NULL
- **THEN** finalize pre-check MUST treat the line as fulfilled

## Why

The stock-to-serial conversion eligibility check blocks conversion whenever any related document exists in a header-level DRAFT status (PurchaseReturn, Transfer, Sale, Adjustment, SaleReturn), even though a draft document has not committed any stock movement. This is overly conservative and blocks users unnecessarily when the only "unfinished" documents are drafts that haven't affected inventory yet.

## What Changes

- Remove header/document-level DRAFT status from the blocking checks in `SerialConversionEligibilityService` for: `PurchaseReturn` (`STATUS_DRAFT`), `Transfer` (`STATUS_DRAFT`), `Sale` (`STATUS_DRAFTED`), `Adjustment` (`'draft'`/`'DRAFT'`), and `SaleReturn` (header `status` in `'Draft'`/`'DRAFT'`).
- All other active/in-progress statuses for these document types continue to block conversion, unchanged.
- `SaleReturn.settlementItems.status == DRAFT` (a nested settlement-item sub-state, not a header status) continues to block conversion, unchanged.
- `ReceivedNote` and `ConsignmentReceiving` are unaffected — they have no draft status.

## Capabilities

### New Capabilities
(none)

### Modified Capabilities
- `existing-stock-serialization-conversion`: Eligibility check no longer treats header-level DRAFT status on PurchaseReturn, Transfer, Sale, Adjustment, and SaleReturn as a blocking condition for stock-to-serial conversion.

## Impact

- `Modules/Product/Services/SerialConversionEligibilityService.php` — five status-list edits (PurchaseReturn, Transfer, Sale, Adjustment, SaleReturn blockers).
- Existing tests asserting a DRAFT document blocks conversion need to flip to asserting it does NOT block; non-draft statuses in the same lists must still be verified as blocking (regression guard).
- No database schema, migration, or API contract changes.

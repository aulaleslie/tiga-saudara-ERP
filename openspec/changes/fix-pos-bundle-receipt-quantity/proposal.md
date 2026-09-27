# Proposal

## Why

POS bundle component quantities can be understated on receipts and transaction details when the display falls back to the transaction snapshot: a parent quantity of two can show a one-per-bundle component as `x1` instead of the dispatched total `x2`. Checkout posting already persists and dispatches multiplied quantities correctly, so the customer-facing display must report the same exact quantity without changing the receipt design.

## What Changes

- Make snapshot-based bundle composition display the total component quantity as parent line quantity multiplied by component quantity per bundle.
- Apply the same quantity rule to completed receipt fallback, receipt reprints, draft/loaded receipts, and POS transaction bundle details.
- Keep persisted Sales/dispatch composition authoritative when it is available, avoiding any second multiplication of quantities already stored as totals.
- Preserve the current receipt markup, styling, prices, serial display, checkout posting, and inventory behavior.
- Add focused regression coverage for one-per-bundle and multi-per-bundle component quantities; do not require a full test-suite run.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-receipt`: Bundle components on completed receipts and reprints display the exact total quantity sold/dispatched, including snapshot fallback reconstruction.
- `pos-draft-receipt`: Bundle components on draft and loaded-transaction receipts display parent-scaled total quantities.
- `pos-transaction-detail-bundle-display`: Transaction details display parent-scaled component quantities when bundle composition comes from snapshot fallback data.

## Impact

- Affected code: `Modules/Pos/Services/PosReceiptService.php` and focused POS receipt/service tests.
- No database migration, API contract change, dependency change, inventory mutation, or receipt layout redesign.
- Verification is limited to focused tests covering bundle quantity reconstruction and existing persisted-composition behavior.

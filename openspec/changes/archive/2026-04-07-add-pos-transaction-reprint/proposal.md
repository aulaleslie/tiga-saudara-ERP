## Why

POS users currently lack visibility into print history and cannot reprint transaction receipts from the transaction management interface. Print logging infrastructure exists for checkouts but is not extended to draft transactions, creating an incomplete audit trail and limiting user access to reprinting capabilities.

## What Changes

- Extend print logging infrastructure to track prints of both completed checkouts and draft transactions
- Add reprint endpoint and functionality for draft transactions (`/pos/transactions/{transaction}/receipt/reprint`)
- Add reprint buttons to transaction list and transaction detail views
- Display print history on receipt templates with count and last printer information
- Print history remains visible when printing receipts for audit purposes

## Capabilities

### New Capabilities
- `pos-transaction-reprint`: Ability to reprint draft transaction receipts with audit logging (user, timestamp)
- `receipt-print-history`: Display print history on receipts showing count and who last printed

### Modified Capabilities
- `pos-receipt-logging`: Extend to handle both POS checkouts and draft transactions with nullable checkout references

## Impact

- Database: Extend `pos_receipt_print_logs` to support nullable `pos_checkout_id` for draft transactions
- Controllers: Add reprint method to `PosTransactionController`
- Views: Update `receipt.blade.php` to display print history; add reprint buttons to transaction list and detail views
- Routes: Add new reprint route for transactions
- Permissions: Reuse existing `pos.receipts.reprint` permission
- No breaking changes to existing APIs

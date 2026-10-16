# Proposal

## Why

POS receipts can show original quantities and serials after a return, while dedicated reprints update amounts using a different data path. Customers need one coherent current purchase receipt, with completed returns explaining quantity, serial, payment, and balance changes.

## What Changes

- Use one current-state receipt builder for completed checkout Print, transaction Print/Reprint, checkout Reprint, and global-payment Reprint; preserve permissions, audit types, and explicit historical receipt access.
- Reduce main quantities and totals for completed cash returns; omit fully returned items from main items and retain them in return details.
- Preserve quantities and customer-facing value for replacements; replace serialized main-item identities with the replacement serials and retain original-to-replacement history below.
- Show all completed, non-reversed returns with references, dates, items, quantities, resolutions, and refund or serial replacement details.
- Present current total, retained paid amount, and debt. Returns reduce debt first; only the excess is a cash refund, explained in the return section.
- Verify with focused receipt and return scenarios only; no full-suite test gate.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-receipt`: Current completed receipt projection, remaining quantities, replacement serials, return history, and debt-first payment presentation.
- `pos-professional-receipt`: Bundle presentation and totals reflect the current purchase while historical receipts retain checkout facts.

## Impact

Primarily `Modules/Pos/Services/PosReceiptService.php`, `PosReceiptReprintProjectionService.php`, settlement/sale-resolution collaborators, the receipt Blade view, and receipt actions in `PosSellController`, `PosTransactionController`, and `GlobalPosPaymentController`. Focused tests extend existing receipt/reprint fixtures with completed return cases. Reuse persisted return execution and serial lineage; avoid new dependencies, checkout snapshot rewrites, or print-triggered financial mutations. Implementation must check the existing return/payment projection against debt-first examples and identify any necessary narrowly scoped correction rather than fabricating refund evidence in the receipt.

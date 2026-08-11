## Why

POS service products that do not manage inventory can be incorrectly blocked at checkout after their draft transaction is loaded back into the cart. The draft snapshot loses the product's stock-management classification, causing preflight validation to treat the service as an out-of-stock inventory item.

## What Changes

- Preserve each POS cart line's stock-management classification when a draft transaction is saved and restore it when the draft is loaded.
- Retain safe behavior for historical drafts that have no stored classification by resolving the current product classification during hydration.
- Ensure restored non-stock products bypass stock availability validation while stock-managed products continue through the existing validation path.
- Add regression coverage for the draft save, load, and checkout-preflight lifecycle.

## Capabilities

### New Capabilities

- `pos-draft-stock-management-preservation`: Preserves and safely restores POS line stock-management behavior across draft persistence and reloading.

### Modified Capabilities

- `pos-checkout-preflight-validation`: Restored non-stock POS lines must not be reported as stock shortages during checkout preflight.

## Impact

- Affected code: `Modules/Pos/Services/PosTransactionSnapshotMapper.php`, POS cart hydration, and POS checkout preflight coverage.
- Affected behavior: POS draft save/load and payment-stage entry for service/non-stock products.
- No API, database schema, or dependency changes are expected.

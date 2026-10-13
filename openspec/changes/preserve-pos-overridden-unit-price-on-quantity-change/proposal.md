# Proposal

## Why

After a cashier overrides a POS row total, changing quantity restores the customer tier or conversion price. This discards the unit price the cashier just established and makes the next total unexpected.

## What Changes

- Keep a successful row-total edit exact at the quantity where it was entered. On a later quantity change, use its displayed two-decimal effective unit price as the new authoritative unit price and calculate the new row total from that price.
- Continue keeping an explicit unit-price override authoritative across quantity changes.
- Bypass automatic conversion packing, tier repricing, and automatic row-total rounding for overridden rows during quantity changes. Continue automatic repricing for rows without overrides and continue repricing all rows when the customer changes.
- Refresh canonical monetary metadata and pricing provenance so cart, draft, checkout, receipt, and posting agree.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-line-total-override`: Change the quantity-change rule from restoring standard pricing to carrying the derived unit price forward; keep the originally entered total exact until quantity changes.
- `pos-conversion-packing-pricing`: Clarify that automatic quantity repricing applies only while the row has no active manual override.
- `pos-line-unit-price-override`: Explicitly preserve the overridden unit price on quantity changes for packed and tier-priced rows.

## Impact

- `Modules/Pos/Services/PosCartService.php` quantity and row-override lifecycle; possibly `PosRowOverrideArithmetic` and `PosCartTotalsCalculator` to preserve minor-unit precision and source metadata.
- Focused POS pricing and override feature tests, including the conversion-unit → tier-customer → row-total edit → quantity-change sequence.
- No API shape, database schema, or completed-transaction migration is expected.

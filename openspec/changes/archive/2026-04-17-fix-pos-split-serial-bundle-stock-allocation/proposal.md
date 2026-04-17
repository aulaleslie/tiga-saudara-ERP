## Why

POS checkout can fail with `STOCK_UNAVAILABLE` for a bundled, serial-tracked parent product even when the parent stock, assigned serials, and bundle child stock are available. The failure appears when split posting is enabled because the split planner drops the serial parent allocation before the inline posting adapter records stock movement.

## What Changes

- Preserve serial-tracked parent stock allocations through split checkout planning so posting receives a usable allocation for each grouped line.
- Partition bundle child stock allocations by split group quantity instead of copying the full child allocation into every group.
- Add regression coverage for serial-tracked bundle checkout with split posting enabled.
- Add regression coverage for multi-source serial parent allocations where bundle child stock must be decremented exactly once per sold parent unit.
- Keep checkout API behavior and response shape unchanged; this change only corrects allocation planning and posting behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-checkout-split-posting`: Split posting must preserve serial parent allocations and must not duplicate bundle child allocations across groups.
- `pos-bundle-selection-checkout`: Bundle child stock deduction during POS checkout must remain proportional to the sold bundle quantity when checkout is split by source location or tax bucket.
- `pos-checkout-serial-stock-validation`: Serial-tracked checkout lines with valid assigned serials must retain their validated allocation through final posting.
- `pos-stock-posting-bucket-alignment`: Stock movement must continue to decrement the source location and tax/non-tax bucket selected by the resolver after split planning.

## Impact

- Affected services:
  - `Modules/Pos/Services/PosCheckoutSplitPlannerService.php`
  - `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`
  - possibly `Modules/Pos/Services/ResolvePosStockAllocationsService.php` if allocation payloads need additional metadata
- Affected tests:
  - POS split posting regression tests
  - POS bundle checkout regression tests
  - POS serial checkout regression tests
- No database schema changes are expected.
- No frontend API contract changes are expected.

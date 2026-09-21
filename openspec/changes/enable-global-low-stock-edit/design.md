# Design

## Context

The normal product edit screen uses a shared Livewire unit-configuration component. Its `locked` state is derived from positive stock existing anywhere and currently disables the low-stock threshold together with structural controls. The update request already validates `product_stock_alert`, and the controller already includes it in the global `Product` update while separating price fields into the active business's `product_prices` row.

The threshold is consumed globally by stock notifications and inventory reports. No per-setting threshold model exists or is needed.

## Goals / Non-Goals

**Goals:**

- Decouple threshold editability from the existing-stock structural lock.
- Retain the stock-management prerequisite for the threshold input.
- Preserve the existing persistence split between global product attributes and setting-scoped prices.
- Make the threshold's shared scope visible to users.

**Non-Goals:**

- Changing stock quantities, notification evaluation, or report calculations.
- Moving the threshold to a per-business or per-location table.
- Relaxing locks on stock management, serial tracking, units, barcodes, or conversions.
- Changing product permissions or introducing a threshold-specific permission.
- Running or requiring the full automated test suite.

## Decisions

### Decouple only the threshold input from the structural lock

The threshold input will be disabled only when stock management is disabled. The component's existing `locked` flag will continue protecting inventory-structural controls.

This is preferred over removing or weakening the shared lock because units, serial policy, and conversions can invalidate existing inventory, while the threshold is only an evaluation boundary.

### Continue persisting the threshold on the shared product row

The existing request/controller path will remain authoritative: `product_stock_alert` is validated as a non-negative integer and saved with global product fields. Current-setting resolution remains limited to pricing and other explicitly setting-scoped records.

Adding threshold rows to `product_prices` or `product_stocks` was rejected because the requested behavior is global and existing notifications and reports already consume the product-level value.

### Explain global scope adjacent to the field

The edit UI will state that the threshold applies to all businesses and locations. This prevents the surrounding setting-scoped price controls from implying that the threshold follows the active business.

### Use focused regression verification

Focused Product module tests will cover field editability for a stocked product, persistence across active-business context, validation, structural-lock preservation, and price-scope preservation as needed. Verification will use the narrowest relevant test file or filter; a full-suite run is deliberately outside scope because the change is localized and does not alter schema or shared infrastructure.

## Risks / Trade-offs

- **A user editing from one business may not expect other businesses to observe the change** → Add explicit global-scope help text beside the threshold.
- **Relaxing the wrong lock could expose structural controls** → Change only the threshold's disabled condition and assert structural controls remain locked.
- **A form regression could omit the threshold from submission** → Add a focused request-level persistence test for a product with existing stock.

## Migration Plan

No data migration is required. Deploy the UI change and focused regression coverage together. Rollback restores the prior disabled condition without requiring data rollback because all saved values use the existing product column and validation contract.

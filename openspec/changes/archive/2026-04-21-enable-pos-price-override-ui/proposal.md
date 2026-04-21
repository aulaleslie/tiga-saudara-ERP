## Why

The POS backend fully supports price overrides with approval governance (`PRICE_OVERRIDE` action type, `pos.overrides.price` permission, `overrideLinePrice()` service, API route), but the sell UI renders the price cell as read-only text. Users cannot trigger a price change from the interface, making the entire backend capability inaccessible.

## What Changes

- Add a price-edit button to each cart line row that opens a "Ubah Harga" modal showing old price and target price input.
- Wire the modal submission through `ApprovalManager.wrapAction()` using the existing `PRICE_OVERRIDE` action type, matching the pattern used by line remove, cart clear, and qty reduce.
- Render PRICE_OVERRIDE approval state (Pending / Approved) on the price cell, reusing the same approval-state rendering pattern as LINE_REMOVE on the delete button.
- Allow price >= 0 (users can intentionally set price to 0) by relaxing backend validation from `gt:0` to `gte:0`.
- Enrich the cart snapshot builder to expose `requested_unit_price` from PRICE_OVERRIDE approval payloads so the UI can display the approved target price.

## Capabilities

### New Capabilities

- `pos-price-override-ui`: Frontend UI for POS price editing — modal dialog, cart row edit trigger, approval-state rendering, and event handlers that wire into the existing `ApprovalManager.wrapAction()` pattern and backend `PRICE_OVERRIDE` endpoint.

### Modified Capabilities

- `pos-supervised-cart-actions`: Relax price validation to accept zero-value prices (>= 0 instead of > 0) and enrich the snapshot builder to include `requested_unit_price` from PRICE_OVERRIDE approval payloads.

## Impact

- **Backend**: `StorePosCartPriceOverrideRequest` validation rule, `PosCartService::overrideLinePrice()` validation guards, `PosCartService::buildSnapshot()` payload extraction.
- **Frontend**: `sell.blade.php` — `buildLineRow()` price cell markup, new click handler for `js-price-edit`, new modal partial `sell/modals/price_override.blade.php`, modal submit wiring.
- **Permissions**: No new permissions needed — reuses existing `pos.overrides.price` and `direct_permissions.price_override` capability flag.
- **Database**: No schema changes.

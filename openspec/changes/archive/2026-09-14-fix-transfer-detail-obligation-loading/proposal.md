## Why

The stock-transfer detail page accesses each legacy line-level return obligation without eager loading it, so environments with lazy loading disabled fail before rendering. The access also occurs outside the stock-visibility permission block, causing blind viewers to traverse protected obligation relationships they must never receive.

## What Changes

- Make transfer-detail relationship loading explicit and compatible with disabled lazy loading.
- Load legacy line-level return obligations and version-2 movement obligations only for viewers authorized by `stockTransfers.view-system-stock`.
- Keep product identity and permitted lifecycle metadata available to blind viewers without traversing protected obligation relationships.
- Move obligation-dependent Blade evaluation inside the stock-visibility boundary.
- Add focused privileged, blind, and lazy-loading-disabled HTTP verification.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `stock-transfer-system-stock-visibility`: Strengthen transfer-detail projection behavior so protected obligation relationships are permission-gated, explicitly loaded, and never lazily accessed.

## Impact

- Affects `TransferStockController::show`, the stock-transfer detail Blade view, and focused detail-visibility tests.
- No database migration, workflow transition, inventory mutation, permission change, or public route change is required.
- Existing privileged obligation presentation remains available; blind presentation remains product-identity and lifecycle only.

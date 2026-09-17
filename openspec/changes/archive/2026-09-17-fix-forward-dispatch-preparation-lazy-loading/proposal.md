## Why

Opening a newly created workflow version 2 forward-dispatch draft can fail while rendering the preparation page because its movement lines lack a loaded `product` relation and Eloquent lazy loading is disabled. Dispatchers cannot begin the physical count for affected transfers.

## What Changes

- Ensure the HTML preparation page receives the product and serial relations it renders for both new and resumed drafts.
- Load requested transfer products only when the user's stock-visibility permission allows the page to show requested quantities.
- Add focused verification of successful HTML rendering with lazy loading disabled, covering a new draft and a resumed draft.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `stock-transfer-forward-dispatch`: Clarify that an authorized dispatcher can render and use the preparation page for both newly created and resumed drafts without a lazy-loading failure.

## Impact

- Affects the forward-dispatch preparation controller and its HTML view data loading in `Modules/Adjustment`.
- Adds a focused feature test for the preparation route.
- Does not change routes, database schema, permissions, or the JSON projection contract.

## Why

Sales and POS already preserve captured bundle data when a definition changes, but POS draft integrity currently hashes only the bundle identifier and can miss mutations to component identity, quantity, price allocation, and operational metadata. Drift handling also differs between Sales and POS, allowing some definition changes to pass without a warning even though the transaction continues from its older snapshot.

## What Changes

- Define a canonical first-level bundle snapshot contract for Sales carts, POS carts, persisted drafts, checkout snapshots, and retries.
- Protect all authoritative POS bundle snapshot fields with deterministic integrity hashing and reject corrupted or incomplete persisted drafts.
- Normalize captured component quantity semantics so Sales and POS can identify quantity drift consistently.
- Warn when an administrator save changes a bundle component's saved informational-price allocation, while retaining the transaction's captured prices and commercial amount.
- Re-evaluate current `stock_managed` and serial-required product classifications at operational gates for safety without refreshing captured composition, quantities, or prices.
- Preserve request-scoped lifecycle acknowledgement, checkout finalization revalidation, and historical idempotent replay behavior.
- Verify every reachable Sales create/update path applies the same captured-bundle drift contract.
- Explicitly defer POS serial entry for bundle components to the later component-serial exploration; this change does not add component-level serial UI or assignment APIs.

## Capabilities

### New Capabilities

- `product-bundle-cart-snapshot-integrity`: Defines canonical captured bundle data, persisted-draft integrity protection, drift comparison, and authoritative snapshot behavior across Sales and POS.

### Modified Capabilities

- `product-bundle-runtime-lifecycle`: Extends captured-bundle warnings to administrator-refreshed informational allocations and clarifies that current operational classifications remain blocking safety inputs without repricing or rebuilding captured bundles.

## Impact

- Affects POS cart construction, persisted transaction mapping and hashing, draft loading, preflight/finalize validation, stock resolution, and split-posting preparation under `Modules/Pos`.
- Affects bundle lifecycle comparison and warning reasons under `Modules/Product`.
- Affects Sales cart hydration and reachable create/update validation paths in `app/Livewire/Sale` and `Modules/Sale`.
- Adds focused regression coverage for mutation detection, definition drift, operational metadata changes, payment-stage races, and posted idempotent replay.
- No schema redesign, historical transaction rewrite, pricing refresh, or bundle-component serial-entry UI is included.

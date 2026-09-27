## Why

Three lifecycle bugs in the POS bundle flow undermine the recently introduced `bundle_sale_price` model: (1) the bundle selection modal still shows the legacy add-on price while the cart charges the new sale price, (2) "Simpan dan Buka Baru" silently strips bundle metadata so reloaded drafts lose their bundle identity, and (3) the transactions list lets users cancel a transaction that is currently loaded into a cart elsewhere, contradicting the load-concurrency guarantees.

## What Changes

- Bundle selection modal endpoint (`/pos/sell/products/{product}/bundles`) returns `bundle_sale_price` as the authoritative `price` shown to the cashier; the legacy add-on price is preserved in a separate `legacy_price` field for backward compatibility.
- Draft transaction persistence stores bundle metadata (`bundle_id`, `bundle_name`, `bundle_price`, `bundle_items`) in the existing `line_meta` JSON column when "Simpan dan Buka Baru" runs, so reloaded drafts faithfully restore the bundle pill, bundle-detail modal, and bundle-aware merge keys.
- Reloaded draft cart lines hydrate `bundle_items` from the saved snapshot rather than re-resolving live from `product_bundles` — drafts remain faithful to what the cashier originally selected even if the bundle definition changes later.
- The deterministic snapshot hash (`PosTransactionSnapshotMapper::buildSnapshotHash`) includes `bundle_id` so tampering or drift in the persisted bundle reference is detected on load, alongside existing `tax_id` and `conversion_id` fields.
- The transactions list hides "Batalkan" for transactions in `LOADED` status and the `PosTransactionService::cancel()` allow list is tightened to `DRAFT` only — a transaction currently held in a cart cannot be cancelled out from under that cart from any path.

## Capabilities

### New Capabilities
<!-- None — this change updates existing capabilities only. -->

### Modified Capabilities
- `pos-bundle-selection-checkout`: bundle selection payload exposed to the modal SHALL surface `bundle_sale_price` as the displayed price and preserve the legacy add-on price as `legacy_price`.
- `pos-sell-save-new`: persisted draft lines SHALL retain bundle metadata so reloaded drafts restore the bundle identity, composition, and merge key as originally captured.
- `pos-transaction-cancel-authorization`: cancellation SHALL be restricted to transactions in `DRAFT` status; transactions in `LOADED` status SHALL NOT be cancellable from any UI or API path, ensuring the load-concurrency guarantee.

## Impact

- Code:
  - `Modules/Pos/Http/Controllers/PosSellController.php` — `productBundles()` payload shape.
  - `Modules/Pos/Services/PosTransactionSnapshotMapper.php` — `persistLines()`, `hydrateCart()`, and `buildSnapshotHash()`.
  - `Modules/Pos/Services/PosTransactionService.php` — `cancel()` allow list.
  - `Modules/Pos/Resources/views/transactions/index.blade.php` — `canCancelRow()` predicate.
- API surface: `productBundles` response gains `legacy_price`; `cancel` endpoint rejects `LOADED` transactions with `TRANSACTION_NOT_CANCELLABLE`.
- Data: no migrations. `line_meta` JSON column gains additional optional keys (`bundle_id`, `bundle_name`, `bundle_price`, `bundle_items`).
- Tests: existing POS bundle and transaction tests under `Modules/Pos/Tests/Feature/` will need updates around the modal price field, save-and-new round-trip, and cancel allow list.
- No breaking change to the cart line shape consumed by the frontend; bundle-aware fields were already part of the in-memory snapshot and only the persistence/hydration round-trip is being completed.
